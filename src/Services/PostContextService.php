<?php
// src/Services/PostContextService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;

/**
 * Contesto del post (licenza Pro, feature `post_context`; interruttore
 * `post_context_enabled` nelle Impostazioni).
 *
 * Al primo commento su un post si legge il post da Facebook (testo, link condiviso,
 * immagine) e Haiku ne scrive un breve riassunto, salvato in `post_contexts`. Ogni
 * commento dello stesso post riceve quel riassunto nel contesto inviato all'AI.
 *
 * - Una sola generazione per post anche con molti commenti simultanei: chi inserisce
 *   la riga 'pending' genera, gli altri aspettano al massimo qualche secondo e poi
 *   proseguono senza contesto.
 * - Se il post viene modificato (cambia testo o link) il riassunto si rigenera; il
 *   controllo su Facebook avviene al massimo una volta ogni RECHECK_MINUTES.
 * - Mai bloccante: qualunque errore → null, e la moderazione procede come prima.
 */
class PostContextService
{
    private const WAIT_SECONDS     = 5;   // attesa massima se un altro processo sta generando
    private const PENDING_STALE    = 90;  // secondi dopo cui un 'pending' si considera abbandonato
    private const FAILED_RETRY_MIN = 15;  // minuti prima di ritentare un post fallito
    private const RECHECK_MINUTES  = 60;  // ogni quanto verificare se il post è stato modificato

    private Client $http;

    public function __construct(
        private readonly ClaudeService    $claude,
        private readonly MetaGraphService $meta,
        private readonly LicenseService   $license,
        private ?Logger                   $logger = null,
    ) {
        $this->http = new Client(['timeout' => 8, 'connect_timeout' => 4]);
    }

    /** Licenza `post_context` + interruttore attivo nelle Impostazioni. */
    public function isEnabled(): bool
    {
        if (!$this->license->hasFeature('post_context')) return false;
        try {
            $v = DB::table('app_settings')->where('key', 'post_context_enabled')->value('value');
        } catch (\Throwable) {
            return false;
        }
        return $v === null || $v === '1';
    }

    /**
     * Riassunto del post per il commento in moderazione, o null se non disponibile.
     *
     * @param array<string,mixed> $page Riga di connected_pages (serve id + page_access_token)
     * @param string $platformPostId ID del post Facebook (dal webhook)
     */
    public function forPost(array $page, string $platformPostId): ?string
    {
        if ($platformPostId === '' || empty($page['page_access_token']) || !$this->isEnabled()) {
            return null;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $row = DB::table('post_contexts')->where('platform_post_id', $platformPostId)->first();

            if (!$row) {
                $inserted = DB::table('post_contexts')->insertOrIgnore([
                    'page_id'          => (int) $page['id'],
                    'platform_post_id' => $platformPostId,
                    'status'           => 'pending',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
                if ($inserted === 1) {
                    return $this->generate($page, $platformPostId);
                }
                return $this->waitForReady($platformPostId);
            }

            if ($row->status === 'ready') {
                $this->recheckIfDue($page, $row);
                return DB::table('post_contexts')->where('id', $row->id)->value('summary') ?: null;
            }

            // pending abbandonato o failed da ritentare: lo prende chi riesce ad aggiornarlo per primo
            $staleBefore = date('Y-m-d H:i:s', time() - ($row->status === 'failed' ? self::FAILED_RETRY_MIN * 60 : self::PENDING_STALE));
            $claimed = DB::table('post_contexts')
                ->where('id', $row->id)
                ->where('status', $row->status)
                ->where('updated_at', '<', $staleBefore)
                ->update(['status' => 'pending', 'updated_at' => $now]);
            if ($claimed === 1) {
                return $this->generate($page, $platformPostId);
            }
            return $row->status === 'pending' ? $this->waitForReady($platformPostId) : null;

        } catch (\Throwable $e) {
            $this->logger?->warning("Post context {$platformPostId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Legge il post da Facebook, chiede il riassunto a Haiku e lo salva.
     *
     * @param array<string,mixed>      $page
     * @param array<string,mixed>|null $post Post già letto da Facebook (altrimenti lo legge qui)
     */
    private function generate(array $page, string $platformPostId, ?array $post = null): ?string
    {
        $post ??= $this->meta->getPost($platformPostId, $page['page_access_token']) ?? [];
        if (empty($post['id'])) {
            $this->fail($platformPostId, 'Post non leggibile da Facebook');
            return null;
        }

        [$text, $link, $image] = $this->extract($post);
        if ($text === '' && $link === [] && $image === null) {
            $this->fail($platformPostId, 'Post senza contenuto utilizzabile');
            return null;
        }

        $article = !empty($link['url']) ? $this->fetchArticleText((string) $link['url']) : '';
        $summary = $this->claude->summarizePost($text, $link, $article, $image);
        if ($summary === null) {
            $this->fail($platformPostId, 'Riassunto AI non riuscito');
            return null;
        }

        $now = date('Y-m-d H:i:s');
        DB::table('post_contexts')->where('platform_post_id', $platformPostId)->update([
            'status'      => 'ready',
            'summary'     => $summary,
            'source_hash' => $this->sourceHash($text, $link),
            'model'       => $this->claude->postContextModel(),
            'error'       => null,
            'checked_at'  => $now,
            'updated_at'  => $now,
        ]);
        return $summary;
    }

    /**
     * Se è ora, controlla su Facebook se il post è cambiato e nel caso rigenera il riassunto.
     *
     * @param array<string,mixed> $page
     */
    private function recheckIfDue(array $page, object $row): void
    {
        $dueBefore = date('Y-m-d H:i:s', time() - self::RECHECK_MINUTES * 60);
        if ($row->checked_at !== null && $row->checked_at >= $dueBefore) return;

        // Segna subito il controllo, così i commenti simultanei non lo ripetono.
        $claimed = DB::table('post_contexts')
            ->where('id', $row->id)
            ->where(fn($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', $dueBefore))
            ->update(['checked_at' => date('Y-m-d H:i:s')]);
        if ($claimed !== 1) return;

        $post = $this->meta->getPost($row->platform_post_id, $page['page_access_token']);
        if (!$post) return;
        [$text, $link] = $this->extract($post);
        if ($this->sourceHash($text, $link) !== $row->source_hash) {
            $this->generate($page, $row->platform_post_id, $post);
        }
    }

    /**
     * @param  array<string,mixed> $post
     * @return array{0:string,1:array<string,string>,2:?string} [testo, link, immagine]
     */
    private function extract(array $post): array
    {
        $text = trim((string) ($post['message'] ?? $post['story'] ?? ''));
        $link = [];
        foreach (($post['attachments']['data'] ?? []) as $a) {
            $url = (string) ($a['unshimmed_url'] ?? $a['url'] ?? '');
            $isLink = in_array($a['type'] ?? '', ['share', 'link', 'native_templates'], true)
                || (($a['media_type'] ?? '') === 'link');
            if ($isLink || !empty($a['title'])) {
                $link = array_filter([
                    'title'       => trim((string) ($a['title'] ?? '')),
                    'description' => trim((string) ($a['description'] ?? '')),
                    'url'         => $isLink ? $url : '',
                ]);
                break;
            }
        }
        $image = !empty($post['full_picture']) ? (string) $post['full_picture'] : null;
        return [$text, $link, $image];
    }

    /** @param array<string,string> $link */
    private function sourceHash(string $text, array $link): string
    {
        return hash('sha256', $text . "\n" . ($link['url'] ?? '') . "\n" . ($link['title'] ?? ''));
    }

    private function waitForReady(string $platformPostId): ?string
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            usleep(500_000);
            $row = DB::table('post_contexts')->where('platform_post_id', $platformPostId)->first(['status', 'summary']);
            if (!$row || $row->status === 'failed') return null;
            if ($row->status === 'ready') return $row->summary ?: null;
        }
        return null;
    }

    private function fail(string $platformPostId, string $error): void
    {
        DB::table('post_contexts')->where('platform_post_id', $platformPostId)->update([
            'status'     => 'failed',
            'error'      => $error,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logger?->info("Post context {$platformPostId}: {$error}");
    }

    /**
     * Testo della pagina linkata dal post. Solo http(s) verso indirizzi pubblici
     * (anche dopo ogni redirect): il server non deve poter essere usato per
     * raggiungere servizi interni. Stringa vuota se non leggibile.
     */
    private function fetchArticleText(string $url): string
    {
        if (!$this->isPublicHttpUrl($url)) return '';
        try {
            $resp = $this->http->get($url, [
                'http_errors'     => false,
                'allow_redirects' => [
                    'max'         => 3,
                    'protocols'   => ['http', 'https'],
                    'on_redirect' => function ($req, $res, $uri) {
                        if (!$this->isPublicHttpUrl((string) $uri)) {
                            throw new \RuntimeException('Redirect verso indirizzo non pubblico');
                        }
                    },
                ],
                'headers' => ['User-Agent' => 'ModerationHub/1.0 (+post context)'],
            ]);
            if ($resp->getStatusCode() >= 400) return '';
            $ctype = $resp->getHeaderLine('Content-Type');
            if ($ctype !== '' && stripos($ctype, 'html') === false) return '';
            $html = mb_substr((string) $resp->getBody(), 0, 1_500_000);
            $html = preg_replace('#<(script|style|noscript|nav|footer|header|aside)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
            return mb_substr(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))) ?? ''), 0, 6000);
        } catch (\Throwable) {
            return '';
        }
    }

    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = trim($parts['host'], '[]');
        $ips  = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) return false;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}

<?php
// src/Controllers/BrandingController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Icona personalizzata dell'app (PWA).
 *
 * Le icone caricate dall'admin vivono in storage/branding/ (fuori dalla docroot e
 * ignorata da git): un `git pull` di deploy non le tocca mai. Se non esiste
 * un'icona personalizzata si serve quella predefinita in public/assets/icons/.
 *
 * Il ritaglio/ridimensionamento avviene nel browser (canvas): il server riceve già
 * PNG alle misure esatte e li valida per intestazione e dimensioni, senza dipendere
 * da GD o da altre estensioni. I nomi dei file sono fissi: nessun nome scelto
 * dall'utente arriva al filesystem.
 */
class BrandingController
{
    /** nome pubblico → [larghezza, altezza, file predefinito, campo del form] */
    private const ICONS = [
        '192'      => [192, 192, 'icon-192.png',           'icon_192'],
        '512'      => [512, 512, 'icon-512.png',           'icon_512'],
        'maskable' => [512, 512, 'icon-maskable-512.png',  'icon_maskable'],
        'apple'    => [180, 180, 'apple-touch-icon.png',   'icon_apple'],
    ];

    private const MAX_BYTES = 1_500_000; // per singolo PNG

    private readonly string $storageDir;
    private readonly string $defaultsDir;

    public function __construct(?string $storageDir = null, ?string $defaultsDir = null)
    {
        $this->storageDir  = $storageDir  ?? dirname(__DIR__, 2) . '/storage/branding';
        $this->defaultsDir = $defaultsDir ?? dirname(__DIR__, 2) . '/public/assets/icons';
    }

    // ── GET /pwa/icon/{name}  (pubblica: la chiedono browser e store) ──
    /** @param array<string,string> $args */
    public function icon(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $key = preg_replace('/\.png$/', '', (string) ($args['name'] ?? ''));
        if (!isset(self::ICONS[$key])) {
            return $response->withStatus(404);
        }

        $custom = $this->customPath($key);
        $path   = is_file($custom) ? $custom : $this->defaultsDir . '/' . self::ICONS[$key][2];
        if (!is_file($path)) {
            return $response->withStatus(404);
        }

        // ETag = contenuto effettivo: l'icona nuova viene vista subito, ma senza riscaricarla se invariata.
        $etag = '"' . md5($path . '|' . filesize($path) . '|' . filemtime($path)) . '"';
        $response = $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('ETag', $etag);

        if (trim($request->getHeaderLine('If-None-Match')) === $etag) {
            return $response->withStatus(304);
        }

        $response->getBody()->write((string) file_get_contents($path));
        return $response;
    }

    // ── GET /api/branding ───────────────────────────────────────────
    public function status(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $custom  = $this->hasCustom();
        $updated = null;
        if ($custom) {
            $updated = date('c', (int) filemtime($this->customPath('512')));
        }
        return $this->json($response, ['custom' => $custom, 'updated_at' => $updated]);
    }

    // ── POST /api/branding/icon  (admin) ────────────────────────────
    /**
     * Multipart con i quattro PNG già pronti: icon_192, icon_512, icon_maskable, icon_apple.
     * Tutto o niente: se uno solo non è valido non viene salvato nulla.
     */
    public function upload(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        $files = $request->getUploadedFiles();
        $png   = [];
        foreach (self::ICONS as $key => [$w, $h, , $field]) {
            $file = $files[$field] ?? null;
            if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
                return $this->json($response, ['error' => "File mancante o non caricato correttamente: {$field}"], 422);
            }
            if (($file->getSize() ?? 0) > self::MAX_BYTES) {
                return $this->json($response, ['error' => "Immagine troppo pesante: {$field} (max 1,5 MB)"], 422);
            }
            $data = (string) $file->getStream();
            if (!str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
                return $this->json($response, ['error' => "Formato non valido: {$field} deve essere un PNG"], 422);
            }
            $info = @getimagesizefromstring($data);
            if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] !== $w || $info[1] !== $h) {
                return $this->json($response, ['error' => "Dimensioni errate per {$field}: servono {$w}×{$h} px"], 422);
            }
            $png[$key] = $data;
        }

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            return $this->json($response, ['error' => 'Impossibile creare la cartella di archiviazione sul server'], 500);
        }

        // Scrittura atomica: prima file temporanei, poi rename.
        $tmp = [];
        foreach ($png as $key => $data) {
            $t = $this->customPath($key) . '.tmp';
            if (@file_put_contents($t, $data) === false) {
                foreach ($tmp as $x) @unlink($x);
                @unlink($t);
                return $this->json($response, ['error' => 'Impossibile scrivere le icone: controlla i permessi della cartella storage/'], 500);
            }
            $tmp[$key] = $t;
        }
        foreach ($tmp as $key => $t) {
            rename($t, $this->customPath($key));
        }

        return $this->json($response, ['custom' => true, 'updated_at' => date('c')]);
    }

    // ── DELETE /api/branding/icon  (admin) ──────────────────────────
    public function reset(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        foreach (array_keys(self::ICONS) as $key) {
            $p = $this->customPath($key);
            if (is_file($p)) @unlink($p);
        }
        return $this->json($response, ['custom' => false, 'updated_at' => null]);
    }

    // ──────────────────────────────────────────────────────────────────

    /** Le chiavi numeriche ('192', '512') diventano int negli array PHP: accetto entrambi i tipi. */
    private function customPath(string|int $key): string
    {
        return $this->storageDir . '/icon-' . $key . '.png';
    }

    private function hasCustom(): bool
    {
        foreach (array_keys(self::ICONS) as $key) {
            if (!is_file($this->customPath($key))) return false;
        }
        return true;
    }

    private function requireAdmin(ServerRequestInterface $request, Response $response): ?ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }
        return null;
    }

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

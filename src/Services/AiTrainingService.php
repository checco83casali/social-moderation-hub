<?php
// src/Services/AiTrainingService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;

/**
 * Training AI (licenza Pro, feature `ai_training`).
 *
 * Con la funzione attiva, ogni decisione umana che rinforza o corregge il verdetto dell'AI
 * richiede una nota (lo stesso vale per le note "postume" aggiunte dal menù del commento).
 * Alla N-esima nota la raccolta si ferma da sola e Sonnet legge le note e propone una nuova
 * versione, INATTIVA, del prompt di moderazione: l'admin la rilegge e decide se attivarla.
 *
 * Ogni nota è classificata rispetto al verdetto dell'AI:
 *  - rinforzo:   l'umano decide nella direzione del sospetto/verdetto dell'AI
 *  - correzione: l'umano decide nella direzione opposta (l'AI era troppo severa o troppo permissiva)
 *  - neutro:     escalation senza alcun sospetto indicato dall'AI (nessuna direzione da giudicare)
 */
final class AiTrainingService
{
    public const MIN_NOTE_CHARS = 15;
    public const MIN_LIMIT      = 5;
    public const MAX_LIMIT      = 200;
    private const MIN_FOR_ANALYSIS = 3;

    private const HIDDEN_STATUSES   = ['hidden', 'hidden_reportable', 'removed', 'reported_legal'];
    private const ESCALATED_STATUSES = ['escalated_human', 'escalated_reportable', 'escalated_sonnet'];

    private static bool $analysisHooked = false;

    public function __construct(
        private readonly LicenseService $license,
        private readonly ClaudeService  $claude,
        private ?Logger                 $logger = null,
    ) {}

    // ── Stato ────────────────────────────────────────────────────────

    public function licensed(): bool
    {
        return $this->license->hasFeature('ai_training');
    }

    /** Sessione di raccolta in corso (solo se licenza + interruttore attivi). */
    public function activeSession(): ?object
    {
        if (!$this->licensed()) return null;
        try {
            if ($this->setting('ai_training_enabled') !== '1') return null;
            return DB::table('ai_training_sessions')->where('status', 'active')->orderByDesc('id')->first() ?: null;
        } catch (\Throwable) {
            return null;   // tabelle non ancora migrate
        }
    }

    /** @return array<string,mixed> */
    public function status(bool $withLast): array
    {
        $licensed = $this->licensed();
        $out = ['licensed' => $licensed, 'active' => false, 'count' => 0, 'target' => 0,
                'limit' => max(self::MIN_LIMIT, (int) ($this->setting('ai_training_limit') ?? 30)),
                'min_note_chars' => self::MIN_NOTE_CHARS];
        if (!$licensed) return $out;

        try {
            $s = $this->activeSession();
            if ($s) {
                $out['active']  = true;
                $out['target']  = (int) $s->target;
                $out['count']   = (int) DB::table('ai_training_notes')->where('session_id', $s->id)->count();
            }
            if ($withLast) {
                $last = DB::table('ai_training_sessions')->orderByDesc('id')->first();
                if ($last) {
                    $out['last'] = [
                        'id'                 => (int) $last->id,
                        'status'             => $last->status,
                        'target'             => (int) $last->target,
                        'count'              => (int) DB::table('ai_training_notes')->where('session_id', $last->id)->count(),
                        'reinforce'          => (int) DB::table('ai_training_notes')->where('session_id', $last->id)->where('kind', 'rinforzo')->count(),
                        'correct'            => (int) DB::table('ai_training_notes')->where('session_id', $last->id)->where('kind', 'correzione')->count(),
                        'started_at'         => $last->started_at,
                        'finished_at'        => $last->finished_at,
                        'summary'            => $last->summary,
                        'proposed_policy_id' => $last->proposed_policy_id !== null ? (int) $last->proposed_policy_id : null,
                        'error'              => $last->error,
                    ];
                }
            }
        } catch (\Throwable) {
            // tabelle non ancora migrate
        }
        return $out;
    }

    // ── Avvio / arresto ──────────────────────────────────────────────

    public function start(int $limit, ?int $userId): int
    {
        $limit = max(self::MIN_LIMIT, min(self::MAX_LIMIT, $limit));
        DB::table('ai_training_sessions')->where('status', 'active')->update(['status' => 'stopped', 'finished_at' => date('Y-m-d H:i:s')]);
        $id = (int) DB::table('ai_training_sessions')->insertGetId([
            'target' => $limit, 'status' => 'active', 'started_by' => $userId, 'started_at' => date('Y-m-d H:i:s'),
        ]);
        $this->saveSetting('ai_training_limit', (string) $limit, $userId);
        $this->saveSetting('ai_training_enabled', '1', $userId);
        return $id;
    }

    public function stop(?int $userId): void
    {
        DB::table('ai_training_sessions')->where('status', 'active')->update(['status' => 'stopped', 'finished_at' => date('Y-m-d H:i:s')]);
        $this->saveSetting('ai_training_enabled', '0', $userId);
    }

    // ── Verdetto dell'AI e classificazione ───────────────────────────

    /** Ultimo verdetto dato da un modello (esclude risposte in cache e righe di sistema). */
    public function aiVerdict(int $commentId): ?object
    {
        return DB::table('moderation_log')
            ->where('comment_id', $commentId)
            ->where('ai_model', 'like', 'claude%')
            ->whereNotNull('ai_decision')
            ->orderByDesc('id')
            ->first() ?: null;
    }

    /**
     * @param list<string> $categories
     * @param 'hidden'|'visible' $outcome
     * @return 'rinforzo'|'correzione'|'neutro'
     */
    public function classify(string $aiDecision, array $categories, string $outcome): string
    {
        if (in_array($aiDecision, ['hide', 'reportable'], true)) {
            return $outcome === 'hidden' ? 'rinforzo' : 'correzione';
        }
        if ($aiDecision === 'allow') {
            return $outcome === 'visible' ? 'rinforzo' : 'correzione';
        }
        // uncertain: l'AI ha indicato una direzione solo se ha segnalato delle categorie
        if ($categories === []) return 'neutro';
        return $outcome === 'hidden' ? 'rinforzo' : 'correzione';
    }

    /**
     * La decisione che sta per essere applicata richiede una nota di addestramento?
     *
     * @return array{kind:string}|null  null = nessuna nota richiesta
     */
    public function requirementFor(int $commentId, string $decision, string $role): ?array
    {
        $session = $this->activeSession();
        if (!$session || !in_array($role, ['admin', 'supervisor', 'moderator'], true)) return null;

        $status = (string) DB::table('comments')->where('id', $commentId)->value('status');
        $verdict = $this->aiVerdict($commentId);
        if (!$verdict) return null;
        if (DB::table('ai_training_notes')->where('session_id', $session->id)->where('comment_id', $commentId)->exists()) {
            return null;   // nota già presente per questo commento
        }

        $outcome = in_array($decision, ['allow', 'unhide', 'restore'], true) ? 'visible' : 'hidden';
        $before  = in_array($status, self::HIDDEN_STATUSES, true) ? 'hidden' : 'visible';
        $isReview = in_array($status, self::ESCALATED_STATUSES, true);
        if (!$isReview && $outcome === $before) return null;   // nessun cambio di stato

        return ['kind' => $this->classify((string) $verdict->ai_decision, $this->categories($verdict), $outcome)];
    }

    // ── Registrazione delle note ─────────────────────────────────────

    /**
     * Salva la nota. Se raggiunge il limite chiude la raccolta e programma l'analisi.
     *
     * @param 'hidden'|'visible' $outcome
     * @return array{count:int,target:int,completed:bool,kind:string}
     */
    public function record(int $commentId, ?int $userId, string $note, string $outcome, bool $posthumous): array
    {
        $session = $this->activeSession();
        if (!$session) throw new \RuntimeException('Training AI non attivo');
        $verdict = $this->aiVerdict($commentId);
        if (!$verdict) throw new \RuntimeException('Nessun verdetto AI per questo commento');

        $kind = $this->classify((string) $verdict->ai_decision, $this->categories($verdict), $outcome);
        DB::table('ai_training_notes')->insert([
            'session_id'    => $session->id,
            'comment_id'    => $commentId,
            'user_id'       => $userId,
            'kind'          => $kind,
            'posthumous'    => $posthumous ? 1 : 0,
            'note'          => mb_substr(trim($note), 0, 2000),
            'comment_text'  => mb_substr((string) DB::table('comments')->where('id', $commentId)->value('content'), 0, 2000),
            'ai_stage'      => $verdict->stage,
            'ai_decision'   => $verdict->ai_decision,
            'ai_confidence' => $verdict->ai_confidence,
            'ai_categories' => json_encode($this->categories($verdict), JSON_UNESCAPED_UNICODE),
            'ai_reason'     => $verdict->ai_reason,
            'final_outcome' => $outcome,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $count = (int) DB::table('ai_training_notes')->where('session_id', $session->id)->count();
        $done  = $count >= (int) $session->target;
        if ($done) {
            // Chiude la raccolta (una sola richiesta vince la transizione) e programma l'analisi.
            $won = DB::table('ai_training_sessions')->where('id', $session->id)->where('status', 'active')
                ->update(['status' => 'analyzing']);
            $this->saveSetting('ai_training_enabled', '0', null);
            if ($won === 1) $this->scheduleAnalysis((int) $session->id);
        }
        return ['count' => $count, 'target' => (int) $session->target, 'completed' => $done, 'kind' => $kind];
    }

    /**
     * Nota aggiunta dopo la decisione, dal menù del commento.
     *
     * @return array{count:int,target:int,completed:bool,kind:string}
     */
    public function recordPosthumous(int $commentId, ?int $userId, string $note): array
    {
        $status = (string) DB::table('comments')->where('id', $commentId)->value('status');
        if (in_array($status, self::HIDDEN_STATUSES, true))      $outcome = 'hidden';
        elseif ($status === 'approved')                          $outcome = 'visible';
        else throw new \DomainException('Il commento non ha ancora una decisione finale');
        return $this->record($commentId, $userId, $note, $outcome, true);
    }

    public function hasNote(int $commentId): bool
    {
        $s = $this->activeSession();
        return $s && DB::table('ai_training_notes')->where('session_id', $s->id)->where('comment_id', $commentId)->exists();
    }

    // ── Analisi e proposta di prompt ─────────────────────────────────

    /** Avvia l'analisi dopo che la risposta HTTP è stata consegnata. */
    public function scheduleAnalysis(int $sessionId): void
    {
        if (self::$analysisHooked) return;
        self::$analysisHooked = true;
        register_shutdown_function(function () use ($sessionId): void {
            ignore_user_abort(true);
            @set_time_limit(300);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            try {
                $this->analyze($sessionId);
            } catch (\Throwable $e) {
                $this->fail($sessionId, 'Analisi non riuscita');
                $this->logger?->error('AI training analysis failed: ' . $e->getMessage());
            }
        });
    }

    /** Rilancia l'analisi di una sessione già chiusa (stopped / failed / done). */
    public function retry(int $sessionId): bool
    {
        $n = DB::table('ai_training_notes')->where('session_id', $sessionId)->count();
        if ($n < self::MIN_FOR_ANALYSIS) return false;
        $ok = DB::table('ai_training_sessions')->where('id', $sessionId)
            ->whereIn('status', ['stopped', 'failed', 'done'])
            ->update(['status' => 'analyzing', 'error' => null]);
        if ($ok !== 1) return false;
        $this->scheduleAnalysis($sessionId);
        return true;
    }

    public function analyze(int $sessionId): bool
    {
        $notes = DB::table('ai_training_notes as n')
            ->leftJoin('comments as c', 'c.id', '=', 'n.comment_id')
            ->where('n.session_id', $sessionId)
            ->orderBy('n.id')
            ->get(['n.*', 'c.platform_post_id'])->all();
        if (count($notes) < self::MIN_FOR_ANALYSIS) {
            return $this->fail($sessionId, 'Troppe poche note per un\'analisi (minimo ' . self::MIN_FOR_ANALYSIS . ')');
        }

        $policy = DB::table('policies')->where('is_active', 1)->first();
        if (!$policy) return $this->fail($sessionId, 'Nessun prompt attivo da rivedere');

        $postIds = array_values(array_unique(array_filter(array_map(fn($n) => $n->platform_post_id, $notes))));
        $posts = [];
        try {
            if ($postIds) $posts = DB::table('post_contexts')->whereIn('platform_post_id', $postIds)->where('status', 'ready')->pluck('summary', 'platform_post_id')->all();
        } catch (\Throwable) {}

        $samples = [];
        foreach ($notes as $i => $n) {
            $samples[] = [
                'n'              => $i + 1,
                'comment'        => (string) $n->comment_text,
                'post_context'   => $posts[$n->platform_post_id] ?? null,
                'ai_stage'       => $n->ai_stage,
                'ai_decision'    => $n->ai_decision,
                'ai_confidence'  => $n->ai_confidence !== null ? (float) $n->ai_confidence : null,
                'ai_categories'  => json_decode((string) $n->ai_categories, true) ?: [],
                'ai_reason'      => $n->ai_reason,
                'human_outcome'  => $n->final_outcome === 'hidden' ? 'nascosto' : 'visibile',
                'relation'       => $n->kind,
                'moderator_note' => $n->note,
            ];
        }

        $result = $this->claude->reviewPromptFromTraining((string) $policy->moderation_prompt, $samples);
        if ($result === null) return $this->fail($sessionId, 'Analisi AI non riuscita');

        $name = 'Proposta addestramento AI ' . date('d/m/Y');
        $version = (int) (DB::table('policies')->where('name', $name)->max('version') ?? 0) + 1;
        $policyId = (int) DB::table('policies')->insertGetId([
            'name'              => $name,
            'description'       => 'Generata da ' . count($notes) . ' note di addestramento (sessione #' . $sessionId . '), partendo da «' . $policy->name . '» v' . $policy->version . '. Da rivedere prima di attivarla.',
            'moderation_prompt' => $result['proposed_prompt'],
            'is_active'         => 0,
            'version'           => $version,
            'created_by'        => (int) ($policy->created_by ?? 0),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        DB::table('ai_training_sessions')->where('id', $sessionId)->update([
            'status' => 'done', 'finished_at' => date('Y-m-d H:i:s'),
            'summary' => $result['summary'], 'proposed_policy_id' => $policyId, 'error' => null,
        ]);
        AuditService::log(null, 'ai_training.analysis', ['details' => ['session_id' => $sessionId, 'notes' => count($notes), 'policy_id' => $policyId]]);
        return true;
    }

    private function fail(int $sessionId, string $error): bool
    {
        DB::table('ai_training_sessions')->where('id', $sessionId)->update([
            'status' => 'failed', 'finished_at' => date('Y-m-d H:i:s'), 'error' => mb_substr($error, 0, 250),
        ]);
        return false;
    }

    // ── Helper ───────────────────────────────────────────────────────

    /** @return list<string> */
    private function categories(object $verdict): array
    {
        $c = json_decode((string) ($verdict->ai_categories ?? '[]'), true);
        return is_array($c) ? array_values(array_map('strval', $c)) : [];
    }

    private function setting(string $key): ?string
    {
        try {
            $v = DB::table('app_settings')->where('key', $key)->value('value');
            return $v === null ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveSetting(string $key, string $value, ?int $userId): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')]
        );
    }
}

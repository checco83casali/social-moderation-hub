<?php
// src/Services/GdprService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Supporto artt. 15/17/20 GDPR: ricerca, export e anonimizzazione dei dati
 * di un singolo utente social su richiesta dell'interessato (DSAR), evasa
 * manualmente dal Titolare tramite la dashboard admin.
 *
 * L'anonimizzazione riusa lo stesso schema di RetentionService::purge() —
 * placeholder sui campi PII, righe statistiche/di audit preservate — ma
 * applicato on-demand a un singolo social_user_id invece che per data di
 * cutoff. Mai un DELETE fisico: coerente con la conservazione richiesta
 * per la gestione di appelli/contenziosi già aperti (vedi §7 privacy policy).
 * Idempotente: una seconda chiamata sullo stesso utente è un no-op.
 */
class GdprService
{
    private const ANON_PREFIX = 'anon_';

    /**
     * Cerca un utente social per ID interno, ID piattaforma (Facebook ID)
     * o token di appello ricevuto in un commento. Restituisce null se non trovato.
     */
    public function findSocialUser(string $query): ?object
    {
        $query = trim($query);
        if ($query === '') return null;

        if (ctype_digit($query)) {
            $byId = DB::table('social_users')->find((int) $query);
            if ($byId) return $byId;
        }

        $byPlatform = DB::table('social_users')->where('platform_user_id', $query)->first();
        if ($byPlatform) return $byPlatform;

        $viaAppeal = DB::table('comments')->where('appeal_token', $query)->first();
        if ($viaAppeal) {
            return DB::table('social_users')->find($viaAppeal->social_user_id);
        }

        return null;
    }

    /**
     * Raccoglie tutti i dati collegati a un utente social per l'evasione
     * di una richiesta di accesso (art. 15) o portabilità (art. 20).
     *
     * @return array<string,mixed>|null
     */
    public function exportUserData(int $socialUserId): ?array
    {
        $user = DB::table('social_users')->find($socialUserId);
        if (!$user) return null;

        $comments = DB::table('comments')
            ->where('social_user_id', $socialUserId)
            ->orderByDesc('received_at')
            ->get()->map(fn($r) => (array) $r)->toArray();

        $banRecords = DB::table('ban_records')
            ->where('social_user_id', $socialUserId)
            ->orderByDesc('created_at')
            ->get()->map(fn($r) => (array) $r)->toArray();

        $appeals = DB::table('appeal_records')
            ->where('social_user_id', $socialUserId)
            ->orderByDesc('submitted_at')
            ->get()->map(fn($r) => (array) $r)->toArray();

        $commentIds = array_column($comments, 'id');
        $moderationLog = empty($commentIds) ? [] : DB::table('moderation_log')
            ->whereIn('comment_id', $commentIds)
            ->orderByDesc('created_at')
            ->get()->map(fn($r) => (array) $r)->toArray();

        return [
            'exported_at'    => date('c'),
            'social_user'    => (array) $user,
            'comments'       => $comments,
            'ban_records'    => $banRecords,
            'appeal_records' => $appeals,
            'moderation_log' => $moderationLog,
        ];
    }

    /**
     * Anonimizza irreversibilmente i dati identificativi di un utente social
     * (art. 17 — diritto alla cancellazione). Stessa logica di RetentionService:
     * placeholder sui campi PII, righe conservate per continuità statistica
     * e per eventuali appelli/contenziosi già aperti.
     *
     * @return array{comments:int,social_users:int,moderation_log:int,appeal_records:int}
     */
    public function anonymiseUser(int $socialUserId): array
    {
        return [
            'comments'       => $this->anonymiseComments($socialUserId),
            'social_users'   => $this->anonymiseSocialUser($socialUserId),
            'moderation_log' => $this->anonymiseModerationLog($socialUserId),
            'appeal_records' => $this->anonymiseAppealRecords($socialUserId),
        ];
    }

    private function anonymiseComments(int $socialUserId): int
    {
        $rows = DB::table('comments')
            ->where('social_user_id', $socialUserId)
            ->where('content', '!=', RetentionService::ANON_PLACEHOLDER)
            ->select('id')->get();

        foreach ($rows as $row) {
            DB::table('comments')->where('id', $row->id)->update([
                'content'             => RetentionService::ANON_PLACEHOLDER,
                'platform_comment_id' => self::ANON_PREFIX . 'c_' . $row->id,
                'appeal_token'        => null,
            ]);
        }
        return $rows->count();
    }

    private function anonymiseSocialUser(int $socialUserId): int
    {
        $user = DB::table('social_users')->where('id', $socialUserId)
            ->where(function ($q) {
                $q->where('display_name', '!=', RetentionService::ANON_PLACEHOLDER)
                  ->orWhereNull('display_name');
            })
            ->first();
        if (!$user) return 0;

        DB::table('social_users')->where('id', $socialUserId)->update([
            'display_name'     => RetentionService::ANON_PLACEHOLDER,
            'profile_url'      => null,
            'platform_user_id' => self::ANON_PREFIX . 'u_' . $socialUserId,
            'notes'            => null,
        ]);
        return 1;
    }

    private function anonymiseModerationLog(int $socialUserId): int
    {
        $commentIds = DB::table('comments')->where('social_user_id', $socialUserId)->pluck('id')->toArray();
        if (empty($commentIds)) return 0;

        return DB::table('moderation_log')
            ->whereIn('comment_id', $commentIds)
            ->where(function ($q) {
                $q->whereNotNull('ai_reason')
                  ->orWhereNotNull('ai_public_reason')
                  ->orWhereNotNull('ai_fact_check_draft')
                  ->orWhereNotNull('ai_fact_check_sources')
                  ->orWhereNotNull('ai_whataboutism_draft')
                  ->orWhereNotNull('human_note')
                  ->orWhereNotNull('removal_reply_text')
                  ->orWhereNotNull('appeal_text');
            })
            ->update([
                'ai_reason'             => null,
                'ai_public_reason'      => null,
                'ai_fact_check_draft'   => null,
                'ai_fact_check_sources' => null,
                'ai_whataboutism_draft' => null,
                'human_note'            => null,
                'removal_reply_text'    => null,
                'appeal_text'           => null,
            ]);
    }

    private function anonymiseAppealRecords(int $socialUserId): int
    {
        return DB::table('appeal_records')
            ->where('social_user_id', $socialUserId)
            ->where(function ($q) {
                $q->whereNotNull('appeal_text')->orWhereNotNull('reviewer_note');
            })
            ->update([
                'appeal_text'   => null,
                'reviewer_note' => null,
            ]);
    }

    /**
     * Registra un'azione nel registro delle richieste GDPR (accountability art. 5.2).
     *
     * @param array<string,mixed> $details
     */
    public function logAction(string $action, ?int $socialUserId, int $adminUserId, string $reason, array $details = []): void
    {
        DB::table('gdpr_audit_log')->insert([
            'action'         => $action,
            'social_user_id' => $socialUserId,
            'admin_user_id'  => $adminUserId,
            'reason'         => $reason !== '' ? $reason : null,
            'details'        => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function getAuditLog(int $limit = 100): array
    {
        return DB::table('gdpr_audit_log as g')
            ->leftJoin('admin_users as a', 'a.id', '=', 'g.admin_user_id')
            ->select(['g.*', 'a.name as admin_name'])
            ->orderByDesc('g.created_at')
            ->limit($limit)
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }
}

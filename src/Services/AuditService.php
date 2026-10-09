<?php

declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Registro di audit append-only (tabella `audit_log`).
 *
 * Una riga per ogni azione di un operatore: chi (id, ruolo, nome al momento dell'azione),
 * cosa, su quale commento/utente, prima/dopo, nota. Le righe non vengono mai modificate:
 * le elimina solo RetentionService, dopo la finestra delle violazioni. Solo gli admin
 * possono leggerlo (AuditController).
 *
 * Una scrittura che fallisce NON blocca l'azione dell'operatore: l'errore va nel log PHP.
 */
final class AuditService
{
    /**
     * @param object|null          $auth Payload JWT (sub, role) dell'operatore; null = sistema.
     * @param array<string, mixed> $ctx  comment_id, social_user_id, page_id, note, details (array)
     */
    public static function log(?object $auth, string $action, array $ctx = []): void
    {
        try {
            $actorId = isset($auth->sub) ? (int) $auth->sub : null;
            $label   = null;
            if ($actorId !== null) {
                $u     = DB::table('admin_users')->where('id', $actorId)->first(['name', 'email']);
                $label = $u ? (trim((string) ($u->name ?? '')) ?: ((string) ($u->email ?? '') ?: null)) : null;
            }

            $details = $ctx['details'] ?? null;
            DB::table('audit_log')->insert([
                'created_at'     => date('Y-m-d H:i:s'),
                'actor_id'       => $actorId,
                'actor_label'    => $label !== null ? mb_substr($label, 0, 190) : null,
                'actor_role'     => isset($auth->role) ? mb_substr((string) $auth->role, 0, 20) : null,
                'action'         => mb_substr($action, 0, 50),
                'comment_id'     => isset($ctx['comment_id']) ? (int) $ctx['comment_id'] : null,
                'social_user_id' => isset($ctx['social_user_id']) ? (int) $ctx['social_user_id'] : null,
                'page_id'        => isset($ctx['page_id']) ? (int) $ctx['page_id'] : null,
                'note'           => isset($ctx['note']) && $ctx['note'] !== '' ? mb_substr((string) $ctx['note'], 0, 2000) : null,
                'details'        => is_array($details) && $details !== []
                                        ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                        : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Audit] scrittura fallita (' . $action . '): ' . $e->getMessage());
        }
    }
}

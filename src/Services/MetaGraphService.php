<?php
// src/Services/MetaGraphService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Wraps the Meta (Facebook) Graph API.
 * Handles page connections, comment retrieval/deletion/hiding, webhook verification,
 * appeal reply posting, and user profile metadata for context-aware moderation.
 */
class MetaGraphService
{
    private const GRAPH_VERSION = 'v19.0';
    private const BASE_URL      = 'https://graph.facebook.com/';

    private Client $http;

    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
    ) {
        $this->http = new Client([
            'base_uri' => self::BASE_URL . self::GRAPH_VERSION . '/',
            'timeout'  => 15,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // OAuth / Page connection
    // ──────────────────────────────────────────────────────────────────

    public function getLongLivedToken(string $shortToken): string
    {
        $response = $this->http->get('oauth/access_token', [
            'query' => [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $this->appId,
                'client_secret'     => $this->appSecret,
                'fb_exchange_token' => $shortToken,
            ],
        ]);
        $data = json_decode((string) $response->getBody(), true);
        return $data['access_token'] ?? throw new \RuntimeException('Failed to exchange token');
    }

    public function getManagedPages(string $userToken): array
    {
        $response = $this->http->get('me/accounts', [
            'query' => [
                'access_token' => $userToken,
                'fields'       => 'id,name,access_token,fan_count',
            ],
        ]);
        $data = json_decode((string) $response->getBody(), true);
        return $data['data'] ?? [];
    }

    // ──────────────────────────────────────────────────────────────────
    // Webhook management
    // ──────────────────────────────────────────────────────────────────

    public function subscribePageWebhook(string $pageId, string $pageToken): bool
    {
        try {
            $response = $this->http->post("{$pageId}/subscribed_apps", [
                'query' => [
                    'access_token'      => $pageToken,
                    'subscribed_fields' => 'feed,mention',
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            $ok   = (bool) ($data['success'] ?? false);
            if (!$ok) {
                error_log("[MetaGraphService] subscribePageWebhook failed for page {$pageId}: " . json_encode($data));
            }
            return $ok;
        } catch (GuzzleException $e) {
            // Dump response body from the exception for diagnostics
            $body = method_exists($e, 'getResponse') && $e->getResponse()
                ? (string) $e->getResponse()->getBody()
                : $e->getMessage();
            error_log("[MetaGraphService] subscribePageWebhook exception for page {$pageId}: {$body}");
            return false;
        }
    }

    public function verifyWebhook(array $queryParams, string $verifyToken): ?string
    {
        if (
            ($queryParams['hub_mode']         ?? '') === 'subscribe' &&
            ($queryParams['hub_verify_token'] ?? '') === $verifyToken
        ) {
            return $queryParams['hub_challenge'] ?? null;
        }
        return null;
    }

    public function parseWebhookComments(array $payload): array
    {
        $comments = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            $pageId = $entry['id'] ?? null;
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'feed') continue;
                $value = $change['value'] ?? [];
                if (!in_array($value['item'] ?? '', ['comment', 'post'], true)) continue;

                $verb = $value['verb'] ?? '';
                if (!in_array($verb, ['add', 'edited'], true)) continue;

                // Meta's 'feed' webhook carries the commenter identity in `value.from`.
                // `sender_id`/`sender_name` are used by other webhook types (Messenger)
                // and sometimes omitted entirely due to privacy/PPCA gating, so we keep
                // them as fallback. If both are missing ModerationService will fetch
                // the identity via the comment endpoint (allowed with page token).
                $fromId   = $value['from']['id']   ?? $value['sender_id']   ?? '';
                $fromName = $value['from']['name'] ?? $value['sender_name'] ?? '';

                // Skip comments posted by the Page itself (e.g. automated reply notifications).
                // The page_id in the entry IS the Facebook Page ID — if the commenter's ID
                // matches it, this is the Page responding to its own post, not a real user.
                if ($fromId && $fromId === $pageId) continue;

                // For 'edited' events Meta does NOT include the new message text in the
                // webhook payload — ModerationService must call getComment() to retrieve it.
                $comments[] = [
                    'id'           => $value['comment_id'] ?? $value['post_id'] ?? null,
                    'post_id'      => $value['post_id'] ?? null,
                    'page_id'      => $pageId,
                    'message'      => $value['message'] ?? '',   // empty for 'edited' — fetched later
                    'from'         => ['id' => $fromId, 'name' => $fromName],
                    'created_time' => $value['created_time'] ?? time(),
                    'verb'         => $verb,                     // 'add' | 'edited'
                ];
            }
        }
        return array_values(array_filter($comments, fn($c) => !empty($c['id'])));
    }

    // ──────────────────────────────────────────────────────────────────
    // Comment actions
    // ──────────────────────────────────────────────────────────────────

    public function deleteComment(string $commentId, string $pageToken): bool
    {
        try {
            $response = $this->http->delete($commentId, [
                'query' => ['access_token' => $pageToken],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            return (bool) ($data['success'] ?? false);
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Hide a comment (visible only to author + page admins, not the public).
     * This is the preferred moderation action: content is preserved for audit/appeal
     * while being invisible to the general audience — GDPR-friendly.
     */
    public function hideComment(string $commentId, string $pageToken): bool
    {
        try {
            $response = $this->http->post($commentId, [
                'query' => [
                    'access_token' => $pageToken,
                    'is_hidden'    => 'true',
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            return (bool) ($data['success'] ?? false);
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Unhide a previously hidden comment (restore full visibility).
     * Called when an appeal is accepted by a human moderator.
     */
    public function unhideComment(string $commentId, string $pageToken): bool
    {
        try {
            $response = $this->http->post($commentId, [
                'query' => [
                    'access_token' => $pageToken,
                    'is_hidden'    => 'false',
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            return (bool) ($data['success'] ?? false);
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Post a reply to an existing comment (comment_id/comments endpoint).
     * Used for fact-check replies and point-6 educational responses.
     * The comment must still exist at call time.
     */
    public function replyToComment(string $commentId, string $message, string $pageToken): bool
    {
        return $this->replyToCommentResult($commentId, $message, $pageToken)['ok'];
    }

    /**
     * Come replyToComment(), ma ritorna anche il motivo dell'eventuale errore di
     * Facebook (per mostrarlo al moderatore). @return array{ok:bool,error:?string}
     */
    public function replyToCommentResult(string $commentId, string $message, string $pageToken): array
    {
        try {
            $response = $this->http->post("{$commentId}/comments", [
                'query' => ['access_token' => $pageToken],
                'json'  => ['message' => $message],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            return isset($data['id'])
                ? ['ok' => true,  'error' => null]
                : ['ok' => false, 'error' => 'Facebook ha risposto senza id commento'];
        } catch (GuzzleException $e) {
            // Cattura il motivo reale di Facebook (permessi, token, commento non rispondibile…)
            $detail = ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse())
                ? (string) $e->getResponse()->getBody()
                : $e->getMessage();
            error_log("[MetaGraph] replyToComment failed for {$commentId}: {$detail}");

            // Estrae il messaggio strutturato di Facebook se presente.
            $fbMsg = $detail;
            $j = json_decode($detail, true);
            if (isset($j['error']['message'])) {
                $fbMsg = $j['error']['message'];
                if (isset($j['error']['code']))    $fbMsg .= " (code {$j['error']['code']}";
                if (isset($j['error']['error_subcode'])) $fbMsg .= "/{$j['error']['error_subcode']}";
                if (isset($j['error']['code']))    $fbMsg .= ')';
            }
            return ['ok' => false, 'error' => $fbMsg];
        }
    }

    /**
     * Post a top-level comment on a post (used after comment deletion,
     * since the removed comment's thread no longer exists).
     * Tagging the user with @[user_id] works only when the user has
     * recently interacted with the page — call this BEFORE deleteComment().
     */
    public function postCommentOnPost(string $postId, string $message, string $pageToken): bool
    {
        try {
            $response = $this->http->post("{$postId}/comments", [
                'query' => ['access_token' => $pageToken],
                'json'  => ['message' => $message],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            return isset($data['id']);
        } catch (GuzzleException) {
            return false;
        }
    }

    public function getComment(string $commentId, string $pageToken): ?array
    {
        try {
            $response = $this->http->get($commentId, [
                'query' => [
                    'access_token' => $pageToken,
                    'fields'       => 'id,message,from,created_time,can_remove,is_hidden',
                ],
            ]);
            return json_decode((string) $response->getBody(), true);
        } catch (GuzzleException) {
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Account metadata (for context-aware moderation)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Fetch public profile fields for a Facebook user.
     * Used to enrich the moderation context sent to Claude:
     * new accounts, unverified pages, and low-follower profiles
     * are higher risk for scam and spam behaviour.
     *
     * Never throws — returns [] on any failure.
     */
    public function getUserPublicProfile(string $userId, string $pageToken): array
    {
        try {
            $response = $this->http->get($userId, [
                'query' => [
                    'access_token' => $pageToken,
                    'fields'       => 'id,name,created_time,fan_count,verified,link',
                ],
            ]);
            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Security
    // ──────────────────────────────────────────────────────────────────

    public function validateSignature(string $rawBody, string $signature): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->appSecret);
        return hash_equals($expected, $signature);
    }
}
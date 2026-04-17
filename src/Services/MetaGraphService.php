<?php
// src/Services/MetaGraphService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Wraps the Meta (Facebook) Graph API.
 * Handles page connections, comment retrieval/deletion, webhook verification,
 * and user profile metadata for context-aware moderation.
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
        $response = $this->http->post("{$pageId}/subscribed_apps", [
            'query' => [
                'access_token'      => $pageToken,
                'subscribed_fields' => 'feed,comments',
            ],
        ]);
        $data = json_decode((string) $response->getBody(), true);
        return (bool) ($data['success'] ?? false);
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
                if (($value['verb'] ?? '') !== 'add') continue;
                $comments[] = [
                    'id'           => $value['comment_id'] ?? $value['post_id'] ?? null,
                    'post_id'      => $value['post_id'] ?? null,
                    'page_id'      => $pageId,
                    'message'      => $value['message'] ?? '',
                    'from'         => [
                        'id'   => $value['sender_id']   ?? '',
                        'name' => $value['sender_name'] ?? '',
                    ],
                    'created_time' => $value['created_time'] ?? time(),
                ];
            }
        }
        return array_values(array_filter($comments, fn($c) => !empty($c['id']) && !empty($c['message'])));
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

    public function getComment(string $commentId, string $pageToken): ?array
    {
        try {
            $response = $this->http->get($commentId, [
                'query' => [
                    'access_token' => $pageToken,
                    'fields'       => 'id,message,from,created_time,can_remove',
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

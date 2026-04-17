<?php
// src/Services/ClaudeService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

/**
 * Orchestrates the AI moderation pipeline:
 *   Claude Haiku (fast/cheap) → Claude Sonnet (if uncertain) → Human
 */
class ClaudeService
{
    private const API_URL       = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION   = '2023-06-01';
    private const MODEL_HAIKU   = 'claude-haiku-4-5-20251001';
    private const MODEL_SONNET  = 'claude-sonnet-4-6';
    private const MAX_TOKENS    = 512;

    private Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly float  $haikuThreshold,   // below → escalate to Sonnet
        private readonly float  $sonnetThreshold,  // below → escalate to human
        private ?Logger         $logger = null,
    ) {
        $this->http = new Client([
            'timeout'         => 20,
            'connect_timeout' => 5,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Full moderation pipeline for a single comment.
     * Returns a ModerationResult with stage, decision, confidence, etc.
     */
    public function moderate(string $commentText, string $systemPrompt): ModerationResult
    {
        // Stage 1: Haiku
        $haiku = $this->callClaude(self::MODEL_HAIKU, $systemPrompt, $commentText);

        if ($haiku->decision !== 'uncertain' && $haiku->confidence >= $this->haikuThreshold) {
            $haiku->stage = 'haiku';
            return $haiku;
        }

        // Stage 2: Sonnet (escalated)
        $sonnet = $this->callClaude(self::MODEL_SONNET, $systemPrompt, $commentText, $haiku);

        if ($sonnet->decision !== 'uncertain' && $sonnet->confidence >= $this->sonnetThreshold) {
            $sonnet->stage = 'sonnet';
            return $sonnet;
        }

        // Stage 3: Human escalation
        $sonnet->stage      = 'human';
        $sonnet->decision   = 'uncertain';
        return $sonnet;
    }

    // ──────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────

    private function callClaude(
        string            $model,
        string            $systemPrompt,
        string            $commentText,
        ?ModerationResult $previousResult = null,
    ): ModerationResult {
        $userContent = $previousResult
            ? $this->buildEscalationPrompt($commentText, $previousResult)
            : "Evaluate the following comment:\n\n\"{$commentText}\"";

        $start = hrtime(true);

        try {
            $response = $this->http->post(self::API_URL, [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version'  => self::API_VERSION,
                    'content-type'       => 'application/json',
                ],
                'json' => [
                    'model'      => $model,
                    'max_tokens' => self::MAX_TOKENS,
                    'system'     => $systemPrompt,
                    'messages'   => [
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ],
            ]);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $body      = json_decode((string) $response->getBody(), true);
            $raw       = $body['content'][0]['text'] ?? '{}';

            return $this->parseResponse($raw, $model, $latencyMs);

        } catch (GuzzleException $e) {
            $this->logger?->error("Claude API error [{$model}]: " . $e->getMessage());
            // On API failure, escalate to human rather than auto-allow
            return new ModerationResult(
                stage:      'human',
                decision:   'uncertain',
                confidence: 0.0,
                reason:     'AI service temporarily unavailable',
                model:      $model,
                latencyMs:  0,
            );
        }
    }

    private function buildEscalationPrompt(string $commentText, ModerationResult $prev): string
    {
        return <<<TXT
        A previous AI review (confidence: {$prev->confidence}) marked this comment as uncertain or low-confidence.
        Please perform a more careful, thorough evaluation.
        
        Comment: "{$commentText}"
        
        Previous assessment: {$prev->reason}
        TXT;
    }

    private function parseResponse(string $raw, string $model, int $latencyMs): ModerationResult
    {
        // Strip possible markdown fences
        $clean = preg_replace('/^```json\s*|\s*```$/s', '', trim($raw));
        $data  = json_decode($clean, true);

        if (!is_array($data) || !isset($data['decision'])) {
            $this->logger?->warning("Claude returned unparseable response: {$raw}");
            return new ModerationResult(
                stage:      'human',
                decision:   'uncertain',
                confidence: 0.0,
                reason:     'AI returned an unparseable response',
                model:      $model,
                latencyMs:  $latencyMs,
            );
        }

        return new ModerationResult(
            stage:      'haiku', // overridden by caller
            decision:   in_array($data['decision'], ['allow','remove','uncertain'])
                            ? $data['decision'] : 'uncertain',
            confidence: min(1.0, max(0.0, (float) ($data['confidence'] ?? 0))),
            reason:     $data['reason'] ?? '',
            categories: $data['categories'] ?? [],
            severity:   $data['severity'] ?? null,
            model:      $model,
            latencyMs:  $latencyMs,
        );
    }
}

// ──────────────────────────────────────────────────────────────────────
// Value object
// ──────────────────────────────────────────────────────────────────────

class ModerationResult
{
    public function __construct(
        public string  $stage,
        public string  $decision,     // allow | remove | uncertain
        public float   $confidence,
        public string  $reason,
        public array   $categories = [],
        public ?string $severity    = null,
        public string  $model       = '',
        public int     $latencyMs   = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'stage'      => $this->stage,
            'decision'   => $this->decision,
            'confidence' => $this->confidence,
            'reason'     => $this->reason,
            'categories' => $this->categories,
            'severity'   => $this->severity,
            'model'      => $this->model,
            'latency_ms' => $this->latencyMs,
        ];
    }
}

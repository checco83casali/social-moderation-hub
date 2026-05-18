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
 *
 * PROMPT ARCHITECTURE
 * ───────────────────
 * The full system prompt sent to Claude is composed of two distinct parts:
 *
 *   1. TECHNICAL LAYER (hardcoded here as TECHNICAL_PROMPT_BLOCK)
 *      Defines the JSON output format, field semantics, decision vocabulary,
 *      routing rules, and runtime language/length instructions.
 *      Never editable by end users — changes here require a code deploy.
 *      This block is appended AFTER the moderation prompt so it always wins
 *      on formatting/output contracts.
 *
 *   2. MODERATION LAYER (stored in policies.moderation_prompt, editable in dashboard)
 *      Defines WHAT to moderate: community rules, violation categories,
 *      editorial escalation rules, fact-check criteria, context guidance.
 *      Operators customise this per installation via the Policy editor in the UI.
 *      Publicly visible at GET /public/policy (transparency endpoint).
 *
 * The compose() method in this class merges both layers at call time.
 * Historical policy logs remain unaffected because each log stores the
 * policy_id used, not the assembled prompt.
 */
class ClaudeService
{
    private const API_URL       = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION   = '2023-06-01';
    private const MODEL_HAIKU   = 'claude-haiku-4-5-20251001';
    private const MODEL_SONNET  = 'claude-sonnet-4-6';

    // Haiku: moderazione veloce, nessun fact-check → token contenuti
    private const MAX_TOKENS_HAIKU  = 800;
    // Sonnet: può produrre fact_check_draft + 3 fonti → serve più spazio
    private const MAX_TOKENS_SONNET = 3000;

    // ──────────────────────────────────────────────────────────────────
    // TECHNICAL PROMPT BLOCK — hardcoded, never user-editable
    //
    // Appended after the operator's moderation_prompt at every API call.
    // Defines the JSON contract that the application code depends on.
    // ──────────────────────────────────────────────────────────────────
    private const TECHNICAL_PROMPT_BLOCK = <<<'TECH'

════════════════════════════════════════
OUTPUT FORMAT — REQUIRED (DO NOT OVERRIDE)
════════════════════════════════════════
Respond ONLY with valid JSON. No preamble, no markdown fences, no explanation outside the JSON object.

{
  "decision": "allow" | "hide" | "remove" | "uncertain" | "reportable",
  "confidence": 0.0–1.0,
  "reason": "internal explanation for moderators (technical, precise)",
  "public_reason": "user-facing explanation (diplomatic, no internal signals) — required for hide/remove/reportable, null for allow",
  "categories": ["hate_speech","violence","harassment","doxxing","sexual","misinformation","spam","scam","grooming","coordinated_behaviour","journalist_criticism","outlet_criticism","illegal_content"],
  "severity": "low" | "medium" | "high",
  "editorial_category": null | "journalist_criticism" | "outlet_criticism",
  "fact_check_suggested": false | true,
  "fact_check_sources": [] | [{"title":"...","url":"https://...","summary":"one sentence in Italian"}],
  "fact_check_draft": null | "ready-to-publish reply text in Italian"
}

DECISION SEMANTICS:
- allow      → comment is acceptable, no action taken
- hide       → violates policy: hide from public, notify user with appeal link
- remove     → reserved for explicitly illegal content (court orders, CSAM)
- uncertain  → borderline: requires human moderator review
- reportable → may constitute a criminal offence (incitement, hate crime, doxxing with harm intent):
               auto-hidden immediately; human decides whether to file with authorities

SEVERITY SEMANTICS:
- low    → borderline, unlikely to cause real harm
- medium → clear violation, moderate impact
- high   → serious harm potential, legal risk, or predatory behaviour

════════════════════════════════════════
FACT-CHECK RULES — READ CAREFULLY
════════════════════════════════════════
The fact-check fields are for comments that contain verifiable factual claims
(statistics, historical events, scientific statements, news attributions).
They are NOT for opinions, insults, spam, or off-topic comments.

Set fact_check_suggested = true ONLY when ALL of the following are true:
  1. The comment contains a specific verifiable claim (e.g. "il governo ha tagliato X fondi", "lo studio dice Y%")
  2. The claim is plausibly false or misleading based on your knowledge
  3. A public editorial response would be useful to the audience reading the page

When fact_check_suggested = true, you MUST also populate:
  - fact_check_sources: 1–3 authoritative sources that confirm or refute the claim.
    IMPORTANT: Only include URLs you are highly confident exist and are directly relevant.
    Prefer canonical, stable URLs (e.g. homepage + section path) over deep article links that may redirect.
    Format: use the pattern https://domain.com/section/page — avoid query strings with ?title= or &id= parameters unless you are certain of the exact format.
    Each source must have: title (publication name + article/section title), url (full https://), summary (one sentence in Italian explaining what the source says).
    If you cannot identify a reliable URL, omit that source rather than guessing.
  - fact_check_draft: a ready-to-publish reply in Italian, written as the page editor speaking.
    Tone: factual, neutral, non-confrontational. Never call the user a liar.
    Structure: acknowledge the topic → state the correct information → cite the source briefly.
    Max 3 sentences. Must not include raw URLs inline (the moderator will attach sources separately).

When fact_check_suggested = false:
  - fact_check_sources must be [] (empty array)
  - fact_check_draft must be null

════════════════════════════════════════
LANGUAGE & LENGTH RULES — RUNTIME (DO NOT OVERRIDE)
════════════════════════════════════════
TECH;

    /**
     * Returns the technical prompt block with runtime variables injected.
     * Called at moderate() time so reasonMaxWords can vary per request.
     */
    private function technicalBlock(int $reasonMaxWords): string
    {
        return self::TECHNICAL_PROMPT_BLOCK . <<<INST

- Write BOTH "reason" and "public_reason" in Italian, regardless of the comment language.
- "reason" is for INTERNAL USE by moderators. Be technical and precise (e.g. "pig butchering pattern rilevato").
  - If decision = "allow": reason must be ≤ 10 words.
  - If decision ≠ "allow": reason must be ≤ {$reasonMaxWords} words.
- "public_reason" is published on Facebook as a reply. It must be:
  - Diplomatic and non-accusatory. Never describe the user as a scammer, criminal, or bot.
  - Phrased as a policy reference, not a personal judgment.
  - ≤ 20 words. Always in Italian.
  - If decision = "allow": set public_reason to null.
  - If decision = "uncertain": write a neutral public_reason as if removal is possible.
INST;
    }

    /**
     * Composes the full system prompt from the two layers:
     *   moderation_prompt (operator-editable) + technical block (hardcoded).
     *
     * The technical block is always appended last so its output-format rules
     * cannot be accidentally overridden by operator content.
     */
    public function composeSystemPrompt(string $moderationPrompt, int $reasonMaxWords = 40): string
    {
        return trim($moderationPrompt) . "\n\n" . $this->technicalBlock($reasonMaxWords);
    }

    private Client $http;

    public function __construct(
        private readonly string         $apiKey,
        private readonly float          $haikuThreshold,   // below → escalate to Sonnet
        private readonly float          $sonnetThreshold,  // below → escalate to human
        private readonly ?LicenseService $license = null,
        private ?Logger                 $logger   = null,
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
     *
     * @param string $commentText      Enriched comment context (pseudonymised).
     * @param string $moderationPrompt Operator-editable part only (from policies.moderation_prompt).
     *                                 The technical output-format block is appended automatically.
     * @param int    $reasonMaxWords   Max word count for internal reason field.
     *
     * Returns a ModerationResult with stage, decision, confidence, etc.
     */
    // ── Prompt dedicato fact-check (solo Sonnet) ──────────────────────
    // Chiamato SOLO quando Haiku ha già approvato il commento ma ha
    // segnalato fact_check_suggested = true. Non ri-modera — produce
    // solo draft, fonti e una confidence sul fact-check.
    private const FACT_CHECK_ONLY_PROMPT = <<<'FC'
You are a fact-checking assistant for a news outlet's social media team.
A comment has already been approved by the moderation system. Your ONLY task is to
verify a factual claim it contains and draft a public editorial reply.

Respond ONLY with valid JSON, no markdown fences:

{
  "fact_check_confidence": 0.0–1.0,
  "fact_check_sources": [{"title":"...","url":"https://...","summary":"one sentence in Italian"}],
  "fact_check_draft": "ready-to-publish reply in Italian (max 3 sentences, neutral tone, no raw URLs)"
}

RULES:
- fact_check_confidence: your confidence that the claim is verifiably inaccurate AND that your reply is factually correct. Be conservative — only go above 0.85 when you are certain of both.
- fact_check_sources: 1–3 real, authoritative sources (government sites, major outlets, scientific journals).
    IMPORTANT: Only include URLs you are highly confident exist. Prefer stable canonical paths over deep article links.
    Omit a source entirely if you are not certain of the exact URL — do not guess.
- fact_check_draft: written as the page editor. Acknowledge the topic, state the correct data, mention the source briefly. Never call the user a liar or wrong. Always in Italian.
- All summaries in Italian.
FC;

    public function moderate(string $commentText, string $moderationPrompt, int $reasonMaxWords = 40): ModerationResult
    {
        $systemPrompt      = $this->composeSystemPrompt($moderationPrompt, $reasonMaxWords);
        $factCheckLicensed = $this->license?->hasFeature('fact_check') ?? true;

        // Stage 1: Haiku — fact_check_suggested abilitato (solo flag booleano),
        // draft e fonti disabilitati (qualità insufficiente su Haiku)
        $haiku = $this->callClaude(
            self::MODEL_HAIKU, $systemPrompt, $commentText,
            null, factCheckFlag: true, factCheckFull: false,
        );

        if ($haiku->decision === 'reportable') {
            $haiku->stage = 'haiku';
            return $haiku;
        }

        // Haiku approva con confidenza sufficiente
        if ($haiku->decision !== 'uncertain' && $haiku->confidence >= $this->haikuThreshold) {
            $haiku->stage = 'haiku';

            // Secondo passaggio Sonnet dedicato al solo fact-check
            // (non ri-modera — produce solo draft + fonti + fact_check_confidence)
            if ($factCheckLicensed && $haiku->factCheckSuggested) {
                $haiku = $this->callClaudeFactCheck($commentText, $haiku);
            }

            return $haiku;
        }

        // Stage 2: Sonnet — moderazione + fact-check completo
        $sonnet = $this->callClaude(
            self::MODEL_SONNET, $systemPrompt, $commentText,
            $haiku, factCheckFlag: $factCheckLicensed, factCheckFull: $factCheckLicensed,
        );

        if ($sonnet->decision === 'reportable') {
            $sonnet->stage = 'sonnet';
            return $sonnet;
        }

        if ($sonnet->decision !== 'uncertain' && $sonnet->confidence >= $this->sonnetThreshold) {
            $sonnet->stage = 'sonnet';
            return $sonnet;
        }

        // Stage 3: Human escalation
        $sonnet->stage    = 'human';
        $sonnet->decision = 'uncertain';
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
        bool              $factCheckFlag  = true,  // abilita fact_check_suggested (solo flag)
        bool              $factCheckFull  = true,  // abilita draft + fonti (solo Sonnet)
    ): ModerationResult {
        $userContent = $previousResult
            ? $this->buildEscalationPrompt($commentText, $previousResult)
            : "Evaluate the following comment:\n\n\"{$commentText}\"";

        $maxTokens       = ($model === self::MODEL_HAIKU)
            ? self::MAX_TOKENS_HAIKU
            : self::MAX_TOKENS_SONNET;

        $effectiveSystem = $systemPrompt;

        if (!$factCheckFull) {
            // Haiku: può segnalare fact_check_suggested ma NON produrre draft/fonti
            $effectiveSystem .= "\n\nFACT-CHECK PARTIAL MODE: You MAY set fact_check_suggested = true if the comment contains a verifiable factual claim that appears inaccurate. However, set fact_check_sources = [] and fact_check_draft = null — a separate specialised model will handle those.";
        }

        if (!$factCheckFlag) {
            $effectiveSystem .= "\n\nFACT-CHECK OVERRIDE: Set fact_check_suggested = false, fact_check_sources = [], fact_check_draft = null.";
        }

        $start = hrtime(true);

        try {
            $response = $this->http->post(self::API_URL, [
                'headers' => [
                    'x-api-key'        => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'     => 'application/json',
                ],
                'json' => [
                    'model'      => $model,
                    'max_tokens' => $maxTokens,
                    'system'     => $effectiveSystem,
                    'messages'   => [
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ],
            ]);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $body      = json_decode((string) $response->getBody(), true);
            $raw       = $body['content'][0]['text'] ?? '{}';

            return $this->parseResponse($raw, $model, $latencyMs, $factCheckFlag, $factCheckFull);

        } catch (GuzzleException $e) {
            $this->logger?->error("Claude API error [{$model}]: " . $e->getMessage());
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

    /**
     * Secondo passaggio Sonnet dedicato esclusivamente al fact-check.
     * Chiamato solo quando Haiku ha approvato con factCheckSuggested = true.
     * Non rimette in discussione la decisione di moderazione.
     * Popola factCheckDraft, factCheckSources, factCheckConfidence sul result.
     */
    private function callClaudeFactCheck(string $commentText, ModerationResult $haikuResult): ModerationResult
    {
        $start = hrtime(true);

        try {
            $response = $this->http->post(self::API_URL, [
                'headers' => [
                    'x-api-key'        => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'     => 'application/json',
                ],
                'json' => [
                    'model'      => self::MODEL_SONNET,
                    'max_tokens' => self::MAX_TOKENS_SONNET,
                    'system'     => self::FACT_CHECK_ONLY_PROMPT,
                    'messages'   => [
                        ['role' => 'user', 'content' =>
                            "Please fact-check the following comment and draft an editorial reply:\n\n\"{$commentText}\""],
                    ],
                ],
            ]);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $body      = json_decode((string) $response->getBody(), true);
            $raw       = $body['content'][0]['text'] ?? '{}';

            $clean = preg_replace('/^```json\s*|\s*```$/s', '', trim($raw));
            $data  = json_decode($clean, true);

            if (!is_array($data)) {
                $this->logger?->warning("Fact-check Sonnet returned unparseable response: {$raw}");
                return $haikuResult;
            }

            // Inietta i campi fact-check nel result di Haiku (decisione invariata)
            $haikuResult->factCheckDraft      = $data['fact_check_draft']      ?? null;
            $haikuResult->factCheckSources    = $this->sanitizeSources($data['fact_check_sources'] ?? []);
            $haikuResult->factCheckConfidence = min(1.0, max(0.0, (float) ($data['fact_check_confidence'] ?? 0.0)));
            $haikuResult->factCheckSuggested  = true;
            $haikuResult->factCheckLatencyMs  = $latencyMs;

        } catch (GuzzleException $e) {
            $this->logger?->warning("Fact-check Sonnet call failed: " . $e->getMessage());
            // Non fatale — restituisce il result di Haiku senza draft
        }

        return $haikuResult;
    }

    /**
     * Sanitizza l'array di fonti — rimuove voci malformate o senza URL https.
     */
    private function sanitizeSources(array $raw): array
    {
        return array_values(array_filter(
            array_map(function ($s) {
                if (!is_array($s)) return null;
                $url = trim((string) ($s['url'] ?? ''));
                if (!str_starts_with($url, 'https://')) return null;
                return [
                    'title'   => substr(trim((string) ($s['title']   ?? '')), 0, 255),
                    'url'     => substr($url, 0, 512),
                    'summary' => substr(trim((string) ($s['summary'] ?? '')), 0, 512),
                ];
            }, $raw),
            fn($s) => $s !== null,
        ));
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

    private function parseResponse(string $raw, string $model, int $latencyMs, bool $factCheckFlag = true, bool $factCheckFull = true): ModerationResult
    {
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

        $validDecisions = ['allow', 'hide', 'remove', 'uncertain', 'reportable'];
        $decision = in_array($data['decision'], $validDecisions, true)
            ? $data['decision'] : 'uncertain';

        return new ModerationResult(
            stage:               'haiku', // overridden by caller
            decision:            $decision,
            confidence:          min(1.0, max(0.0, (float) ($data['confidence'] ?? 0))),
            reason:              $data['reason'] ?? '',
            publicReason:        $decision === 'allow' ? null : ($data['public_reason'] ?? null),
            categories:          $data['categories'] ?? [],
            severity:            $data['severity'] ?? null,
            factCheckSuggested:  $factCheckFlag && (bool) ($data['fact_check_suggested'] ?? false),
            factCheckDraft:      $factCheckFull ? ($data['fact_check_draft']   ?? null) : null,
            factCheckSources:    $factCheckFull ? $this->sanitizeSources($data['fact_check_sources'] ?? []) : [],
            factCheckConfidence: 0.0, // popolato da callClaudeFactCheck se chiamato
            editorialCategory:   $data['editorial_category'] ?? null,
            model:               $model,
            latencyMs:           $latencyMs,
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
        public string  $decision,
        public float   $confidence,
        public string  $reason,
        public ?string $publicReason        = null,
        public array   $categories          = [],
        public ?string $severity            = null,
        public bool    $factCheckSuggested  = false,
        public ?string $factCheckDraft      = null,
        public array   $factCheckSources    = [],
        public float   $factCheckConfidence = 0.0,  // confidence del fact-check Sonnet
        public int     $factCheckLatencyMs  = 0,    // latency del secondo call Sonnet
        public ?string $editorialCategory   = null,
        public string  $model               = '',
        public int     $latencyMs           = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'stage'                  => $this->stage,
            'decision'               => $this->decision,
            'confidence'             => $this->confidence,
            'reason'                 => $this->reason,
            'public_reason'          => $this->publicReason,
            'categories'             => $this->categories,
            'severity'               => $this->severity,
            'fact_check_suggested'   => $this->factCheckSuggested,
            'fact_check_draft'       => $this->factCheckDraft,
            'fact_check_sources'     => $this->factCheckSources,
            'fact_check_confidence'  => $this->factCheckConfidence,
            'fact_check_latency_ms'  => $this->factCheckLatencyMs,
            'editorial_category'     => $this->editorialCategory,
            'model'                  => $this->model,
            'latency_ms'             => $this->latencyMs,
        ];
    }
}
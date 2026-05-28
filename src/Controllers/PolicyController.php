<?php
// src/Controllers/PolicyController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Manages moderation policies.
 *
 * PROMPT SPLIT ARCHITECTURE
 * ─────────────────────────
 * Each policy record stores the operator-editable prompt:
 *
 *   moderation_prompt  — operator-editable via the dashboard Policy editor.
 *                        Defines WHAT to moderate (rules, categories, escalation logic).
 *                        Publicly visible at GET /public/policy (transparency).
 *
 * The technical output-format block is never stored in the DB; it is
 * assembled at call time by ClaudeService::composeSystemPrompt().
 *
 * Only admins can create/edit/activate policies.
 * Full version history is preserved for audit.
 */
class PolicyController
{
    // ClaudeService non è iniettato nel costruttore perché le route pubbliche
    // (/public/policy, /public/privacy-generator) non ne hanno bisogno,
    // e Slim non riesce a risolvere automaticamente dipendenze complesse
    // per controller usati fuori dal gruppo /api.
    // Se in futuro serve composeSystemPrompt() nelle route protette,
    // iniettare ClaudeService direttamente nel metodo via container.

    // ── GET /api/policies  ───────────────────────────────────────────
    public function index(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $policies = DB::table('policies as p')
            ->join('admin_users as a', 'a.id', '=', 'p.created_by')
            ->select(['p.*', 'a.name as created_by_name'])
            ->orderByDesc('p.created_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        return $this->json($response, $policies);
    }

    // ── GET /api/policies/{id}  ──────────────────────────────────────
    public function show(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $policy = DB::table('policies')->find((int) $args['id']);
        if (!$policy) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        return $this->json($response, (array) $policy);
    }

    // ── POST /api/policies  ──────────────────────────────────────────
    /** Create a new policy version using the split-prompt model. */
    public function create(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $body = (array) $request->getParsedBody();

        if (empty($body['name'])) {
            return $this->json($response, ['error' => "Field 'name' is required"], 422);
        }

        $moderationPrompt = trim($body['moderation_prompt'] ?? '');
        if ($moderationPrompt === '') {
            return $this->json($response, ['error' => "Field 'moderation_prompt' is required"], 422);
        }

        // Bump version number relative to same-name policies
        $lastVersion = DB::table('policies')
            ->where('name', $body['name'])
            ->max('version') ?? 0;

        $id = DB::table('policies')->insertGetId([
            'name'              => trim($body['name']),
            'description'       => $body['description'] ?? null,
            'moderation_prompt' => $moderationPrompt,
            'is_active'         => 0, // must be explicitly activated
            'version'           => $lastVersion + 1,
            'created_by'        => $auth->sub,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['id' => $id, 'message' => 'Policy created'], 201);
    }

    // ── PUT /api/policies/{id}  ──────────────────────────────────────
    /** Update name/description/moderation_prompt. Prompt changes create a new version via POST. */
    public function update(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $body   = (array) $request->getParsedBody();
        $policy = DB::table('policies')->find((int) $args['id']);
        if (!$policy) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $currentPrompt = $policy->moderation_prompt ?? '';

        // Prompt changes require a new version
        $newPrompt = $body['moderation_prompt'] ?? null;
        if ($newPrompt !== null && trim($newPrompt) !== trim($currentPrompt)) {
            return $this->json($response, [
                'error' => 'Changing moderation_prompt requires creating a new policy version (POST /api/policies)',
            ], 422);
        }

        $allowed = ['name', 'description'];
        $update  = [];
        foreach ($allowed as $field) {
            if (isset($body[$field])) {
                $update[$field] = $body[$field];
            }
        }

        if (empty($update)) {
            return $this->json($response, ['error' => 'Nothing to update'], 422);
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        DB::table('policies')->where('id', $policy->id)->update($update);

        return $this->json($response, ['message' => 'Policy updated']);
    }

    // ── POST /api/policies/{id}/activate  ────────────────────────────
    /** Activate a policy (deactivates all others). */
    public function activate(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $policy = DB::table('policies')->find((int) $args['id']);
        if (!$policy) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        DB::transaction(function () use ($policy) {
            DB::table('policies')->update(['is_active' => 0]);
            DB::table('policies')->where('id', $policy->id)->update([
                'is_active'  => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });

        return $this->json($response, ['message' => "Policy '{$policy->name}' v{$policy->version} is now active"]);
    }

    // ── GET /api/policies/active  ─────────────────────────────────────
    /** Return the currently active policy (used by frontend). */
    public function active(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $policy = DB::table('policies')->where('is_active', 1)->first();
        if (!$policy) {
            return $this->json($response, ['error' => 'No active policy'], 404);
        }
        return $this->json($response, (array) $policy);
    }

    // ── GET /public/policy  ───────────────────────────────────────────
    /**
     * PUBLIC transparency endpoint — no authentication required.
     *
     * Returns the operator-editable moderation rules (moderation_prompt)
     * of the currently active policy. The technical output-format block
     * is intentionally excluded: it is an implementation detail and exposing
     * it would give bad actors a roadmap for bypassing detection patterns.
     *
     * Also returns metadata: policy name, version, activation timestamp,
     * and the assembled technical block structure description so users
     * understand what the system does at a high level.
     */
    public function publicPolicy(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $policy = DB::table('policies')->where('is_active', 1)->first();
        if (!$policy) {
            return $this->json($response, ['error' => 'No active policy'], 404);
        }

        $moderationPrompt = $policy->moderation_prompt;

        $payload = [
            'policy' => [
                'name'        => $policy->name,
                'description' => $policy->description,
                'version'     => $policy->version,
                'activated_at'=> $policy->updated_at,
            ],
            'moderation_rules' => $moderationPrompt,
            'technical_layer'  => [
                'description' => 'The technical output-format block is hardcoded in the application '
                    . 'and not stored in the database. It defines the JSON contract (fields, '
                    . 'decision vocabulary, severity scale) that the application depends on. '
                    . 'It is intentionally excluded from this public endpoint.',
                'output_fields' => [
                    'decision'              => 'allow | hide | remove | uncertain | reportable',
                    'confidence'            => '0.0–1.0 float',
                    'reason'               => 'internal explanation for moderators (Italian)',
                    'public_reason'        => 'user-facing explanation published on Facebook (Italian)',
                    'categories'           => 'array of matched violation categories',
                    'severity'             => 'low | medium | high',
                    'editorial_category'   => 'null | journalist_criticism | outlet_criticism',
                    'fact_check_suggested'   => 'boolean',
                    'fact_check_sources'     => 'array of {title, url, summary}',
                    'fact_check_draft'       => 'ready-to-publish reply text or null',
                    'whataboutism_suggested' => 'boolean — comment is a topical deflection',
                ],
            ],
            'pipeline' => [
                'stage_1' => 'Claude Haiku — fast initial analysis',
                'stage_2' => 'Claude Sonnet — deep analysis (when Haiku confidence is below threshold)',
                'stage_3' => 'Human moderator — review (when both AI stages are uncertain)',
            ],
            'privacy_note' => 'Comment text is transmitted to the AI service (Anthropic PBC) for analysis. '
                . 'The commenter is identified only by an irreversible pseudonym (HMAC-SHA256). '
                . 'No real names, Facebook IDs, or contact details are transmitted. '
                . 'See the Privacy Policy at /privacy for full details.',
        ];

        return $this->json($response, $payload);
    }

    // ─────────────────────────────────────────────────────────────────

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}

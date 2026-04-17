<?php
// src/Controllers/PolicyController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Manages moderation policies (system prompts for Claude).
 * Only admins can create/edit/activate policies.
 * Full version history is preserved for audit.
 */
class PolicyController
{
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
    /** Create a new policy version. */
    public function create(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $body = (array) $request->getParsedBody();

        $required = ['name', 'system_prompt'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return $this->json($response, ['error' => "Field '{$field}' is required"], 422);
            }
        }

        // Bump version number relative to same-name policies
        $lastVersion = DB::table('policies')
            ->where('name', $body['name'])
            ->max('version') ?? 0;

        $id = DB::table('policies')->insertGetId([
            'name'          => trim($body['name']),
            'description'   => $body['description'] ?? null,
            'system_prompt' => $body['system_prompt'],
            'is_active'     => 0, // must be explicitly activated
            'version'       => $lastVersion + 1,
            'created_by'    => $auth->sub,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['id' => $id, 'message' => 'Policy created'], 201);
    }

    // ── PUT /api/policies/{id}  ──────────────────────────────────────
    /** Update name/description only. Prompt changes require a new version. */
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

        $allowed = ['name', 'description'];
        $update  = [];
        foreach ($allowed as $field) {
            if (isset($body[$field])) {
                $update[$field] = $body[$field];
            }
        }

        // system_prompt change → create new version instead
        if (!empty($body['system_prompt']) && $body['system_prompt'] !== $policy->system_prompt) {
            return $this->json($response, [
                'error' => 'Changing system_prompt requires creating a new policy version (POST /api/policies)',
            ], 422);
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
    /** Return the currently active policy (used by frontend to display it). */
    public function active(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $policy = DB::table('policies')->where('is_active', 1)->first();
        if (!$policy) {
            return $this->json($response, ['error' => 'No active policy'], 404);
        }
        return $this->json($response, (array) $policy);
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

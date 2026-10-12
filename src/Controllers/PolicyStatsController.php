<?php
// src/Controllers/PolicyStatsController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\LicenseService;
use ModerationHub\Services\PolicyStatsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Utilizzo ed efficacia delle versioni del prompt (licenza `prompt_stats`, livello Enterprise).
 * Controller a parte perché PolicyController non può ricevere dipendenze (serve anche alle route pubbliche).
 */
class PolicyStatsController
{
    public function __construct(private readonly LicenseService $license) {}

    // ── GET /api/policies/stats?scope=all|prod|dev  (admin e supervisori) ──
    public function stats(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $role = $request->getAttribute('auth_user')->role ?? '';
        if (!in_array($role, ['admin', 'supervisor'], true)) {
            return $this->json($response, ['error' => 'Admin or supervisor required'], 403);
        }
        if (!$this->license->hasFeature('prompt_stats')) {
            return $this->json($response, [
                'error' => 'Funzionalità inclusa nella licenza Enterprise.',
                'code'  => 'license_required',
                'feature' => 'prompt_stats',
            ], 403);
        }
        $scope = (string) ($request->getQueryParams()['scope'] ?? 'all');
        return $this->json($response, (new PolicyStatsService())->compute($scope));
    }

    /** @param array<string,mixed> $data */
    private function json(Response $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

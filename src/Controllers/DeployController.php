<?php
// src/Controllers/DeployController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * GitHub auto-deploy webhook.
 *
 * Listens at POST /webhook/github. When the configured branch receives a push,
 * pulls the new commits with a fast-forward-only `git pull` in the project root.
 * The handler is **fail-closed**: without GITHUB_WEBHOOK_SECRET set in .env it
 * returns 503 and does nothing.
 */
class DeployController
{
    public function pull(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $secret = (string) ($_ENV['GITHUB_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            return $this->reply($response, 503, ['error' => 'deploy webhook not configured']);
        }

        $rawBody   = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('X-Hub-Signature-256');
        $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if ($sigHeader === '' || !hash_equals($expected, $sigHeader)) {
            return $this->reply($response, 403, ['error' => 'invalid signature']);
        }

        // ping events are sent by GitHub when the webhook is created — answer pong.
        $event = $request->getHeaderLine('X-GitHub-Event');
        if ($event === 'ping') {
            return $this->reply($response, 200, ['ok' => true, 'pong' => true]);
        }
        if ($event !== 'push') {
            return $this->reply($response, 200, ['ok' => true, 'skipped_event' => $event]);
        }

        // React only to pushes on the configured branch (default: main).
        $branch  = (string) ($_ENV['GITHUB_WEBHOOK_BRANCH'] ?? 'main');
        $payload = json_decode($rawBody, true);
        $ref     = is_array($payload) ? ($payload['ref'] ?? '') : '';
        if ($ref !== "refs/heads/{$branch}") {
            return $this->reply($response, 200, ['ok' => true, 'skipped_ref' => $ref]);
        }

        // Ensure exec() is available — some shared hosts disable it.
        if (!function_exists('exec')) {
            return $this->reply($response, 500, ['error' => 'exec() is disabled on this server']);
        }

        $root = dirname(__DIR__, 2);
        $cmd  = sprintf(
            'cd %s && git pull --ff-only origin %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($branch),
        );

        $output = [];
        $rc     = 0;
        @exec($cmd, $output, $rc);

        @file_put_contents(
            $root . '/logs/deploy.log',
            sprintf("[%s] git pull rc=%d branch=%s\n%s\n\n",
                date('c'), $rc, $branch, implode("\n", $output)),
            FILE_APPEND,
        );

        if ($rc !== 0) {
            // Don't leak the raw git output to the caller — keep it in the log.
            return $this->reply($response, 500, ['error' => 'pull failed', 'rc' => $rc]);
        }
        return $this->reply($response, 200, ['ok' => true, 'branch' => $branch]);
    }

    /** @param array<string,mixed> $data */
    private function reply(Response $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

<?php
// src/Middleware/AccessGuardMiddleware.php
declare(strict_types=1);

namespace ModerationHub\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Network-level access guard (defense in depth).
 *
 * The AUTHORITATIVE control must be the server firewall / reverse proxy
 * (see docs/deployment-security.md). This middleware is a secondary safety
 * net for requests that reach PHP, and it also implements the "split domain"
 * model: a single public hostname exposes ONLY the public endpoints (Meta
 * webhook, appeal page, transparency pages), while everything else (dashboard,
 * /api, OAuth, page-connect) is reachable only from an internal IP allowlist.
 *
 * Fully OPT-IN: with both PUBLIC_DOMAIN and INTERNAL_IP_ALLOWLIST empty it is
 * a no-op, so existing single-host installations keep working unchanged.
 *
 * Relevant .env keys:
 *   INTERNAL_IP_ALLOWLIST  CSV of IPv4/IPv6 or CIDR ranges allowed to reach
 *                          non-public paths. Empty = IP check disabled.
 *   PUBLIC_DOMAIN          Hostname that may serve ONLY public paths. Empty =
 *                          domain split disabled.
 *   TRUSTED_PROXIES        CSV of proxy IPs whose X-Forwarded-For we trust for
 *                          the real client IP. Empty = always use REMOTE_ADDR.
 *   PUBLIC_PATHS           Optional CSV of path prefixes considered public.
 *                          Defaults to the built-in public surface below.
 */
class AccessGuardMiddleware implements MiddlewareInterface
{
    /** Built-in public path prefixes (everything Meta or an end-user must reach). */
    private const DEFAULT_PUBLIC_PREFIXES = [
        '/webhook/meta',
        '/appeal',
        '/public/',
        '/privacy',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $allowlist = $this->csv($_ENV['INTERNAL_IP_ALLOWLIST'] ?? '');
        $publicDomain = trim((string) ($_ENV['PUBLIC_DOMAIN'] ?? ''));

        // No-op when nothing is configured.
        if ($allowlist === [] && $publicDomain === '') {
            return $handler->handle($request);
        }

        $path       = '/' . ltrim($request->getUri()->getPath(), '/');
        $isPublic   = $this->isPublicPath($path);
        $host       = strtolower($request->getUri()->getHost());

        // 1. Public domain may serve ONLY public paths — hide everything else.
        if ($publicDomain !== '' && $host === strtolower($publicDomain)) {
            return $isPublic ? $handler->handle($request) : $this->deny(404, 'Not found');
        }

        // 2. On the internal host, restrict non-public paths to the IP allowlist.
        if ($allowlist !== [] && !$isPublic) {
            $clientIp = $this->clientIp($request);
            if ($clientIp === null || !$this->ipAllowed($clientIp, $allowlist)) {
                return $this->deny(403, 'Forbidden');
            }
        }

        return $handler->handle($request);
    }

    private function isPublicPath(string $path): bool
    {
        $prefixes = $this->csv($_ENV['PUBLIC_PATHS'] ?? '') ?: self::DEFAULT_PUBLIC_PREFIXES;
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the real client IP. X-Forwarded-For is honoured ONLY when the
     * direct peer (REMOTE_ADDR) is a configured trusted proxy — otherwise the
     * header is attacker-controlled and must be ignored.
     */
    private function clientIp(ServerRequestInterface $request): ?string
    {
        $server   = $request->getServerParams();
        $remote   = $server['REMOTE_ADDR'] ?? null;
        $trusted  = $this->csv($_ENV['TRUSTED_PROXIES'] ?? '');

        if ($remote !== null && $trusted !== [] && $this->ipAllowed($remote, $trusted)) {
            $xff = $request->getHeaderLine('X-Forwarded-For');
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        return $remote;
    }

    /** @param string[] $ranges */
    private function ipAllowed(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return inet_pton($ip) !== false && inet_pton($range) !== false
                && inet_pton($ip) === inet_pton($range);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        $bits      = (int) $bits;

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // mixed IPv4/IPv6 or malformed
        }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr(0xff << (8 - $rem) & 0xff);
        return (($ipBin[$bytes] ^ $subnetBin[$bytes]) & $mask) === "\0";
    }

    /** @return string[] */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
    }

    private function deny(int $status, string $message): Response
    {
        $response = new Response();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

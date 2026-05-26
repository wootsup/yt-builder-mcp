<?php
/**
 * Per-kid + generic-bucket rate-limiter using JoomlaTransientStore.
 * Cookbook §2.6 parity: 60 writes/60s/kid + 10 pickup-claims/60s/IP.
 *
 * @package WootsUp\BuilderMcp\Platform\Joomla\Rest
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Rest;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaTransientStore;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class JoomlaRateLimiter
{
    public const WINDOW_SECONDS = 60;
    public const WRITE_LIMIT = 60;
    public const KEY_PREFIX = 'rate_';

    public function __construct(private readonly JoomlaTransientStore $store = new JoomlaTransientStore())
    {
    }

    /**
     * @return array{error_code:string, status:int, payload:array<string,mixed>}|null
     *         null = allowed; non-null = caller MUST reject with given payload.
     */
    public function checkWrite(string $kid): ?array
    {
        if ($kid === '') {
            return null;
        }
        return $this->checkBucket(
            self::KEY_PREFIX . $this->sanitise($kid, 64),
            self::WRITE_LIMIT,
            self::WINDOW_SECONDS,
            'yootheme_builder_mcp.rate_limited'
        );
    }

    /**
     * @return array{error_code:string, status:int, payload:array<string,mixed>}|null
     */
    public function checkGeneric(string $bucketKey, int $maxAttempts, int $windowSeconds, string $errorCode = 'yootheme_builder_mcp.rate_limited'): ?array
    {
        $sanitised = $this->sanitise($bucketKey, 80);
        if ($sanitised === '') {
            return null;
        }
        return $this->checkBucket(self::KEY_PREFIX . $sanitised, $maxAttempts, $windowSeconds, $errorCode);
    }

    /**
     * @return array{error_code:string, status:int, payload:array<string,mixed>}|null
     */
    private function checkBucket(string $transientKey, int $maxAttempts, int $windowSeconds, string $errorCode): ?array
    {
        $current = $this->store->get($transientKey);
        $count = \is_numeric($current) ? (int) $current : 0;
        $next = $count + 1;
        if ($next > $maxAttempts) {
            SecurityLogger::log(SecurityLogger::EVENT_RATE_LIMIT, [
                'platform' => 'joomla',
                'bucket' => $transientKey,
                'limit' => $maxAttempts,
                'window_seconds' => $windowSeconds,
            ]);
            return [
                'error_code' => $errorCode,
                'status' => 429,
                'payload' => [
                    'code' => $errorCode,
                    'message' => \sprintf('Rate limit exceeded: %d attempts per %d seconds.', $maxAttempts, $windowSeconds),
                    'data' => [
                        'status' => 429,
                        'limit' => $maxAttempts,
                        'window_seconds' => $windowSeconds,
                        'retry_after' => $windowSeconds,
                    ],
                ],
            ];
        }
        $this->store->set($transientKey, (string) $next, $windowSeconds);
        return null;
    }

    private function sanitise(string $input, int $maxLen): string
    {
        $clean = (string) \preg_replace('/[^A-Za-z0-9_-]/', '', $input);
        return \substr($clean, 0, $maxLen);
    }
}

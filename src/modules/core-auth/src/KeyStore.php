<?php
/**
 * KeyStore — wp_options-backed metadata store for Bearer-token kids.
 *
 * Storage: `wp_option('ytb_mcp_keys')` with autoload=false (the option is
 * only ever read inside REST authentication, never on front-end page
 * renders).
 *
 * Wave-6 Round-2 R2.11 — versioned envelope + CAS:
 *
 *   [
 *     'version' => N,                  // monotonic; incremented on every write
 *     'kids'    => [
 *       '<kid>' => [
 *         'label'      => 'Human-readable label',
 *         'scope'      => 'read'|'write'|'admin',
 *         'created_at' => unix-timestamp,
 *         'expires_at' => unix-timestamp|null,
 *         'revoked_at' => unix-timestamp|null,
 *       ],
 *       …
 *     ],
 *   ]
 *
 * `register()` and `revoke()` do an optimistic-concurrency check: read
 * the envelope, mutate, re-read before persist, compare versions. On
 * version-drift (a concurrent writer beat us), retry up to N times
 * before giving up with a logged security event.
 *
 * Backwards compatibility: legacy unversioned envelopes (flat
 * `<kid> => meta` map) are read-migrated on first load — they implicitly
 * carry version 0 and get bumped to 1 on the next write.
 *
 * The store deliberately does NOT hold the secret token itself — only the
 * kid + metadata. The token is shown once at generation-time and never
 * recoverable, in keeping with Stripe-style key-management UX.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

use WootsUp\BuilderMcp\Util\SecurityLogger;

final class KeyStore implements KeyStoreInterface
{
    /** wp_option key. */
    public const OPTION = 'ytb_mcp_keys';

    /** Maximum CAS retries before giving up. 5 = ~50ms worst-case lock-spin. */
    public const MAX_CAS_RETRIES = 5;

    /**
     * Persist a new kid + its metadata. Overwrites any existing entry with
     * the same kid (caller's responsibility to guard against accidental
     * overwrites with the desired UX).
     *
     * R2.11: CAS via versioned envelope — on concurrent writer race, retry
     * up to MAX_CAS_RETRIES then log + throw.
     *
     * @param array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * } $metadata
     */
    public function register(string $kid, array $metadata): void
    {
        if ($kid === '') {
            throw new \InvalidArgumentException('kid must not be empty.');
        }
        $this->mutateWithCas(static function (array &$kids) use ($kid, $metadata): void {
            $kids[$kid] = $metadata;
        }, 'register');
    }

    /**
     * @return array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * }|null
     */
    public function find(string $kid): ?array
    {
        return $this->loadAll()[$kid] ?? null;
    }

    /**
     * @return array<string, array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * }>
     */
    public function list(): array
    {
        return $this->loadAll();
    }

    /**
     * Mark the given kid as revoked. No-op if the kid is unknown.
     */
    public function revoke(string $kid): void
    {
        $this->mutateWithCas(static function (array &$kids) use ($kid): void {
            if (!isset($kids[$kid])) {
                return;
            }
            $kids[$kid]['revoked_at'] = time();
        }, 'revoke');
    }

    /**
     * @return array<string, array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * }>
     */
    private function loadAll(): array
    {
        return $this->loadEnvelope()['kids'];
    }

    /**
     * Load the full envelope (version + kids). Migrates legacy unversioned
     * data on the fly. Never throws — corruption returns the empty envelope.
     *
     * @return array{version: int, kids: array<string, array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * }>}
     */
    private function loadEnvelope(): array
    {
        /** @var mixed $raw */
        $raw = \get_option(self::OPTION, []);
        if (!is_array($raw)) {
            return ['version' => 0, 'kids' => []];
        }

        // Versioned envelope (post-R2.11).
        if (isset($raw['kids']) && is_array($raw['kids']) && isset($raw['version'])) {
            /** @var array{version: int, kids: array<string, array{
             *   label: string,
             *   scope: string,
             *   created_at: int,
             *   expires_at: int|null,
             *   revoked_at: int|null,
             * }>} $raw
             */
            $version = is_numeric($raw['version']) ? (int) $raw['version'] : 0;
            return ['version' => $version, 'kids' => $raw['kids']];
        }

        // Legacy flat envelope — treat the whole array as the kids-map and
        // implicitly versioned at 0 (next write bumps to 1).
        /** @var array<string, array{
         *   label: string,
         *   scope: string,
         *   created_at: int,
         *   expires_at: int|null,
         *   revoked_at: int|null,
         * }> $raw
         */
        return ['version' => 0, 'kids' => $raw];
    }

    /**
     * CAS write: load envelope → apply $mutator → re-read → compare versions
     * → write. Retries up to MAX_CAS_RETRIES on version drift. Logs +
     * throws when the retry budget exhausts.
     *
     * @param callable(array<string, mixed>&): void $mutator
     */
    private function mutateWithCas(callable $mutator, string $opLabel): void
    {
        for ($attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++) {
            $envelope = $this->loadEnvelope();
            $beforeVersion = $envelope['version'];
            $kids = $envelope['kids'];

            $mutator($kids);

            // Re-read to detect a concurrent writer between load + write.
            $reread = $this->loadEnvelope();
            if ($reread['version'] !== $beforeVersion) {
                // Drift detected — retry with fresh data.
                SecurityLogger::log(SecurityLogger::EVENT_KEYSTORE_RACE, [
                    'op' => $opLabel,
                    'attempt' => $attempt + 1,
                    'before_version' => $beforeVersion,
                    'observed_version' => $reread['version'],
                ]);
                continue;
            }

            $newEnvelope = [
                'version' => $beforeVersion + 1,
                'kids' => $kids,
            ];
            \update_option(self::OPTION, $newEnvelope, false);
            return;
        }

        SecurityLogger::log(SecurityLogger::EVENT_KEYSTORE_RACE, [
            'op' => $opLabel,
            'outcome' => 'exhausted_retries',
        ]);
        throw new \RuntimeException(
            sprintf('KeyStore::%s exhausted %d CAS retries — concurrent writer storm.', $opLabel, self::MAX_CAS_RETRIES),
        );
    }
}

<?php
/**
 * Joomla twin of {@see WootsUp\BuilderMcp\Auth\KeyStore}.
 *
 * Implements {@see KeyStoreInterface} so {@see BearerVerifier} can use
 * either WP or Joomla storage transparently. The envelope shape is
 * IDENTICAL to the WP side (version + kids), serialised as JSON in
 * `#__ytb_mcp_options` row keyed `keys`. CAS retries mirror the WP
 * pattern (5 attempts, log + throw on exhaustion).
 *
 * Cookbook reference: §2.3 (KeyStore contract + CAS retry budget) +
 * §4.13.2 (Joomla CAS via INSERT IGNORE on PRIMARY KEY column).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Auth
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Auth;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Auth\KeyStoreInterface;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class JoomlaKeyStore implements KeyStoreInterface
{
    public const OPTION_KEY      = 'keys';
    public const MAX_CAS_RETRIES = 5;

    public function __construct(private readonly JoomlaOptionStore $store = new JoomlaOptionStore())
    {
    }

    /** {@inheritDoc} */
    public function register(string $kid, array $metadata): void
    {
        if ($kid === '') {
            throw new \InvalidArgumentException('kid must not be empty.');
        }
        $this->mutateWithCas(static function (array &$kids) use ($kid, $metadata): void {
            $kids[$kid] = $metadata;
        }, 'register');
    }

    /** {@inheritDoc} */
    public function find(string $kid): ?array
    {
        return $this->loadEnvelope()['kids'][$kid] ?? null;
    }

    /** {@inheritDoc} */
    public function list(): array
    {
        return $this->loadEnvelope()['kids'];
    }

    /** {@inheritDoc} */
    public function revoke(string $kid): void
    {
        $this->mutateWithCas(static function (array &$kids) use ($kid): void {
            if (!isset($kids[$kid])) {
                return;
            }
            $kids[$kid]['revoked_at'] = \time();
        }, 'revoke');
    }

    /**
     * @return array{version: int, kids: array<string, array{
     *   label: string, scope: string, created_at: int,
     *   expires_at: int|null, revoked_at: int|null
     * }>}
     */
    private function loadEnvelope(): array
    {
        $raw = $this->store->get(self::OPTION_KEY, null);
        if (!\is_string($raw) || $raw === '') {
            return ['version' => 0, 'kids' => []];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return ['version' => 0, 'kids' => []];
        }
        if (isset($decoded['kids']) && \is_array($decoded['kids']) && isset($decoded['version'])) {
            $version = \is_numeric($decoded['version']) ? (int) $decoded['version'] : 0;
            return ['version' => $version, 'kids' => $decoded['kids']];
        }
        // Legacy flat envelope (would only exist if hand-migrated from WP).
        return ['version' => 0, 'kids' => $decoded];
    }

    /**
     * CAS write — mirrors WP KeyStore::mutateWithCas semantics.
     *
     * @param callable(array<string, mixed>&): void $mutator
     */
    private function mutateWithCas(callable $mutator, string $opLabel): void
    {
        for ($attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++) {
            $envelope      = $this->loadEnvelope();
            $beforeVersion = $envelope['version'];
            $kids          = $envelope['kids'];

            $mutator($kids);

            // Re-read to detect a concurrent writer between load + write.
            $reread = $this->loadEnvelope();
            if ($reread['version'] !== $beforeVersion) {
                SecurityLogger::log(SecurityLogger::EVENT_KEYSTORE_RACE, [
                    'op'               => $opLabel,
                    'attempt'          => $attempt + 1,
                    'before_version'   => $beforeVersion,
                    'observed_version' => $reread['version'],
                ]);
                continue;
            }

            $newEnvelope = ['version' => $beforeVersion + 1, 'kids' => $kids];
            $this->store->set(
                self::OPTION_KEY,
                (string) \json_encode($newEnvelope, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            );
            return;
        }

        SecurityLogger::log(SecurityLogger::EVENT_KEYSTORE_RACE, [
            'op'      => $opLabel,
            'outcome' => 'exhausted_retries',
        ]);
        throw new \RuntimeException(\sprintf(
            'JoomlaKeyStore::%s exhausted %d CAS retries — concurrent writer storm.',
            $opLabel,
            self::MAX_CAS_RETRIES
        ));
    }
}

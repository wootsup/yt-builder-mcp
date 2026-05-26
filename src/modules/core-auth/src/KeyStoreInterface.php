<?php
/**
 * Cross-platform abstraction over the Bearer-token keystore.
 *
 * Both the WordPress {@see KeyStore} (wp_option-backed) and the Joomla
 * {@see \WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore}
 * (#__ytb_mcp_options-backed) satisfy this contract. Consumers
 * ({@see BearerVerifier}, settings UIs) type-hint against the interface
 * so the same domain logic runs on both platforms unchanged.
 *
 * Wave 2 (Joomla port) extraction — the interface preserves the
 * versioned-envelope shape originally defined by KeyStore so that
 * existing call-sites continue to work without behavioural change.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

interface KeyStoreInterface
{
    /**
     * Persist a new kid + its metadata. Overwrites any existing entry
     * with the same kid. Implementations MUST use a race-safe
     * read-modify-write primitive (WP: add_option CAS; Joomla: INSERT
     * IGNORE / SELECT-FOR-UPDATE).
     *
     * @param array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * } $metadata
     */
    public function register(string $kid, array $metadata): void;

    /**
     * Mark the given kid as revoked. No-op if the kid is unknown.
     */
    public function revoke(string $kid): void;

    /**
     * @return array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * }|null
     */
    public function find(string $kid): ?array;

    /**
     * @return array<string, array{
     *   label: string,
     *   scope: string,
     *   created_at: int,
     *   expires_at: int|null,
     *   revoked_at: int|null,
     * }>
     */
    public function list(): array;
}

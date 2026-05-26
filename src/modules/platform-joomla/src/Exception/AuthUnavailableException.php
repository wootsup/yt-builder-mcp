<?php
/**
 * Thrown when the auth stack cannot be made ready for a request — most
 * importantly when the HMAC signing secret could NOT be persisted to the
 * options table.
 *
 * Background (R8-A4 P1): {@see JoomlaSigningSecret::ensure()} previously
 * ignored the `add()`/`set()` persistence boolean and returned the
 * in-memory secret regardless. If the option write silently failed
 * (disk-full, row-lock, a future driver regression of the
 * bind-on-driver class), KeyService would sign tokens with a secret that
 * never reached the DB → every subsequent request reads a DIFFERENT
 * freshly-generated secret → all Bearer verification fails, with NO error
 * surfaced at write time. Surfacing this as a typed exception lets the
 * REST layer return a structured 503 instead of issuing tokens that can
 * never be verified.
 *
 * REST infrastructure maps this to HTTP 503 with a structured
 * `{code:"yootheme_builder_mcp.auth.unavailable", …}` envelope
 * (see {@see AbstractApiController::dispatch()}).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Exception
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Exception;

defined('_JEXEC') or die;

final class AuthUnavailableException extends \RuntimeException
{
    public function __construct(
        string $reason = '',
        public readonly string $remediation = 'The signing secret could not be persisted. Check the database is writable and the #__ytb_mcp_options table exists, then retry.'
    ) {
        parent::__construct(
            $reason === ''
                ? 'The authentication subsystem is temporarily unavailable (signing secret could not be persisted).'
                : $reason
        );
    }
}

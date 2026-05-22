<?php
/**
 * Thrown when a Bearer-token's `kid` is present in the KeyStore but has been
 * marked as revoked.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

final class RevokedTokenException extends InvalidTokenException
{
}

<?php
/**
 * Thrown when a Bearer-token has passed its `exp` claim.
 *
 * Sub-class of {@see InvalidTokenException} so callers that catch the broader
 * exception still handle expiration uniformly, while callers that care about
 * "expired vs invalid signature" can distinguish.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

final class ExpiredTokenException extends InvalidTokenException
{
}

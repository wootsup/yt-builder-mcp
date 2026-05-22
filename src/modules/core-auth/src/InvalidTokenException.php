<?php
/**
 * Thrown when a Bearer-token's format or signature is invalid.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

class InvalidTokenException extends \RuntimeException
{
}

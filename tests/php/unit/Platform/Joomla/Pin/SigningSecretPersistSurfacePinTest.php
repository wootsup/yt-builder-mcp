<?php
/**
 * REGRESSION PIN-TEST (R8-A4 P1): JoomlaSigningSecret::ensure() must SURFACE
 * a persistence failure, not return a phantom in-memory secret.
 *
 * Pre-fix: ensure() ignored the add()/set() boolean and `return $secret`
 * regardless. If the option write silently failed (disk-full, row-lock, a
 * future regression of the bind-on-driver class the deploy-delta fixed),
 * KeyService would sign tokens with a secret that never reached the DB → the
 * next request reads a DIFFERENT freshly-generated secret → every Bearer
 * verification fails, with NO error surfaced at write time.
 *
 * Fix: when add() misses AND the re-read misses AND the fallback set() also
 * fails, log EVENT_WRITE_FAILED + throw AuthUnavailableException so the REST
 * layer emits a structured 503 instead of issuing un-verifiable tokens.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret;
use WootsUp\BuilderMcp\Platform\Joomla\Exception\AuthUnavailableException;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;

#[CoversClass(JoomlaSigningSecret::class)]
#[CoversClass(AuthUnavailableException::class)]
final class SigningSecretPersistSurfacePinTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
        if (!\defined('YTB_MCP_ENCRYPTION_KEY')) {
            \define('YTB_MCP_ENCRYPTION_KEY', 'ytb-mcp-test-suite-encryption-key-deterministic-do-not-use');
        }
    }

    /**
     * Make the REAL JoomlaOptionStore (it is `final`, so no subclass) never
     * persist: a driver that throws on every operation. add() executes → the
     * driver throws → add()'s catch returns false; the re-read get() throws →
     * get()'s catch returns null (miss); the fallback set() executes → throws
     * → set()'s catch returns false. That is the exact "write silently
     * refused" mode that must now surface instead of returning a phantom
     * secret.
     */
    private function installFailingDriver(): void
    {
        \MockJoomlaDatabase::$throwException = true;
    }

    public function test_ensure_throws_when_persistence_fails(): void
    {
        $this->installFailingDriver();
        $this->expectException(AuthUnavailableException::class);
        JoomlaSigningSecret::ensure(new JoomlaOptionStore());
    }

    public function test_ensure_does_not_return_a_phantom_secret_on_persist_failure(): void
    {
        $this->installFailingDriver();
        $returned = null;
        try {
            $returned = JoomlaSigningSecret::ensure(new JoomlaOptionStore());
        } catch (AuthUnavailableException $e) {
            self::assertStringContainsString('persist', \strtolower($e->getMessage()));
            self::assertNotSame('', $e->remediation);
            return;
        }
        self::fail('ensure() returned a phantom secret (' . \var_export($returned, true) . ') instead of surfacing the persist failure.');
    }

    /**
     * Sanity: when the store DOES persist (the happy path), ensure() still
     * returns a real secret and does NOT throw — the guard only fires on
     * genuine write failure.
     */
    public function test_ensure_succeeds_normally_when_store_persists(): void
    {
        $secret = JoomlaSigningSecret::ensure(); // real in-memory mock DB
        self::assertSame(128, \strlen($secret));
        self::assertSame($secret, JoomlaSigningSecret::get());
    }
}

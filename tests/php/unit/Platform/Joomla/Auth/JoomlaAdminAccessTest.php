<?php
/**
 * JoomlaAdminAccess — admin capability-gate behaviour.
 *
 * Wave-9 T3 (#6/#7): the com_ytbmcp admin dashboard + its mutating key
 * tasks (generateKey / revokeKey) must be reachable ONLY by a user with
 * `core.admin` (or `core.manage`) on `com_ytbmcp` — the Joomla parity-twin
 * of WP's `manage_options` gate on
 * {@see \WootsUp\BuilderMcp\Platform\WordPress\SettingsPage::render}.
 *
 * The gate logic is extracted into {@see JoomlaAdminAccess} so both the
 * controller (tasks) and the View (render) delegate to one choke-point,
 * and so the deny path is unit-testable WITHOUT spinning up the
 * MVCFactory-autoloaded controller. The tests below are the RED proof:
 *
 *   - a non-authorised identity (authorise() → false) is DENIED, and
 *   - the deny happens BEFORE any key is minted (no JoomlaKeyStore write).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Auth;

use Joomla\CMS\Access\Exception\NotAllowed;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaAdminAccess;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;

final class JoomlaAdminAccessTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
    }

    /**
     * Build a fake Joomla identity whose authorise() answers from a map of
     * action → bool, defaulting to false for any unlisted action.
     *
     * @param array<string, bool> $grants
     */
    private function identity(array $grants): object
    {
        return new class ($grants) {
            /** @param array<string, bool> $grants */
            public function __construct(private array $grants)
            {
            }

            public function authorise(string $action, ?string $assetname = null): bool
            {
                return $this->grants[$action] ?? false;
            }
        };
    }

    // --- isAllowed() ------------------------------------------------------

    public function test_denies_identity_without_admin_or_manage(): void
    {
        $identity = $this->identity(['core.login.admin' => true]); // logged in, but no component grant
        self::assertFalse(
            JoomlaAdminAccess::isAllowed($identity),
            'An identity lacking core.admin AND core.manage on com_ytbmcp must be denied.'
        );
    }

    public function test_denies_null_identity(): void
    {
        self::assertFalse(
            JoomlaAdminAccess::isAllowed(null),
            'A null identity (no logged-in user) must be denied.'
        );
    }

    public function test_allows_core_admin(): void
    {
        $identity = $this->identity(['core.admin' => true]);
        self::assertTrue(JoomlaAdminAccess::isAllowed($identity));
    }

    public function test_allows_core_manage_without_core_admin(): void
    {
        $identity = $this->identity(['core.manage' => true]);
        self::assertTrue(
            JoomlaAdminAccess::isAllowed($identity),
            'core.manage alone is sufficient (manager-level access to the component).'
        );
    }

    public function test_authorise_is_scoped_to_the_component_asset(): void
    {
        $captured = [];
        $identity = new class ($captured) {
            /** @param array<int, array{action: string, asset: ?string}> $captured */
            public function __construct(public array &$captured)
            {
            }

            public function authorise(string $action, ?string $assetname = null): bool
            {
                $this->captured[] = ['action' => $action, 'asset' => $assetname];
                return false;
            }
        };

        JoomlaAdminAccess::isAllowed($identity);

        self::assertNotSame([], $identity->captured, 'authorise() must be consulted.');
        foreach ($identity->captured as $call) {
            self::assertSame(
                'com_ytbmcp',
                $call['asset'],
                'Every capability probe must be scoped to the com_ytbmcp component asset, not site-global.'
            );
        }
    }

    // --- assert() ---------------------------------------------------------

    public function test_assert_throws_notallowed_for_denied_identity(): void
    {
        $this->expectException(NotAllowed::class);
        $this->expectExceptionCode(403);

        JoomlaAdminAccess::assert($this->identity([]));
    }

    public function test_assert_is_silent_for_allowed_identity(): void
    {
        JoomlaAdminAccess::assert($this->identity(['core.admin' => true]));
        $this->addToAssertionCount(1); // reaching here = no throw
    }

    // --- deny-path: no key minted -----------------------------------------

    /**
     * The whole point of the gate: a denied caller never reaches the
     * key-minting code. We simulate the controller's task ordering
     * (assert FIRST, then register) and prove the KeyStore stays empty.
     */
    public function test_denied_caller_never_mints_a_key(): void
    {
        $store = new JoomlaKeyStore();
        self::assertSame([], $store->list(), 'Pre-condition: no keys yet.');

        $minted = false;
        try {
            // Mirror DashboardController::generateKey() ordering: gate first.
            JoomlaAdminAccess::assert($this->identity([]));

            // Unreachable for a denied identity — but if the gate regressed
            // this would mint a key and the assertion below would catch it.
            $store->register('deadbeefdeadbeef', [
                'label'      => 'should-never-exist',
                'scope'      => 'read',
                'created_at' => \time(),
                'expires_at' => null,
                'revoked_at' => null,
            ]);
            $minted = true;
        } catch (NotAllowed) {
            // Expected: gate slammed shut before any mint.
        }

        self::assertFalse($minted, 'A denied caller must not reach the key-mint code.');
        self::assertSame([], $store->list(), 'KeyStore must stay empty after a denied generate attempt.');
    }
}

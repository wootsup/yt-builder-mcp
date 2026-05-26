<?php
/**
 * PIN-TEST: L2 controllers MUST use Bearer write-scope as the sole
 * authority — the per-article Joomla `core.edit` ACL gate that lived
 * in {@see ArticlesController::assertArticleAcl} +
 * {@see ArticleElementsController::assertArticleAcl} MUST stay removed.
 *
 * Round-6 A2 N-A2-001 architectural decision (Option A drop).
 * Cookbook §2.2.4 Bearer-Deny-Invariant + ADR-001 session-strip.
 *
 * Why this pin is load-bearing:
 *
 * The pre-R6 controllers tried to layer `Factory::getUser()->authorise(
 * 'core.edit', "com_content.article.{$id}")` ON TOP of Bearer scope. But
 * `Ytbmcp::onStripApiSession` (system plugin, priority 1) deliberately
 * drops Joomla user identity for every yt-builder-mcp API request, so
 * `Factory::getUser()` always returned Guest and `authorise(...)` was
 * always false. Every L2 write returned `403 acl_denied` even with a
 * valid admin-scope Bearer — a structurally always-deny gate that
 * disguised itself as defence-in-depth.
 *
 * A future innocent-looking refactor ("let me restore the ACL gate so
 * Joomla users can revoke per-article access") would either:
 *   (a) re-introduce the same always-deny bug, or
 *   (b) require loading Joomla identity AFTER session-strip — re-opening
 *       the cookie-bypass surface that ADR-001 was built to close.
 *
 * This pin fails loudly at PHPUnit-collection time if any of the
 * forbidden substrings reappear in the L2 controller source files, so
 * neither regression can ship.
 *
 * See `docs/adr/2026-05-24-l2-bearer-as-authority.md` for the full
 * rationale + the path back to per-article ACL if customer demand
 * emerges (requires Bearer claim extension, NOT Factory::getUser()).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class L2BearerAuthorityPinTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../../../..';

    /**
     * Strip every PHP comment (single-line, multi-line, doc) from the
     * source so the pin tests only inspect executable code. The
     * rationale-bearing docblock + inline comments we WANT to keep
     * (they document the removal and back-reference the ADR) would
     * otherwise trip the forbidden-substring checks below.
     */
    private static function stripComments(string $src): string
    {
        $stripped = '';
        if (!\defined('T_OPEN_TAG')) {
            return $src; // PHP tokenizer not available — degrade to raw
        }
        foreach (\token_get_all($src) as $token) {
            if (\is_array($token)) {
                [$id, $text] = $token;
                if ($id === \T_COMMENT || $id === \T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $text;
            } else {
                $stripped .= $token;
            }
        }
        return $stripped;
    }

    private const ARTICLES_CONTROLLER =
        self::REPO_ROOT
        . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/ArticlesController.php';

    private const ARTICLE_ELEMENTS_CONTROLLER =
        self::REPO_ROOT
        . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/ArticleElementsController.php';

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function controllerProvider(): iterable
    {
        yield 'ArticlesController'        => ['ArticlesController',        self::ARTICLES_CONTROLLER];
        yield 'ArticleElementsController' => ['ArticleElementsController', self::ARTICLE_ELEMENTS_CONTROLLER];
    }

    #[DataProvider('controllerProvider')]
    public function test_controller_source_exists(string $name, string $path): void
    {
        self::assertFileExists(
            $path,
            "L2 controller {$name} must exist at the canonical path."
        );
    }

    /**
     * The private helper that bound L2 writes to a per-article Joomla ACL
     * MUST stay removed — its presence is structurally always-deny per the
     * top-of-file rationale.
     *
     * @param string $name controller class short-name (for failure messages)
     * @param string $path absolute path to the controller source file
     */
    #[DataProvider('controllerProvider')]
    public function test_controller_does_not_define_assert_article_acl(string $name, string $path): void
    {
        $code = self::stripComments((string) \file_get_contents($path));
        self::assertStringNotContainsString(
            'assertArticleAcl',
            $code,
            "{$name} MUST NOT contain executable `assertArticleAcl` — the helper was removed in Round-6 (cookbook §2.2.4)."
        );
    }

    /**
     * The Joomla user-permission API MUST NOT be invoked from L2
     * controllers. Calling `\$user->authorise(...)` after session-strip
     * is always-deny; calling it before session-strip would require
     * undoing ADR-001 and re-opening the cookie-bypass surface.
     *
     * @param string $name controller class short-name
     * @param string $path absolute controller source path
     */
    #[DataProvider('controllerProvider')]
    public function test_controller_does_not_call_authorise(string $name, string $path): void
    {
        $code = self::stripComments((string) \file_get_contents($path));
        self::assertStringNotContainsString(
            '$user->authorise(',
            $code,
            "{$name} MUST NOT call \$user->authorise() in executable code — session-strip (ADR-001) makes Factory::getUser-based ACL always-deny."
        );
    }

    /**
     * Joomla user-identity loading MUST NOT be reintroduced into L2
     * controllers. The session-strip listener in `Ytbmcp.php` deliberately
     * removes the cookie-bound user; loading it back here would re-enable
     * the cookie-bypass surface ADR-001 closed.
     *
     * @param string $name controller class short-name
     * @param string $path absolute controller source path
     */
    #[DataProvider('controllerProvider')]
    public function test_controller_does_not_load_joomla_user_identity(string $name, string $path): void
    {
        $code = self::stripComments((string) \file_get_contents($path));
        self::assertStringNotContainsString(
            'Factory::getUser()',
            $code,
            "{$name} MUST NOT call Factory::getUser() in executable code — session-strip (ADR-001) deliberately drops user identity."
        );
    }

    /**
     * Positive assertion: the docblock MUST explain that Bearer scope is
     * the sole authority. This is the canonical rationale — a future
     * refactor that drops the comment without restoring the gate would
     * lose the architectural decision rationale.
     *
     * @param string $name controller class short-name
     * @param string $path absolute controller source path
     */
    #[DataProvider('controllerProvider')]
    public function test_controller_documents_bearer_as_sole_authority(string $name, string $path): void
    {
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString(
            'Bearer',
            $src,
            "{$name} MUST document Bearer as the L2 authority — see ADR `docs/adr/2026-05-24-l2-bearer-as-authority.md`."
        );
        self::assertStringContainsString(
            'authoritative',
            $src,
            "{$name} MUST contain the rationale comment ('Bearer write-scope is authoritative') so the decision survives future grep-driven refactors."
        );
    }

    /**
     * Cross-reference pin: the ADR file documenting the decision MUST
     * exist on disk. A drift-detection guard: deleting the ADR while
     * keeping the controllers in their R6 state breaks the audit chain.
     */
    public function test_adr_documents_the_decision(): void
    {
        $adr = self::REPO_ROOT . '/docs/adr/2026-05-24-l2-bearer-as-authority.md';
        self::assertFileExists(
            $adr,
            'ADR `2026-05-24-l2-bearer-as-authority.md` MUST exist — it carries the architectural decision rationale referenced by both L2 controller docblocks.'
        );
    }
}

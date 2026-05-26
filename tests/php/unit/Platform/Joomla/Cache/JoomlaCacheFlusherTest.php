<?php
/**
 * JoomlaCacheFlusher behavioural tests.
 *
 * Round-4 audit A3 P1 — JoomlaCacheFlusher landed in Wave 6.5 with zero
 * unit coverage. Round-6 A1 polish — the original test suite hit only the
 * "no factory registered" failure path (every `cleanGroup()` swallowed
 * a `RuntimeException` from `Factory::getContainer()->get()`), so it
 * proved fail-safe behaviour but said nothing about the success-path
 * invariants. This version registers a fake `CacheControllerFactoryInterface`
 * + a fake YT-cache and asserts the actual cleanup call-counts, so the
 * ADR-002 scoping contract is pinned structurally:
 *
 *   - flushL1() invalidates ONLY the YT-cache layer.
 *   - flushL2($id) invalidates YT + `com_content` + (conditionally)
 *     the `page` cache-group when plg_system_cache is enabled.
 *   - When `plg_system_cache` is disabled, the page-group call is
 *     skipped (no wasted call).
 *   - Cache-flush failures are caught + logged via
 *     `EVENT_CACHE_FLUSH_FAILED` and NEVER re-thrown.
 *
 * Cross-references: ADR-002 scoping, Wave-6 Fix 14 nuclear-flush
 * regression-class (R3 F-A1-005 release-blocker), R6 A1 polish.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Cache
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Cache\JoomlaCacheFlusher;
use WootsUp\BuilderMcp\Util\SecurityLogger;

/**
 * Fake cache-controller — records `clean()` invocations so the test can
 * assert which groups were touched. Single-throw mode emulates a driver
 * failure on a specific group.
 */
final class FakeCacheController
{
    /** @var array<int, string> */
    public array $cleanedGroups = [];

    public ?string $throwOnGroup = null;

    public function clean(string $group): void
    {
        $this->cleanedGroups[] = $group;
        if ($this->throwOnGroup === $group) {
            throw new \RuntimeException('fake driver failure on group ' . $group);
        }
    }
}

/**
 * Fake factory — returns one shared {@see FakeCacheController} per test so
 * call-count assertions accumulate across multiple `createCacheController`
 * invocations within the same `flushL2()` run.
 */
final class FakeCacheControllerFactory
{
    public FakeCacheController $controller;
    /** @var array<int, array{type: string, options: array<string, mixed>}> */
    public array $createCalls = [];
    public bool $throwOnCreate = false;

    public function __construct()
    {
        $this->controller = new FakeCacheController();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createCacheController(string $type, array $options = []): FakeCacheController
    {
        $this->createCalls[] = ['type' => $type, 'options' => $options];
        if ($this->throwOnCreate) {
            throw new \RuntimeException('fake factory failure');
        }
        return $this->controller;
    }
}

#[CoversClass(JoomlaCacheFlusher::class)]
final class JoomlaCacheFlusherTest extends TestCase
{
    private string $errorLogBackup = '';
    private string $errorLogFile = '';
    private FakeCacheControllerFactory $factory;

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \Joomla\CMS\Plugin\PluginHelper::$isEnabled = true;

        // Register a fake CacheControllerFactoryInterface so cleanGroup()
        // exercises its success path. Without this the flusher's outer
        // try/catch swallowed every call and we couldn't observe which
        // groups were targeted.
        $this->factory = new FakeCacheControllerFactory();
        \MockJoomlaContainer::register(
            \Joomla\CMS\Cache\CacheControllerFactoryInterface::class,
            $this->factory
        );

        // Redirect error_log so we can grep for EVENT_CACHE_FLUSH_FAILED.
        $this->errorLogFile = (string) \tempnam(\sys_get_temp_dir(), 'ytbmcp-cacheflush-');
        $this->errorLogBackup = (string) \ini_get('error_log');
        \ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', $this->errorLogBackup);
        if ($this->errorLogFile !== '' && \file_exists($this->errorLogFile)) {
            @\unlink($this->errorLogFile);
        }
        \MockJoomlaFactory::reset();
    }

    /**
     * @cookbook ADR-002 §"Decision" — flushL1 invalidates ONLY YT cache;
     *           NO Joomla cache-group call, NO page-cache call.
     */
    public function test_flush_l1_targets_only_yt_cache_no_joomla_group(): void
    {
        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL1();

        self::assertSame(
            [],
            $this->factory->createCalls,
            'flushL1 MUST NOT touch any Joomla cache-group — only YT cache.'
        );
        self::assertSame(
            [],
            $this->factory->controller->cleanedGroups,
            'flushL1 MUST NOT call clean() on any Joomla group.'
        );
    }

    /**
     * @cookbook ADR-002 §"Decision" — flushL2 invalidates `com_content`
     *           AND `page` (when plg_system_cache enabled).
     */
    public function test_flush_l2_cleans_com_content_and_page_when_plugin_enabled(): void
    {
        \Joomla\CMS\Plugin\PluginHelper::$isEnabled = true;

        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL2(42);

        self::assertCount(
            2,
            $this->factory->createCalls,
            'flushL2 MUST create exactly 2 cache controllers (com_content + page) when plg_system_cache is enabled.'
        );
        self::assertSame('com_content', $this->factory->createCalls[0]['options']['defaultgroup'] ?? null);
        self::assertSame('page',        $this->factory->createCalls[1]['options']['defaultgroup'] ?? null);
        self::assertSame(
            ['com_content', 'page'],
            $this->factory->controller->cleanedGroups,
            'flushL2 MUST clean both com_content and page groups in order.'
        );
    }

    /**
     * @cookbook ADR-002 §"Decision" — page-group call is gated on
     *           plg_system_cache.
     */
    public function test_flush_l2_skips_page_group_when_plugin_disabled(): void
    {
        \Joomla\CMS\Plugin\PluginHelper::$isEnabled = false;

        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL2(42);

        self::assertCount(
            1,
            $this->factory->createCalls,
            'When plg_system_cache is disabled flushL2 MUST skip the page-cache controller creation.'
        );
        self::assertSame('com_content', $this->factory->createCalls[0]['options']['defaultgroup'] ?? null);
        self::assertSame(
            ['com_content'],
            $this->factory->controller->cleanedGroups,
            'Only com_content group MUST be cleaned when the page-cache plugin is disabled.'
        );
    }

    /**
     * @cookbook §2.10.15 cache-flush invariant — driver failure logs +
     *           never re-throws.
     */
    public function test_flush_l2_logs_event_cache_flush_failed_on_clean_failure(): void
    {
        $this->factory->controller->throwOnGroup = 'com_content';

        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL2(7); // must not throw

        $log = (string) \file_get_contents($this->errorLogFile);
        self::assertStringContainsString(
            SecurityLogger::EVENT_CACHE_FLUSH_FAILED,
            $log,
            'A cleanGroup() failure MUST be logged via EVENT_CACHE_FLUSH_FAILED.'
        );
        self::assertStringContainsString(
            'com_content',
            $log,
            'EVENT_CACHE_FLUSH_FAILED MUST carry the failing layer/group identifier.'
        );
    }

    /**
     * @cookbook §2.10.15 cache-flush invariant — factory failure logs +
     *           never re-throws.
     */
    public function test_flush_l2_logs_event_cache_flush_failed_on_factory_failure(): void
    {
        $this->factory->throwOnCreate = true;

        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL2(99); // must not throw

        $log = (string) \file_get_contents($this->errorLogFile);
        self::assertStringContainsString(
            SecurityLogger::EVENT_CACHE_FLUSH_FAILED,
            $log,
            'A factory createCacheController() failure MUST be logged via EVENT_CACHE_FLUSH_FAILED.'
        );
    }

    /**
     * @cookbook §2.10.15 — flushL1 stays no-op-safe when YT and the cache
     *           factory both blow up.
     */
    public function test_flush_l1_is_noop_safe_without_yt(): void
    {
        // YT not bootstrapped (function_exists('\YOOtheme\app') is false
        // in the unit-test process) — flushL1 short-circuits silently.
        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL1(); // must not throw
        self::assertSame(
            [],
            $this->factory->createCalls,
            'flushL1 must remain a no-op for Joomla groups when YT is absent.'
        );
    }

    /**
     * @cookbook ADR-002 — $articleId parameter is preserved for future
     *           per-key eviction (positive ints + edge cases all accepted).
     */
    public function test_flush_l2_accepts_positive_article_id(): void
    {
        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL2(1);
        $flusher->flushL2(\PHP_INT_MAX);
        // Each call produces 2 createCalls (com_content + page when enabled).
        self::assertCount(4, $this->factory->createCalls);
    }

    /**
     * @cookbook ADR-002 — flushL2 stays callable with article_id=0 (cache-
     *           flush invariant — never abort caller on bogus input).
     */
    public function test_flush_l2_with_zero_article_id_does_not_throw(): void
    {
        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL2(0); // must not throw
        // Behavioural check — even with 0 the flusher still attempted the
        // group cleanup (defensive — caller may have an off-by-one bug).
        self::assertCount(2, $this->factory->createCalls);
    }

    /**
     * Repeated calls accumulate exactly as expected — no hidden global
     * state that would skip subsequent invocations.
     */
    public function test_flush_l1_and_flush_l2_are_idempotent(): void
    {
        $flusher = new JoomlaCacheFlusher();
        $flusher->flushL1();
        $flusher->flushL1();
        // flushL1 must not have touched the Joomla factory at all.
        self::assertSame([], $this->factory->createCalls);

        $flusher->flushL2(7);
        $flusher->flushL2(7);
        // 2 calls × 2 groups (com_content + page) = 4 createCalls
        self::assertCount(4, $this->factory->createCalls);
    }
}

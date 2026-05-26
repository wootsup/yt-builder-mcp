<?php
/**
 * W11-T4 — reliable admin-context YOOtheme Pro detection.
 *
 * The Joomla admin Dashboard ({@see HtmlView}) used to derive `$ytPresent`
 * from a RUNTIME bootstrap of YOOtheme Pro (`YtBootstrapper::ensure()` +
 * `YoothemeAdapter::getVersion()`). But YT Pro only lazy-bootstraps on the
 * REST/API + frontend surface (ADR-001 — com_api's template_bootstrap
 * allowlist excludes the administrator application; YT is the SITE template,
 * never loaded in the admin app). So in the admin component context the
 * bootstrap could never load YT and `getVersion()` returned null EVEN WHEN
 * YT Pro was installed + active → false "YOOtheme Pro required" notice +
 * Diagnostics "—".
 *
 * The fix: detect presence + version via the Joomla framework's own
 * `#__extensions` table (mirrors how api-mapper's
 * `ApiMapper\Core\Compat\YOOthemeCompat` / `CompatibilityChecker` read the
 * Joomla template manifest), with a `templateDetails.xml` fallback. No
 * runtime YT load required — works in the admin app.
 *
 * RED-WITHOUT-FIX: {@see JoomlaYoothemeProbe} does not exist yet, so this
 * test fatals on the missing class. GREEN once the probe queries
 * `#__extensions` for the `yootheme` template row and parses `manifest_cache`.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Yootheme
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Yootheme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Yootheme\JoomlaYoothemeProbe;

#[CoversClass(JoomlaYoothemeProbe::class)]
final class JoomlaYoothemeProbeTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
        // Point the templateDetails.xml fallback at a non-existent path so
        // the #__extensions table is the only detection surface under test.
        // (The bootstrap defines JPATH_SITE under sys_get_temp_dir(); no
        // yootheme template file exists there.)
        $this->removeFallbackManifest();
    }

    protected function tearDown(): void
    {
        $this->removeFallbackManifest();
        \MockJoomlaFactory::reset();
    }

    /**
     * YT Pro template row present in #__extensions (version 4.5.x in the
     * manifest_cache) → detectVersion() surfaces the version, isPresent()
     * is true.
     */
    public function test_detects_version_from_extensions_manifest_cache(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = \json_encode([
            'name' => 'YOOtheme',
            'type' => 'template',
            'version' => '4.5.33',
        ]);

        $probe = new JoomlaYoothemeProbe();

        self::assertSame('4.5.33', $probe->detectVersion());
        self::assertTrue($probe->isPresent());
    }

    /**
     * No yootheme template row → loadResult() returns null → detectVersion()
     * is null, isPresent() is false (and there is no fallback manifest on
     * disk in the test JPATH). Drives the "YOOtheme Pro required" notice.
     */
    public function test_absent_when_no_extension_row_and_no_manifest(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = null;

        $probe = new JoomlaYoothemeProbe();

        self::assertNull($probe->detectVersion());
        self::assertFalse($probe->isPresent());
    }

    /**
     * Falls back to templateDetails.xml <version> when #__extensions yields
     * no manifest_cache (e.g. an older install whose manifest_cache was
     * never populated). Mirrors api-mapper's CompatibilityChecker fallback.
     */
    public function test_falls_back_to_template_details_xml_version(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = null; // extensions row absent

        $manifest = $this->writeFallbackManifest('4.7.0');
        self::assertFileExists($manifest);

        $probe = new JoomlaYoothemeProbe();

        self::assertSame('4.7.0', $probe->detectVersion());
        self::assertTrue($probe->isPresent());
    }

    /**
     * A DB error must NOT fatal the admin page — the probe degrades to the
     * fallback (none here) and reports "absent" rather than throwing.
     */
    public function test_db_failure_degrades_to_absent(): void
    {
        \MockJoomlaDatabase::$throwException = true;

        $probe = new JoomlaYoothemeProbe();

        self::assertNull($probe->detectVersion());
        self::assertFalse($probe->isPresent());
    }

    /**
     * A3-M1 (a): a corrupt `manifest_cache` that is NOT valid JSON (e.g. a
     * half-written row, a BOM-prefixed blob, or a server that truncated the
     * column) makes `json_decode` return a NON-array — the shared detector
     * must degrade to null (no extensions hit), NOT fatal and NOT misread.
     * With no on-disk fallback manifest, the probe reports "absent".
     *
     * Behavioural: exercises ytbmcp_shared_yt_version_from_extensions() through
     * the public probe façade, with the corruption injected at the DB layer.
     */
    public function test_non_json_manifest_cache_degrades_to_absent(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        // Not JSON → json_decode() returns null (a non-array) → branch to null.
        \MockJoomlaDatabase::$loadResultOverride = 'this-is-not-json{{';

        $probe = new JoomlaYoothemeProbe();

        self::assertNull(
            $probe->detectVersion(),
            'A non-JSON manifest_cache must not be misread as a version.'
        );
        self::assertFalse($probe->isPresent());
    }

    /**
     * A3-M1 (b): a `manifest_cache` that IS a valid JSON object but carries no
     * `version` key (older Joomla wrote a sparse cache; some installs store
     * only {name,type}). The detector must return null rather than emit an
     * empty/garbage version — and must NOT throw on the missing key.
     */
    public function test_json_manifest_cache_without_version_key_degrades_to_absent(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = \json_encode([
            'name' => 'YOOtheme',
            'type' => 'template',
            // no 'version' key on purpose
        ]);

        $probe = new JoomlaYoothemeProbe();

        self::assertNull(
            $probe->detectVersion(),
            'A manifest_cache object missing the version key must yield null.'
        );
        self::assertFalse($probe->isPresent());
    }

    /**
     * A3-M1 (c): an EMPTY `manifest_cache` ('') — the column exists but was
     * never populated (a very common older-install shape). The extensions
     * branch returns null, and detection must then FALL BACK to the on-disk
     * `templateDetails.xml` manifest and surface its <version>. This pins the
     * exact "empty cache → manifest fallback wins" path that older installs
     * depend on (otherwise YT shows as absent on a perfectly valid install).
     */
    public function test_empty_manifest_cache_falls_back_to_template_details_xml(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = ''; // column present but empty

        $manifest = $this->writeFallbackManifest('4.6.2');
        self::assertFileExists($manifest);

        $probe = new JoomlaYoothemeProbe();

        self::assertSame(
            '4.6.2',
            $probe->detectVersion(),
            'An empty manifest_cache must fall back to the templateDetails.xml version.'
        );
        self::assertTrue($probe->isPresent());
    }

    // --- helpers ----------------------------------------------------------

    private function fallbackManifestPath(): string
    {
        return JPATH_SITE . '/templates/yootheme/templateDetails.xml';
    }

    private function writeFallbackManifest(string $version): string
    {
        $path = $this->fallbackManifestPath();
        @\mkdir(\dirname($path), 0777, true);
        \file_put_contents(
            $path,
            '<?xml version="1.0"?><extension type="template"><version>' . $version . '</version></extension>'
        );
        return $path;
    }

    private function removeFallbackManifest(): void
    {
        $path = $this->fallbackManifestPath();
        if (\is_file($path)) {
            @\unlink($path);
        }
    }
}

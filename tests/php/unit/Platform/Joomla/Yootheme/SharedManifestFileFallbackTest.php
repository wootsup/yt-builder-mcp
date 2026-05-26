<?php
/**
 * A3-L4 — direct behavioural coverage of the SSoT manifest-file fallback
 * `ytbmcp_shared_yt_version_from_manifest_file()` (Audit A1-M1 extracted this
 * out of the probe into the dependency-free shared file
 * `platform-joomla/src/Shared/ytbmcp-joomla-detect.php`, 2026-05-25).
 *
 * The probe-façade test ({@see JoomlaYoothemeProbeTest}) covers the happy
 * fallback (valid manifest → version) and the empty-cache → fallback path.
 * This file pins the two EDGE shapes that previously had no coverage and are
 * pure fail-safety invariants:
 *
 *   (1) the manifest exists but is UNPARSEABLE XML — `simplexml_load_file()`
 *       returns false. The function must degrade to null, NOT warn-and-fatal.
 *   (2) the manifest parses but its `<version>` element is empty or
 *       whitespace-only — must yield null, never an empty-string "version".
 *
 * We call the shared function directly (it ships as plain dependency-free PHP
 * required by computed path) and drive it through the real on-disk
 * `JPATH_SITE/templates/yootheme/templateDetails.xml` location it reads.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Yootheme
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Yootheme;

use PHPUnit\Framework\TestCase;

final class SharedManifestFileFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        // The shared functions live in the dependency-free SSoT file. It is
        // already required transitively by the probe class, but require it
        // explicitly so this test stands alone (the guard inside the file
        // makes a double-include a no-op).
        require_once \dirname(__DIR__, 6)
            . '/src/modules/platform-joomla/src/Shared/ytbmcp-joomla-detect.php';

        $this->removeManifest();
    }

    protected function tearDown(): void
    {
        $this->removeManifest();
    }

    private function manifestPath(): string
    {
        return JPATH_SITE . '/templates/yootheme/templateDetails.xml';
    }

    private function writeManifest(string $contents): string
    {
        $path = $this->manifestPath();
        @\mkdir(\dirname($path), 0o777, true);
        \file_put_contents($path, $contents);
        return $path;
    }

    private function removeManifest(): void
    {
        $path = $this->manifestPath();
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    /**
     * Sanity: a well-formed manifest surfaces the version (this is the
     * baseline the two edge-cases below diverge from).
     */
    public function test_well_formed_manifest_yields_version(): void
    {
        $this->writeManifest(
            '<?xml version="1.0"?><extension type="template"><version>4.5.33</version></extension>'
        );

        self::assertSame('4.5.33', \ytbmcp_shared_yt_version_from_manifest_file());
    }

    /**
     * A3-L4 (1): the file exists but is malformed XML so
     * `simplexml_load_file()` returns false. Must degrade to null without
     * throwing or emitting a warning that bubbles out.
     */
    public function test_unparseable_manifest_xml_yields_null(): void
    {
        // Unterminated tag → libxml parse error → simplexml_load_file() === false.
        $this->writeManifest('<?xml version="1.0"?><extension><version>4.5.0</extension');

        self::assertNull(
            \ytbmcp_shared_yt_version_from_manifest_file(),
            'A manifest whose XML does not parse must yield null (fail-safe).'
        );
    }

    /**
     * A3-L4 (2a): the manifest parses but `<version>` is empty. Must yield
     * null — an empty string is NOT a usable version and would let a YT-less
     * site read as "present".
     */
    public function test_empty_version_element_yields_null(): void
    {
        $this->writeManifest(
            '<?xml version="1.0"?><extension type="template"><version></version></extension>'
        );

        self::assertNull(
            \ytbmcp_shared_yt_version_from_manifest_file(),
            'An empty <version> must yield null, not an empty-string version.'
        );
    }

    /**
     * A3-L4 (2b): the manifest parses but `<version>` is whitespace-only. The
     * function trims before the empty-check, so this must also yield null.
     */
    public function test_whitespace_only_version_element_yields_null(): void
    {
        $this->writeManifest(
            "<?xml version=\"1.0\"?><extension type=\"template\"><version>   \n\t </version></extension>"
        );

        self::assertNull(
            \ytbmcp_shared_yt_version_from_manifest_file(),
            'A whitespace-only <version> must trim to empty and yield null.'
        );
    }

    /**
     * The missing-file path is also null (no manifest at the read location).
     */
    public function test_absent_manifest_yields_null(): void
    {
        $this->removeManifest();

        self::assertNull(\ytbmcp_shared_yt_version_from_manifest_file());
    }
}

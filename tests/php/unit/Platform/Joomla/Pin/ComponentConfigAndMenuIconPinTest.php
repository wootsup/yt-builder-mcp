<?php
/**
 * STATIC PARITY PIN TESTS (Wave-9 T7 — gaps #8 / #9):
 * the com_ytbmcp component ships an Options/Permissions config schema and a
 * branded admin-menu + component icon.
 *
 * These guard the STATIC manifest wiring that Joomla reads at install/render
 * time — the seams a regression could silently break:
 *
 *   #8  administrator/config.xml exists + declares the permissions `rules`
 *       fieldset (so com_config renders the Options button + Permissions tab),
 *       AND the manifest copies config.xml on a folder-scoped upgrade.
 *
 *   #9  the manifest <menu img> + <icon> point at the branded media SVG (not
 *       the generic class:component cog), the <media> element ships the
 *       images/ folder, and the icon file itself exists + is valid SVG.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class ComponentConfigAndMenuIconPinTest extends TestCase
{
    private const COMPONENT_DIR =
        'src/packaging/joomla/extensions/com_ytbmcp';

    private const MANIFEST_REL  = self::COMPONENT_DIR . '/ytbmcp.xml';
    private const CONFIG_REL    = self::COMPONENT_DIR . '/administrator/config.xml';
    private const ICON_REL      = self::COMPONENT_DIR . '/media/images/icon-ytbmcp.svg';

    private static function repoRoot(): string
    {
        return \dirname(__DIR__, 6);
    }

    private static function read(string $relPath): string
    {
        $path = self::repoRoot() . '/' . $relPath;
        if (!\is_file($path)) {
            self::fail("Expected file missing: $path");
        }
        $contents = \file_get_contents($path);
        self::assertIsString($contents, "Could not read $path");

        return $contents;
    }

    // ── #8: component config.xml ──────────────────────────────────────────

    public function test_config_xml_exists_and_is_well_formed(): void
    {
        $xml = \simplexml_load_string(self::read(self::CONFIG_REL));
        self::assertNotFalse($xml, 'administrator/config.xml must be well-formed XML.');
        self::assertSame('config', $xml->getName(), 'root element must be <config>.');
    }

    public function test_config_declares_permissions_rules_field(): void
    {
        $xml = \simplexml_load_string(self::read(self::CONFIG_REL));
        self::assertNotFalse($xml);

        $rulesField = null;
        foreach ($xml->fieldset as $fieldset) {
            foreach ($fieldset->field as $field) {
                if ((string) $field['type'] === 'rules') {
                    $rulesField = $field;
                    break 2;
                }
            }
        }

        self::assertNotNull($rulesField, 'config.xml must declare a permissions rules field (Permissions tab).');
        self::assertSame(
            'com_ytbmcp',
            (string) $rulesField['component'],
            'rules field must target the com_ytbmcp asset.'
        );
        self::assertSame(
            'component',
            (string) $rulesField['section'],
            'rules field section must be "component" (matches access.xml <section name="component">).'
        );
    }

    public function test_manifest_ships_config_xml(): void
    {
        $manifest = self::read(self::MANIFEST_REL);
        self::assertStringContainsString(
            '<filename>config.xml</filename>',
            $manifest,
            'The administrator <files> block must enumerate config.xml so a folder-scoped upgrade copies it.'
        );
    }

    // ── #9: branded menu + component icon ─────────────────────────────────

    public function test_manifest_menu_uses_branded_media_icon_not_generic_cog(): void
    {
        $xml = \simplexml_load_string(self::read(self::MANIFEST_REL));
        self::assertNotFalse($xml);

        $menu = $xml->administration->menu;
        self::assertNotNull($menu, 'manifest must declare an <administration><menu>.');

        $img = (string) $menu['img'];
        self::assertSame(
            'com_ytbmcp/images/icon-ytbmcp.svg',
            $img,
            '<menu img> must point at the branded media SVG.'
        );
        self::assertStringNotContainsString(
            'class:',
            $img,
            '<menu img> must NOT use a generic CSS-class glyph (the W9-T7 #9 fix replaces class:component).'
        );
    }

    public function test_manifest_declares_branded_component_icon(): void
    {
        $xml = \simplexml_load_string(self::read(self::MANIFEST_REL));
        self::assertNotFalse($xml);

        $icon = $xml->administration->icon;
        self::assertNotNull($icon, 'manifest must declare an <administration><icon> for the Components-view tile.');
        self::assertSame(
            'com_ytbmcp/images/icon-ytbmcp.svg',
            (string) $icon,
            '<icon> must point at the branded media SVG.'
        );
    }

    public function test_manifest_media_ships_images_folder(): void
    {
        $xml = \simplexml_load_string(self::read(self::MANIFEST_REL));
        self::assertNotFalse($xml);

        $folders = [];
        foreach ($xml->media->folder as $folder) {
            $folders[] = (string) $folder;
        }
        self::assertContains(
            'images',
            $folders,
            '<media> must ship the images/ folder so the icon installs into media/com_ytbmcp/images/.'
        );
    }

    public function test_branded_icon_file_exists_and_is_valid_svg(): void
    {
        $svg = self::read(self::ICON_REL);
        $xml = \simplexml_load_string($svg);
        self::assertNotFalse($xml, 'icon-ytbmcp.svg must be well-formed XML.');
        self::assertSame('svg', $xml->getName(), 'icon must be an <svg> root element.');
        // The WootsUp brand teal must be present (it is the mark's fill).
        self::assertStringContainsString(
            '#2fd1cd',
            $svg,
            'branded icon must carry the WootsUp teal mark.'
        );
    }
}

<?php
/**
 * ItemContainerMap — single-source-of-truth pin tests for the YT-Pro
 * container ↔ item element pairing (Multi-Items binding pattern).
 *
 * The map captures the YT-Pro 4.5.33+ invariant: `SourceTransform::repeatSource`
 * clones the *source-bearing element* N-times as siblings in its parent.
 * For "1 container with N children" the binding MUST live on the `*_item`
 * child element, NEVER on the container.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Elements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;

#[CoversClass(ItemContainerMap::class)]
final class ItemContainerMapTest extends TestCase
{
    public function test_map_pairs_every_canonical_yt_pro_container_with_its_item(): void
    {
        $expected = [
            'grid' => 'grid_item',
            'list' => 'list_item',
            'slider' => 'slider_item',
            'slideshow' => 'slideshow_item',
            'switcher' => 'switcher_item',
            'gallery' => 'gallery_item',
            'accordion' => 'accordion_item',
            'map' => 'map_item',
            'overlay-slider' => 'overlay-slider_item',
            'panel-slider' => 'panel-slider_item',
        ];
        $this->assertSame($expected, ItemContainerMap::MAP);
    }

    public function test_item_of_resolves_container_type_to_its_item_type(): void
    {
        $this->assertSame('grid_item', ItemContainerMap::itemOf('grid'));
        $this->assertSame('list_item', ItemContainerMap::itemOf('list'));
        $this->assertSame('slider_item', ItemContainerMap::itemOf('slider'));
        $this->assertSame('overlay-slider_item', ItemContainerMap::itemOf('overlay-slider'));
    }

    public function test_item_of_returns_null_for_unknown_container(): void
    {
        $this->assertNull(ItemContainerMap::itemOf('section'));
        $this->assertNull(ItemContainerMap::itemOf(''));
    }

    public function test_container_of_resolves_item_type_to_its_container_type(): void
    {
        $this->assertSame('grid', ItemContainerMap::containerOf('grid_item'));
        $this->assertSame('list', ItemContainerMap::containerOf('list_item'));
        $this->assertSame('switcher', ItemContainerMap::containerOf('switcher_item'));
        $this->assertSame('overlay-slider', ItemContainerMap::containerOf('overlay-slider_item'));
    }

    public function test_container_of_returns_null_for_unknown_item(): void
    {
        $this->assertNull(ItemContainerMap::containerOf('grid'));
        $this->assertNull(ItemContainerMap::containerOf('headline'));
        $this->assertNull(ItemContainerMap::containerOf(''));
    }

    public function test_is_container_returns_true_for_every_mapped_container(): void
    {
        foreach (array_keys(ItemContainerMap::MAP) as $container) {
            $this->assertTrue(
                ItemContainerMap::isContainer($container),
                sprintf('Expected %s to be a container.', $container),
            );
        }
    }

    public function test_is_container_returns_false_for_non_multi_item_types(): void
    {
        $this->assertFalse(ItemContainerMap::isContainer('section'));
        $this->assertFalse(ItemContainerMap::isContainer('row'));
        $this->assertFalse(ItemContainerMap::isContainer('headline'));
        $this->assertFalse(ItemContainerMap::isContainer('grid_item'));
        $this->assertFalse(ItemContainerMap::isContainer(''));
    }

    public function test_is_item_returns_true_for_every_mapped_item(): void
    {
        foreach (ItemContainerMap::MAP as $itemType) {
            $this->assertTrue(
                ItemContainerMap::isItem($itemType),
                sprintf('Expected %s to be an item.', $itemType),
            );
        }
    }

    public function test_is_item_returns_false_for_containers_and_other_types(): void
    {
        $this->assertFalse(ItemContainerMap::isItem('grid'));
        $this->assertFalse(ItemContainerMap::isItem('list'));
        $this->assertFalse(ItemContainerMap::isItem('section'));
        $this->assertFalse(ItemContainerMap::isItem(''));
    }
}

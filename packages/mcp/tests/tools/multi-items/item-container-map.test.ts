/**
 * `ITEM_CHILDREN_OF_CONTAINER` — TypeScript mirror of the canonical
 * YT-Pro container ↔ item pairing. The TS map MUST agree with the
 * PHP `ItemContainerMap::MAP` constant.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    ITEM_CHILDREN_OF_CONTAINER,
    containerOf,
    isContainer,
    isItem,
    itemOf,
} from '../../../src/tools/multi-items/item-container-map.js';

describe('ITEM_CHILDREN_OF_CONTAINER', () => {
    it('pairs every canonical YT-Pro container with its item type', () => {
        expect(ITEM_CHILDREN_OF_CONTAINER).toEqual({
            grid: 'grid_item',
            list: 'list_item',
            slider: 'slider_item',
            slideshow: 'slideshow_item',
            switcher: 'switcher_item',
            gallery: 'gallery_item',
            accordion: 'accordion_item',
            map: 'map_item',
            'overlay-slider': 'overlay-slider_item',
            'panel-slider': 'panel-slider_item',
        });
    });

    it('itemOf() returns the child item type for a known container', () => {
        expect(itemOf('grid')).toBe('grid_item');
        expect(itemOf('overlay-slider')).toBe('overlay-slider_item');
    });

    it('itemOf() returns null for non-containers', () => {
        expect(itemOf('section')).toBeNull();
        expect(itemOf('')).toBeNull();
    });

    it('containerOf() returns the container type for a known item', () => {
        expect(containerOf('grid_item')).toBe('grid');
        expect(containerOf('panel-slider_item')).toBe('panel-slider');
    });

    it('containerOf() returns null for non-items', () => {
        expect(containerOf('grid')).toBeNull();
        expect(containerOf('headline')).toBeNull();
        expect(containerOf('')).toBeNull();
    });

    it('isContainer() / isItem() classify every pair', () => {
        for (const [container, item] of Object.entries(ITEM_CHILDREN_OF_CONTAINER)) {
            expect(isContainer(container)).toBe(true);
            expect(isItem(item)).toBe(true);
            expect(isContainer(item)).toBe(false);
            expect(isItem(container)).toBe(false);
        }
    });

    it('isContainer() / isItem() return false for unrelated element types', () => {
        expect(isContainer('section')).toBe(false);
        expect(isItem('section')).toBe(false);
        expect(isContainer('')).toBe(false);
        expect(isItem('')).toBe(false);
    });
});

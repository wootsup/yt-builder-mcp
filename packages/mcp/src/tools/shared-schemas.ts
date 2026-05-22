/**
 * Shared Zod schemas — Wave-6 Fix 20.
 *
 * Centralises the schema definitions reused across pages, elements and
 * sources tool builders, so a description tweak lands in one place
 * instead of three.
 *
 * @license MIT
 */

import { z } from 'zod';

export const TEMPLATE_ID = z
    .string()
    .min(1)
    .describe(
        'Template ID (e.g. "default"). Use yootheme_builder_pages_list to discover.',
    );

export const ELEMENT_PATH = z
    .string()
    .min(1)
    .describe(
        'JSON-Pointer path to the element (e.g. "/0/children/2"). Use ' +
            'yootheme_builder_element_list to discover available paths.',
    );

export const ETAG = z
    .string()
    .min(1)
    .describe(
        'Optimistic-lock ETag from yootheme_builder_get_etag. Required to ' +
            'detect concurrent edits — call get_etag first, pass the value here. ' +
            'On 412 Precondition Failed, re-fetch the ETag and retry.',
    );

export const PROPS = z
    .record(z.string(), z.unknown())
    .describe(
        'Element props object (e.g. `{title: "Hello", margin: "default"}`). ' +
            'Use yootheme_builder_element_type_get_schema to discover available keys.',
    );

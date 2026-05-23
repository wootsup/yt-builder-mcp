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
        'JSON-Pointer to an element. **`rel_path` from `element_list` is the ' +
            'preferred form** — copy it straight back here (e.g. `/children/0` or ' +
            '`/children/0/children/1`); the server resolves it within this template. ' +
            'Fully-qualified pointers (`/templates/<id>/layout/children/0/...`) are ' +
            'also accepted. Both forms work everywhere `element_path` appears.',
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

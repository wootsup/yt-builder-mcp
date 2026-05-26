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

// F-102 (2026-05-26): the input-arg name is **`element_path`** (NOT
// `element_rel_path`). `rel_path` is a PROJECTION COLUMN name on the
// output of `element_list` / `page_get_schema` — copy its VALUE back
// here as `element_path`. The disambiguation is baked into the
// `.describe()` string so the LLM sees it at every call site that
// reuses this shared schema. There is no `element_rel_path` server
// argument anywhere.
export const ELEMENT_PATH = z
    .string()
    .min(1)
    .describe(
        'JSON-Pointer to an element. Field name is `element_path` (NOT ' +
            '`element_rel_path` — that is the projection column name on ' +
            '`element_list` / `page_get_schema` output, not an input arg). ' +
            '**`rel_path` VALUE from `element_list` is the preferred form** ' +
            '— copy it straight back here as `element_path` (e.g. `/children/0` ' +
            'or `/children/0/children/1`); the server resolves it within this ' +
            'template. Fully-qualified pointers (`/templates/<id>/layout/' +
            'children/0/...`) are also accepted. Both forms work everywhere ' +
            '`element_path` appears.',
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

/**
 * W5 — Multi-Site selector. Every tool exposes this field as an OPTIONAL
 * first-position input so an agent can target a specific site while
 * defaulting to the primary site when omitted. The handler bodies do not
 * yet read this field — W6 wires `pool.resolve(site_id)` into each
 * handler. Keep this addition non-breaking: the regex is permissive enough
 * to allow customer ids like `wp-acme` or `joomla_beta`, and the field is
 * optional so existing clients continue to work without modification.
 */
export const SITE_ID_SCHEMA = z.string()
    .min(1).max(64)
    .regex(/^[a-zA-Z0-9_-]+$/, 'site_id uses letters/digits/dash/underscore only')
    .optional()
    .describe(
        'Target site (e.g., "wp-acme"). Omit to use the default site. ' +
        'Use yootheme_builder_sites_list to discover available IDs.'
    );

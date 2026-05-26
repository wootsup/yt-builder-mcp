/**
 * W8 — `sites://current` resource tests.
 *
 * Validates that the MCP server exposes the configured-sites roster
 * as a structured JSON resource alongside the existing SKILL.md
 * resource. The resource is bearer-redacted, MIME-typed
 * `application/json`, and registered as a static single URI (no
 * list-changed semantics needed — see plan §W8 line 919: instructions
 * are single-shot per session, so the resource is structurally static
 * for the lifetime of the MCP session and never emits
 * `notifications/resources/updated`).
 *
 * @license MIT
 */

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { describe, expect, it } from 'vitest';

import {
    buildSitesResourcePayload,
    createServer,
    SITES_RESOURCE_MIME_TYPE,
    SITES_RESOURCE_URI,
    type SitesResourcePayload,
} from '../src/server.js';
import { SiteRegistry } from '../src/sites/registry.js';
import type { SiteEntryT, SitesFileT } from '../src/sites/schema.js';
import { makeMultiTestPool } from './helpers/test-pool.js';

const VALID_BEARER: string =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function makeMultiSitePool() {
    return makeMultiTestPool({
        defaultSiteId: 'wp-acme',
        sites: [
            {
                site_id: 'wp-acme',
                url: 'https://acme.com',
                bearer: VALID_BEARER,
                platform: 'wordpress',
                is_default: true,
                label: 'Acme — Production',
            },
            {
                site_id: 'joomla-beta',
                url: 'https://beta.example.com/joomla',
                bearer: VALID_BEARER,
                platform: 'joomla',
                is_default: false,
                label: 'Beta — Staging',
            },
        ],
    });
}

async function connectInMemory(pool: ReturnType<typeof makeMultiSitePool>): Promise<Client> {
    const { mcp } = createServer({ pool });
    const [clientT, serverT] = InMemoryTransport.createLinkedPair();
    const client = new Client(
        { name: 'test-client', version: '0.0.1' },
        { capabilities: {} },
    );
    await Promise.all([client.connect(clientT), mcp.connect(serverT)]);
    return client;
}

describe('buildSitesResourcePayload — pure function', () => {
    it('returns default_site_id + sites[] for a configured registry', () => {
        const entries: SiteEntryT[] = [
            {
                site_id: 'wp-a',
                url: 'https://a.example.com',
                platform: 'wordpress',
                bearer: VALID_BEARER,
                is_default: true,
                label: 'A',
            },
            {
                site_id: 'wp-b',
                url: 'https://b.example.com',
                platform: 'wordpress',
                bearer: VALID_BEARER,
                is_default: false,
            },
        ];
        const file: SitesFileT = {
            schema_version: 1,
            default_site_id: 'wp-a',
            sites: entries,
        };
        const payload: SitesResourcePayload = buildSitesResourcePayload(
            new SiteRegistry(file),
        );

        expect(payload.default_site_id).toBe('wp-a');
        expect(payload.sites).toHaveLength(2);
        expect(payload.sites[0]).toEqual({
            site_id: 'wp-a',
            url: 'https://a.example.com',
            platform: 'wordpress',
            is_default: true,
            label: 'A',
        });
        // Second site has no label — payload omits the key entirely.
        expect(payload.sites[1]).toEqual({
            site_id: 'wp-b',
            url: 'https://b.example.com',
            platform: 'wordpress',
            is_default: false,
        });
        expect('label' in payload.sites[1]).toBe(false);
    });

    it('returns default_site_id=null and an empty sites[] for a fresh registry', () => {
        const file: SitesFileT = {
            schema_version: 1,
            default_site_id: null,
            sites: [],
        };
        const payload = buildSitesResourcePayload(new SiteRegistry(file));
        expect(payload.default_site_id).toBeNull();
        expect(payload.sites).toEqual([]);
    });

    it('never references bearer, bearer_ref, ytb_live_, or op:// in the JSON output', () => {
        const entries: SiteEntryT[] = [
            {
                site_id: 'wp-acme',
                url: 'https://acme.com',
                platform: 'wordpress',
                bearer: VALID_BEARER,
                is_default: true,
                label: 'Acme',
            },
            {
                site_id: 'joomla-1p',
                url: 'https://1p.example.com',
                platform: 'joomla',
                bearer_ref: 'op://Vault/Item/credential',
                is_default: false,
            },
        ];
        const file: SitesFileT = {
            schema_version: 1,
            default_site_id: 'wp-acme',
            sites: entries,
        };
        const text = JSON.stringify(
            buildSitesResourcePayload(new SiteRegistry(file)),
            null,
            2,
        );
        expect(text.includes('bearer')).toBe(false);
        expect(text.includes('bearer_ref')).toBe(false);
        expect(text.includes('ytb_live_')).toBe(false);
        expect(text.includes('op://')).toBe(false);
        expect(text.includes('bearer_source')).toBe(false);
    });
});

describe('resources/list — sites://current entry', () => {
    it('exposes the sites resource alongside the skill resource', async () => {
        const client = await connectInMemory(makeMultiSitePool());
        try {
            const result = await client.listResources();
            const sitesResource = result.resources.find(
                (r) => r.uri === SITES_RESOURCE_URI,
            );
            expect(sitesResource).toBeDefined();
            expect(sitesResource?.mimeType).toBe('application/json');
            expect(sitesResource?.name).toBe('Currently configured sites');
        } finally {
            await client.close();
        }
    });

    it('uses the stable sites://current URI scheme', () => {
        expect(SITES_RESOURCE_URI).toBe('sites://current');
        expect(SITES_RESOURCE_MIME_TYPE).toBe('application/json');
    });
});

describe('resources/read — sites://current payload', () => {
    it('returns JSON with default_site_id + sites[] for the wired registry', async () => {
        const client = await connectInMemory(makeMultiSitePool());
        try {
            const result = await client.readResource({ uri: SITES_RESOURCE_URI });
            expect(result.contents).toHaveLength(1);
            const entry = result.contents[0];
            expect(entry.uri).toBe(SITES_RESOURCE_URI);
            expect(entry.mimeType).toBe('application/json');
            expect(typeof entry.text).toBe('string');

            // eslint-disable-next-line @typescript-eslint/no-explicit-any -- MCP resource text is JSON.parse-safe by construction
            const parsed = JSON.parse(entry.text as string) as any;
            expect(parsed.default_site_id).toBe('wp-acme');
            expect(parsed.sites).toHaveLength(2);
            expect(parsed.sites[0].site_id).toBe('wp-acme');
            expect(parsed.sites[0].url).toBe('https://acme.com');
            expect(parsed.sites[0].platform).toBe('wordpress');
            expect(parsed.sites[0].is_default).toBe(true);
            expect(parsed.sites[0].label).toBe('Acme — Production');
            expect(parsed.sites[1].site_id).toBe('joomla-beta');
            expect(parsed.sites[1].is_default).toBe(false);
        } finally {
            await client.close();
        }
    });

    it('returns JSON whose text contains NO bearer / bearer_ref / token / op:// substring', async () => {
        const client = await connectInMemory(makeMultiSitePool());
        try {
            const result = await client.readResource({ uri: SITES_RESOURCE_URI });
            const text = result.contents[0].text as string;
            expect(text.includes('bearer')).toBe(false);
            expect(text.includes('bearer_ref')).toBe(false);
            expect(text.includes('ytb_live_')).toBe(false);
            expect(text.includes('op://')).toBe(false);
            expect(text.includes('bearer_source')).toBe(false);
        } finally {
            await client.close();
        }
    });

    it('emits the payload as pretty-printed JSON (2-space indent)', async () => {
        const client = await connectInMemory(makeMultiSitePool());
        try {
            const result = await client.readResource({ uri: SITES_RESOURCE_URI });
            const text = result.contents[0].text as string;
            // Two-space-indented JSON contains `  "default_site_id"`
            // somewhere on the second line — easier to read for humans
            // peeking at the resource in a host devtools panel.
            expect(text).toContain('  "default_site_id"');
            expect(text).toContain('  "sites"');
        } finally {
            await client.close();
        }
    });
});

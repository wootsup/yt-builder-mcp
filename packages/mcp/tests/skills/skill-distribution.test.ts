/**
 * skill-distribution tests — Stream B1.
 *
 * Verify that the MCP server distributes the bundled SKILL.md via the
 * two MCP-native channels:
 *
 *   1. `instructions` field in the `initialize` response (auto-context
 *      for hosts like Claude Desktop that surface server instructions).
 *   2. `resources/list` + `resources/read` for hosts that prefer
 *      on-demand fetch (Cursor, generic MCP clients).
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { describe, expect, it } from 'vitest';

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { createServer } from '../../src/server.js';
import type { ClientPool } from '../../src/sites/client-pool.js';
import { loadSkillContent, SKILL_RESOURCE_URI } from '../../src/skill-loader.js';
import { makeTestPool } from '../helpers/test-pool.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SKILL_PATH = resolve(
    __dirname,
    '..',
    '..',
    'skills',
    'yt-builder-mcp',
    'SKILL.md',
);

function makeClient(): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 'test',
    });
}

interface ConnectedClient {
    readonly client: Client;
    readonly pool: ClientPool;
}

async function connectInMemory(): Promise<ConnectedClient> {
    const pool = makeClient();
    const { mcp } = createServer({ pool });
    const [clientT, serverT] = InMemoryTransport.createLinkedPair();
    const client = new Client(
        { name: 'test-client', version: '0.0.1' },
        { capabilities: {} },
    );
    await Promise.all([client.connect(clientT), mcp.connect(serverT)]);
    return { client, pool };
}

describe('skill-loader', () => {
    it('loads SKILL.md content from disk', () => {
        const content = loadSkillContent();
        const expected = readFileSync(SKILL_PATH, 'utf-8');
        expect(content).toBe(expected);
    });

    it('exports a stable resource URI for the skill', () => {
        expect(SKILL_RESOURCE_URI).toBe('skill://yt-builder-mcp');
    });

    it('returns non-empty markdown that starts with the skill frontmatter', () => {
        const content = loadSkillContent();
        expect(content.length).toBeGreaterThan(1000);
        expect(content.startsWith('---\nname: yt-builder-mcp')).toBe(true);
    });
});

describe('initialize — instructions field', () => {
    it('includes the full SKILL.md content (with W8 sites appendix) as instructions', async () => {
        const { client, pool } = await connectInMemory();
        try {
            const instructions = client.getInstructions();
            expect(instructions).toBeDefined();
            // W8 — server.ts wires loadSkillContent(pool.registry) so the
            // instructions string includes the live "Currently configured
            // sites" appendix when the registry carries ≥1 site (the
            // test pool always has 1 default site).
            expect(instructions).toBe(loadSkillContent(pool.registry));
        } finally {
            await client.close();
        }
    });

    it('instructions field is non-empty and references the YT Builder skill', async () => {
        const { client } = await connectInMemory();
        try {
            const instructions = client.getInstructions() ?? '';
            expect(instructions.length).toBeGreaterThan(1000);
            expect(instructions).toContain('yt-builder-mcp');
        } finally {
            await client.close();
        }
    });
});

describe('resources/list — skill resource', () => {
    it('exposes the SKILL.md as a resource entry', async () => {
        const { client } = await connectInMemory();
        try {
            const result = await client.listResources();
            const skillResource = result.resources.find(
                (r) => r.uri === SKILL_RESOURCE_URI,
            );
            expect(skillResource).toBeDefined();
            expect(skillResource?.mimeType).toBe('text/markdown');
            expect(skillResource?.name).toBeTruthy();
        } finally {
            await client.close();
        }
    });
});

describe('resources/read — skill resource', () => {
    it('returns SKILL.md text (with W8 sites appendix) at mimeType text/markdown', async () => {
        const { client, pool } = await connectInMemory();
        try {
            const result = await client.readResource({ uri: SKILL_RESOURCE_URI });
            expect(result.contents.length).toBe(1);
            const entry = result.contents[0];
            expect(entry.uri).toBe(SKILL_RESOURCE_URI);
            expect(entry.mimeType).toBe('text/markdown');
            expect(typeof entry.text).toBe('string');
            // Server-side snapshot wires the same registry, so the
            // resource read matches the registry-aware loadSkillContent.
            expect(entry.text).toBe(loadSkillContent(pool.registry));
        } finally {
            await client.close();
        }
    });
});

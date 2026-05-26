/**
 * MCP server factory — Wave G.1 / Gateway-Hub edition.
 *
 * `createServer({client})` builds a configured `McpServer` with a 3-lane
 * tool registration:
 *
 *   L3 — direct top-level (yootheme_builder_health + yootheme_builder_diagnose)
 *        Registered FIRST, BEFORE the CapturingServer wraps the real server.
 *        These tools must reach the LLM even when the gateway itself
 *        misbehaves (chicken-and-egg: the LLM diagnoses gateway breakage
 *        via these two).
 *
 *   L1 — essential forwarded (17 tools)
 *        Routed through the CapturingServer to the real server. Appear in
 *        `tools/list` as first-class entries (Cursor-cap-safe).
 *
 *   L2 — advanced captured (7 tools)
 *        Routed through the CapturingServer into its in-process advanced
 *        registry; reachable via the single `yootheme_builder_advanced`
 *        gateway tool. Hidden from `tools/list`.
 *
 *   Gateway — `yootheme_builder_advanced`
 *        Registered LAST on the real server, after every L1/L2 tool is
 *        placed and the advanced registry is final.
 *
 * Result: `tools/list.length === 20` (17 L1 + 2 L3 + 1 gateway), and the
 * full callable tool surface is 27 (20 advertised + 7 advanced reachable
 * through the gateway).
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ZodRawShapeCompat } from '@modelcontextprotocol/sdk/server/zod-compat.js';

import { registerAdvancedTool } from './gateway/advanced-tool.js';
import { CapturingServer, type ToolRegistrar } from './gateway/capturing-server.js';
import { isDirectTopLevel } from './gateway/essentials.js';
import type { ClientPool } from './sites/client-pool.js';
import type { SiteRegistry } from './sites/registry.js';
import {
    loadSkillContent,
    SKILL_RESOURCE_DESCRIPTION,
    SKILL_RESOURCE_MIME_TYPE,
    SKILL_RESOURCE_NAME,
    SKILL_RESOURCE_URI,
} from './skill-loader.js';
import { toElicitationCapability } from './tools/elicitation.js';
import { buildAllTools, type ToolDefinition } from './tools/index.js';

export const SERVER_NAME = '@wootsup/yt-builder-mcp';

/**
 * Read `version` from the package.json that ships alongside `dist/` (and
 * `src/` in the source tree). Resolved at module-init from
 *
 *   src-tree: packages/mcp/src/server.ts  → ../package.json
 *   tarball:  packages/mcp/dist/server.js → ../package.json
 *
 * Both layouts work because `package.json` lives at the package root and
 * `src/` / `dist/` are siblings. Bumping `package.json#version` (e.g. via
 * `php scripts/release.php release yt-builder-mcp patch`) is the SINGLE
 * source of truth — the value reported on MCP `initialize.serverInfo`
 * follows automatically. No hardcoded string to drift out of sync.
 */
function readPackageVersion(): string {
    const here = dirname(fileURLToPath(import.meta.url));
    const pkgPath = resolve(here, '..', 'package.json');
    const raw = readFileSync(pkgPath, 'utf-8');
    const parsed = JSON.parse(raw) as { version?: unknown };
    if (typeof parsed.version !== 'string' || parsed.version.length === 0) {
        throw new Error(
            `yt-builder-mcp: package.json at ${pkgPath} is missing a string "version" field`,
        );
    }
    return parsed.version;
}

export const SERVER_VERSION = readPackageVersion();

/**
 * W8 — stable URI under which the live sites snapshot is exposed via
 * MCP `resources/*`. Single fixed resource (no list-changed needed) —
 * MCP hosts can fetch the current registry roster on demand without
 * having to invoke a tool.
 */
export const SITES_RESOURCE_URI = 'sites://current';

/** Human-readable resource name surfaced in `resources/list`. */
export const SITES_RESOURCE_NAME = 'Currently configured sites';

/** Short description for `resources/list` consumers. */
export const SITES_RESOURCE_DESCRIPTION =
    'Live snapshot of sites wired into this MCP server (site_id, URL, ' +
    'platform, default flag, label). Bearer fields are redacted. ' +
    'Mirrors what `yootheme_builder_sites_list` would print.';

/** MIME type for the `sites://current` payload. */
export const SITES_RESOURCE_MIME_TYPE = 'application/json';

/**
 * Bearer-redacted JSON payload shape for the W8 `sites://current`
 * resource. Mirrors the structured-content shape of the
 * `yootheme_builder_sites_list` tool but never reveals bearer or
 * bearer_ref — only safe-to-render display fields land in the
 * `sites[]` rows.
 */
export interface SitesResourcePayload {
    readonly default_site_id: string | null;
    readonly sites: readonly SitesResourceRow[];
}

/**
 * One row inside {@link SitesResourcePayload.sites}. Strictly the
 * fields enumerated by plan §W8 Z.929 — no bearer / bearer_ref /
 * bearer_source. `label` and `platform_resolved` are optional (only
 * present when the underlying registry entry / probe carries them).
 */
export interface SitesResourceRow {
    readonly site_id: string;
    readonly url: string;
    readonly platform: 'wordpress' | 'joomla' | 'auto';
    readonly is_default: boolean;
    readonly label?: string;
}

/**
 * Build the JSON payload served by the `sites://current` resource.
 *
 * Field projection (per plan §W8 Z.925-933):
 *  - `default_site_id` from {@link SiteRegistry.defaultSiteId}.
 *  - `sites[].site_id` / `url` / `is_default` / `label` verbatim.
 *  - `sites[].platform` prefers the resolved kind from the W3 memo
 *    cache (peek-only, no probe) and falls back to the configured
 *    hint when no probe has run.
 *
 * Bearer fields are NEVER referenced. The `bearer_source` hint that
 * the W7 `sites_list` tool exposes is intentionally omitted from this
 * resource: even the "plain" vs "op" hint hints at storage layout,
 * and the resource is positioned as a host-facing public roster.
 */
export function buildSitesResourcePayload(
    registry: SiteRegistry,
): SitesResourcePayload {
    const rows = registry.listForDisplay();
    return {
        default_site_id: registry.defaultSiteId(),
        sites: rows.map((row): SitesResourceRow => {
            const platform: 'wordpress' | 'joomla' | 'auto' =
                row.platform_resolved ?? row.platform_hint;
            const out: { -readonly [K in keyof SitesResourceRow]: SitesResourceRow[K] } = {
                site_id: row.site_id,
                url: row.url,
                platform,
                is_default: row.is_default,
            };
            if (row.label !== undefined) {
                out.label = row.label;
            }
            return out;
        }),
    };
}

export interface CreateServerOptions {
    /**
     * Multi-site client pool — replaces the single `client` parameter
     * shipped pre-W6. Each tool handler asks `pool.resolve(site_id)`
     * for the per-call REST client + resolved site descriptor.
     */
    readonly pool: ClientPool;
    /** Override the tool set (defaults to `buildAllTools`). */
    readonly tools?: readonly ToolDefinition[];
}

export interface CreatedServer {
    readonly mcp: McpServer;
    /** The full set of tool definitions registered across all 3 lanes + gateway. */
    readonly tools: readonly ToolDefinition[];
    /** The CapturingServer wrapping `mcp` — exposed for test helpers. */
    readonly capturing: CapturingServer;
}

export function createServer(options: CreateServerOptions): CreatedServer {
    // Stream B1 — Skill-Distribution via MCP-Protocol.
    //
    // The bundled SKILL.md is published through two MCP-native channels:
    //
    //   (a) `instructions` field on the `initialize` response. MCP-spec
    //       hosts (Claude Desktop, Cursor, …) surface this string as
    //       auto-context so Maria-the-Claude-Desktop-user receives the
    //       skill narrative without ever calling a tool.
    //
    //   (b) `skill://yt-builder-mcp` resource exposed via
    //       `resources/list` + `resources/read`. Backup for hosts that
    //       ignore `instructions` but honor the resources API.
    //
    // Reading SKILL.md is synchronous (small file, ~17 KB) and cached
    // after first call by `loadSkillContent` — no async cost.
    //
    // W8 — pass the pool's registry so loadSkillContent can append the
    // live "Currently configured sites" block. When the registry is
    // empty (fresh install, no sites yet) the appendix is silently
    // omitted and the host receives the unchanged skill content.
    // Bearer fields are never touched by the appendix (see
    // {@link appendSitesBlock} in skill-loader.ts).
    const instructions = loadSkillContent(options.pool.registry);

    const mcp = new McpServer(
        {
            name: SERVER_NAME,
            version: SERVER_VERSION,
        },
        {
            instructions,
        },
    );

    // Register the SKILL.md as a static MCP resource. The capability
    // (`resources: { listChanged: true }` etc.) is auto-advertised by
    // McpServer the moment the first resource is registered, so MCP
    // hosts see `resources` in `initialize.capabilities`.
    mcp.registerResource(
        SKILL_RESOURCE_NAME,
        SKILL_RESOURCE_URI,
        {
            title: SKILL_RESOURCE_NAME,
            description: SKILL_RESOURCE_DESCRIPTION,
            mimeType: SKILL_RESOURCE_MIME_TYPE,
        },
        async (uri: URL) => ({
            contents: [
                {
                    uri: uri.toString(),
                    mimeType: SKILL_RESOURCE_MIME_TYPE,
                    text: instructions,
                },
            ],
        }),
    );

    // W8 — `sites://current` resource: live registry snapshot as JSON.
    //
    // The payload is computed on every read (cheap — listForDisplay()
    // is in-memory) so hosts that re-fetch after a sites file edit see
    // up-to-date data. We do NOT emit `notifications/resources/updated`
    // since the only mutation path is the W9 CLI which itself requires
    // an AI-client restart (per plan §W8 line 919) — the resource is
    // structurally static for the lifetime of the MCP session.
    //
    // Bearer / bearer_ref are NEVER referenced (see
    // {@link buildSitesResourcePayload}). The resource intentionally
    // omits the `bearer_source` hint that the `sites_list` tool
    // surfaces; a host-facing roster should not even hint at storage
    // layout.
    const registry = options.pool.registry;
    mcp.registerResource(
        SITES_RESOURCE_NAME,
        SITES_RESOURCE_URI,
        {
            title: SITES_RESOURCE_NAME,
            description: SITES_RESOURCE_DESCRIPTION,
            mimeType: SITES_RESOURCE_MIME_TYPE,
        },
        async (uri: URL) => ({
            contents: [
                {
                    uri: uri.toString(),
                    mimeType: SITES_RESOURCE_MIME_TYPE,
                    text: JSON.stringify(
                        buildSitesResourcePayload(registry),
                        null,
                        2,
                    ),
                },
            ],
        }),
    );

    // Wave G.4.5 — MCP elicitation capability wiring.
    //
    // `toElicitationCapability(mcp)` bridges the SDK's stricter
    // discriminated `ElicitRequestFormParams` to the toolkit's looser
    // `ElicitInputParams`. The 3 elicitation-aware tools
    // (`element_delete`, `element_unbind_source`, `element_bind_source`)
    // consume this capability through their `*HandlerDeps`; the other 19
    // tools never see it. When the host the SDK connects to does not
    // advertise the elicitation capability, the toolkit's `elicitChoice` /
    // `elicitConfirmation` return `null` / `false` and the tool falls
    // back to the structured-error / preview path (no hang, no guess).
    const elicitation = toElicitationCapability(mcp);

    const tools =
        options.tools ?? buildAllTools(options.pool, { elicitation });

    // L3 — direct top-level: registered FIRST on the real McpServer, before
    // the CapturingServer wraps it. These names get a no-op route inside
    // the CapturingServer (see capturing-server.ts), so the SDK never sees
    // a duplicate registration when the builder list is later replayed.
    for (const tool of tools) {
        if (isDirectTopLevel(tool.name)) {
            registerToolOn(mcp, tool);
        }
    }

    // L1 / L2 — every other tool is routed through the CapturingServer,
    // which forwards essentials to the real server and captures the rest
    // into its advanced registry.
    const capturing = new CapturingServer(mcp);
    for (const tool of tools) {
        if (isDirectTopLevel(tool.name)) continue;
        registerToolOn(capturing, tool);
    }

    // Gateway — registered LAST so the advanced registry is final.
    registerAdvancedTool(mcp, capturing.getAdvancedRegistry());

    return { mcp, tools, capturing };
}

/**
 * Registers a `ToolDefinition` on a `ToolRegistrar`. A real `McpServer`
 * satisfies `ToolRegistrar` structurally (its `registerTool` returns
 * `RegisteredTool`, which is assignable to the `ToolRegistrar` declared
 * `void` return type), so call-sites pass either a real server (for L3
 * + the gateway, which need not be captured) or a `CapturingServer`
 * (for L1 + L2 routing).
 */
function registerToolOn(registrar: ToolRegistrar, tool: ToolDefinition): void {
    // F-203 follow-up (Audit 2026-05-26 reviewer Gap 2): when the tool
    // ships a refined `inputObjectSchema` (a full `z.object(...).refine(...)`
    // built from the same shape as `inputSchema`), forward THAT to the
    // SDK so its built-in pre-handler validation enforces cross-field
    // rules and raises a JSON-RPC `-32602 InvalidParams` envelope on
    // violation — BEFORE the handler ever runs. Otherwise fall back to
    // the raw `ZodRawShape`, which the SDK auto-wraps with
    // `objectFromShape()` (no `.refine`).
    const inputSchema =
        tool.inputObjectSchema !== undefined
            ? (tool.inputObjectSchema as unknown as ZodRawShapeCompat)
            : (tool.inputSchema as ZodRawShapeCompat);

    // outputSchema is optional — tools migrated to structured-content (Wave
    // G.2) declare a Zod schema describing the `structuredContent` payload.
    // The SDK accepts a `ZodTypeAny` (v3/v4) via `AnySchema`; we forward our
    // schema verbatim. Hosts that support MCP structured output validate
    // responses against this schema.
    const outputSchema =
        tool.outputSchema !== undefined
            ? (tool.outputSchema as unknown as ZodRawShapeCompat)
            : undefined;

    registrar.registerTool(
        tool.name,
        {
            description: tool.description,
            inputSchema,
            ...(outputSchema !== undefined ? { outputSchema } : {}),
            annotations: tool.annotations,
        },
        // Wave G.5 — forward the SDK-supplied `extra` (RequestHandlerExtra)
        // as the 2nd argument to every handler. Write-handlers consume
        // `extra._meta.progressToken` + `extra.sendNotification` via the
        // local `createProgressReporter` helper; read-handlers ignore it
        // (their handler signature still binds `extra` to `HandlerExtra |
        // undefined`, so no runtime coupling for non-progress tools).
        // F-203 follow-up: cross-field input refinement (when present on
        // `tool.inputObjectSchema`) is enforced by the SDK pre-handler;
        // see the `inputSchema` selection above.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- SDK generic narrows to inferred shape; safe because zod validates upstream
        (async (args: any, extra: any) => {
            return tool.handler(args, extra);
        }) as never,
    );
}

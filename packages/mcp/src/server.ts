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
 *   L1 — essential forwarded (7 tools)
 *        Routed through the CapturingServer to the real server. Appear in
 *        `tools/list` as first-class entries (Cursor-cap-safe).
 *
 *   L2 — advanced captured (12 tools)
 *        Routed through the CapturingServer into its in-process advanced
 *        registry; reachable via the single `yootheme_builder_advanced`
 *        gateway tool. Hidden from `tools/list`.
 *
 *   Gateway — `yootheme_builder_advanced`
 *        Registered LAST on the real server, after every L1/L2 tool is
 *        placed and the advanced registry is final.
 *
 * Result: `tools/list.length === 10` (7 L1 + 2 L3 + 1 gateway), and the
 * full tool surface is 22 (collectAllRegisteredTools).
 *
 * @license MIT
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ZodRawShapeCompat } from '@modelcontextprotocol/sdk/server/zod-compat.js';

import type { RestClient } from './client.js';
import { registerAdvancedTool } from './gateway/advanced-tool.js';
import { CapturingServer, type ToolRegistrar } from './gateway/capturing-server.js';
import { isDirectTopLevel } from './gateway/essentials.js';
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
export const SERVER_VERSION = '0.2.0-alpha.2';

export interface CreateServerOptions {
    readonly client: RestClient;
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
    const instructions = loadSkillContent();

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
        options.tools ?? buildAllTools(options.client, { elicitation });

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
    // The SDK's `registerTool` accepts a raw Zod shape as inputSchema. We
    // cast through `ZodRawShapeCompat` to keep our `ToolDefinition` generic
    // (any ZodRawShape) compatible with the SDK's v3/v4 dual-zod surface.
    const inputSchema = tool.inputSchema as ZodRawShapeCompat;

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
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- SDK generic narrows to inferred shape; safe because zod validates upstream
        (async (args: any, extra: any) => {
            return tool.handler(args, extra);
        }) as never,
    );
}

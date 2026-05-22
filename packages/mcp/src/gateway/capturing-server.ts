/**
 * CapturingServer — registration interceptor wrapping a real McpServer.
 *
 * Tool routing (Wave G.1 / Design-Doc §3.1.1):
 *   - L3 direct (yootheme_builder_health, yootheme_builder_diagnose):
 *     skipped — no forward, no capture. These tools must already be
 *     registered directly on the real server BEFORE the CapturingServer
 *     wraps it, so the SDK never sees a duplicate name.
 *   - L1 essential (ESSENTIAL_TOOLS): forwarded to the real server as a
 *     first-class MCP tool reachable from `tools/list`.
 *   - L2 advanced (everything else): captured into the in-process
 *     advanced registry, reachable through the single
 *     `yootheme_builder_advanced` gateway tool.
 *
 * This is a typed wrapper, NOT a JS Proxy — TypeScript stays strict.
 *
 * Typing approach (mirrors the reference apimapper-mcp implementation):
 *   `ToolRegistrar` is the McpServer subsurface modules consume:
 *   `registerTool` + `registerResource`. `registerTool` is a hand-written
 *   generic mirroring the SDK signature EXCEPT its return type is `void`
 *   — captured tools have no live `RegisteredTool`, and no module reads
 *   the return value. A real `McpServer` is still assignable to
 *   `ToolRegistrar` (return-type bivariance for `void`), so every
 *   call-site accepts both shapes cast-free.
 *
 * Casts in this file (each documented + justified inline):
 *   1. forwarded-essential — erases the handler's `InputArgs` generic to
 *      the SDK default when `config` is the erased `CapturedToolConfig`.
 *   2. registry storage — erases the generic handler to the homogeneous
 *      `CapturedToolHandler` shape so one Map type fits all entries.
 *   3. `registerResource` field — the SDK declares two overloads; a
 *      single spread-arg arrow cannot re-express the pair.
 *
 * @license MIT
 */

import type { McpServer, ToolCallback } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { AnySchema, ZodRawShapeCompat } from '@modelcontextprotocol/sdk/server/zod-compat.js';
import type { CallToolResult, ToolAnnotations } from '@modelcontextprotocol/sdk/types.js';

import { isDirectTopLevel, isEssential } from './essentials.js';

/**
 * The input-schema constraint the SDK's `registerTool` accepts — a raw Zod
 * shape, a Zod schema, or `undefined` (zero-arg tools). Reused so the
 * `ToolRegistrar.registerTool` generic stays in lockstep with `ToolCallback`.
 */
type ToolInputArgs = undefined | ZodRawShapeCompat | AnySchema;

/**
 * The config object accepted by `registerTool` — mirrors the SDK's 2nd arg.
 * `inputSchema` is generic so per-tool handler args stay strongly inferred.
 */
interface RegisterToolConfig<InputArgs extends ToolInputArgs> {
    title?: string;
    description?: string;
    inputSchema?: InputArgs;
    outputSchema?: ZodRawShapeCompat | AnySchema;
    annotations?: ToolAnnotations;
    _meta?: Record<string, unknown>;
}

/**
 * The McpServer subsurface our `registerXTools` builders consume.
 *
 * `registerTool` is a hand-written generic mirroring the SDK signature with a
 * `void` return type. A real `McpServer` (whose method returns
 * `RegisteredTool`) is still assignable here because a value-returning method
 * satisfies a `void`-returning member. The `InputArgs` generic preserves
 * per-tool `ToolCallback` argument inference at every call-site.
 */
export interface ToolRegistrar {
    registerTool<InputArgs extends ToolInputArgs = undefined>(
        name: string,
        config: RegisterToolConfig<InputArgs>,
        cb: ToolCallback<InputArgs>,
    ): void;
    registerResource: McpServer['registerResource'];
}

/** Config object passed to `registerTool` (the SDK's 2nd arg). */
export type CapturedToolConfig = Parameters<McpServer['registerTool']>[1];
/** Args of `registerResource` (the SDK has two overloads — both covered). */
type RegisterResourceArgs = Parameters<McpServer['registerResource']>;

/**
 * Captured-handler shape. The SDK's `registerTool` is generic over the input
 * shape, so a captured handler is stored with its args erased to
 * `Record<string, unknown>`. The gateway validates `arguments` against the
 * tool's Zod schema before calling the handler, so the runtime contract holds.
 */
export type CapturedToolHandler = (
    args: Record<string, unknown>,
    extra: unknown,
) => CallToolResult | Promise<CallToolResult>;

/** A captured advanced tool: its registration config and its handler. */
export interface AdvancedToolEntry {
    config: CapturedToolConfig;
    handler: CapturedToolHandler;
}

/**
 * Wraps a real McpServer. Forwards L1 essentials (and all resources) to it,
 * captures L2 non-essentials into an in-process registry, and silently
 * skips L3 direct top-level names that must already be on the real server.
 */
export class CapturingServer implements ToolRegistrar {
    private readonly realServer: McpServer;
    private readonly advancedRegistry = new Map<string, AdvancedToolEntry>();

    constructor(realServer: McpServer) {
        this.realServer = realServer;
    }

    /**
     * Routes a tool registration by name. Returns `void`: captured tools
     * have no `RegisteredTool` and no `registerXTools` module reads the
     * return value.
     *
     * Routing order matters: the L3-direct check is FIRST so a caller
     * cannot accidentally re-route a direct-registered name through the
     * gateway just by re-naming a future tool builder.
     */
    registerTool<InputArgs extends ToolInputArgs = undefined>(
        name: string,
        config: CapturedToolConfig,
        handler: ToolCallback<InputArgs>,
    ): void {
        // L3 — direct top-level: already on the real server, do nothing.
        // Returning early here prevents the SDK from raising a duplicate-name
        // error when an L3 tool's builder is accidentally re-included in the
        // forwarded tool list.
        if (isDirectTopLevel(name)) {
            return;
        }

        if (isEssential(name)) {
            // L1 — forward to the real server.
            //
            // Cast: the SDK pairs `config.inputSchema` and the handler through
            // one `InputArgs` generic. Here `config` is the erased
            // `CapturedToolConfig`, so the SDK cannot re-infer that link; the
            // handler is asserted to the SDK's default `ToolCallback` to satisfy
            // the call. The runtime values are exactly what the SDK expects —
            // only the static generic linkage is erased.
            this.realServer.registerTool(name, config, handler as ToolCallback);
            return;
        }

        // L2 — capture into the advanced registry.
        //
        // Erase the generic handler to the captured-handler shape for
        // homogeneous Map storage. The gateway validates `arguments` against
        // the tool's Zod schema before invoking it, so the (args, extra)
        // runtime contract holds.
        this.advancedRegistry.set(name, {
            config,
            handler: handler as unknown as CapturedToolHandler,
        });
    }

    /**
     * Resources are always forwarded — they are not part of the tool cap.
     *
     * Cast: the SDK declares `registerResource` as two overloads; a single
     * spread `(...args: RegisterResourceArgs)` arrow cannot re-express that
     * overload pair, so the field is asserted to the SDK's exact member
     * type. The spread forwards every argument verbatim, so the runtime
     * contract is unchanged.
     */
    registerResource: McpServer['registerResource'] = ((...args: RegisterResourceArgs) =>
        this.realServer.registerResource(...args)) as McpServer['registerResource'];

    /** The captured non-essential tools, keyed by tool name. */
    getAdvancedRegistry(): Map<string, AdvancedToolEntry> {
        return this.advancedRegistry;
    }
}

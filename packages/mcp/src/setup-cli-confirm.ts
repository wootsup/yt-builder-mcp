/**
 * W12-R2A — TTY confirm prompt for destructive CLI subcommands.
 *
 * Hosts `confirmTTY(prompt)`, the y/N readline callback the W9
 * dispatcher wires into the `removeSiteCommand` / `setDefaultCommand`
 * deps bag. Lives in its own file so the dispatcher stays a thin
 * routing layer and so tests can mock the prompt without touching the
 * subcommand factories.
 *
 * Contract:
 *
 *  - Returns `true` ONLY when stdin is a TTY AND the operator types
 *    "y", "Y", or "yes" (case-insensitive). Anything else (incl. EOF,
 *    empty line, Ctrl-D) returns `false` → caller treats this as a
 *    cancel and skips the write.
 *
 *  - When stdin is NOT a TTY (CI, piped input, MCP-server stdio), the
 *    function does NOT block — it returns `false` immediately and
 *    writes a one-line hint to stderr telling the operator to pass
 *    `--yes` for non-interactive use. The dispatcher then surfaces a
 *    typed `CONFIRM_REQUIRED` / `SET_DEFAULT_CONFIRM_REQUIRED` error
 *    via the subcommand factory (which fails closed when no confirm
 *    hook AND no --yes is in play).
 *
 *  - No external deps; uses Node's built-in `node:readline` so the
 *    package keeps the same dep-graph as Round-1.
 *
 * @license MIT
 */

import { createInterface } from 'node:readline';

/**
 * Read one line via readline and resolve `true` on y/Y/yes,
 * `false` otherwise. Stdin-not-a-TTY short-circuits to `false` with a
 * stderr hint so a piped invocation can't silently hang.
 */
export async function confirmTTY(prompt: string): Promise<boolean> {
    // process.stdin.isTTY is `true | undefined` per Node's typings —
    // explicit !== true guards against both undefined and false.
    if (process.stdin.isTTY !== true) {
        process.stderr.write(
            'yt-builder-mcp: no TTY available for confirmation. '
                + 'Pass --yes to run this subcommand non-interactively.\n',
        );
        return false;
    }
    const rl = createInterface({
        input: process.stdin,
        output: process.stderr,
    });
    try {
        const answer = await new Promise<string>((resolve) => {
            rl.question(`${prompt} [y/N] `, (line) => resolve(line));
        });
        const normalised = answer.trim().toLowerCase();
        return normalised === 'y' || normalised === 'yes';
    } finally {
        rl.close();
    }
}

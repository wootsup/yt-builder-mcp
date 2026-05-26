/**
 * W1 — Sites-Storage Foundation: default on-disk path resolver.
 *
 * Resolution order:
 *  1. `$XDG_CONFIG_HOME/yt-builder-mcp/sites.json` (XDG Base-Dir spec)
 *  2. `$HOME/.config/yt-builder-mcp/sites.json` (XDG fallback)
 *  3. `os.tmpdir()/yt-builder-mcp/sites.json` + stderr warning (last
 *     resort — used in CI / containers where HOME may be unset).
 *
 * The W2/W3 layer never touches paths directly — callers ask this module
 * for the canonical location and pass the resolved string to the store.
 *
 * @license MIT
 */

import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * Pure environment lookup so tests can inject a process-env-like object
 * without mutating `process.env`. Returns the path that the load/save
 * layer should target.
 */
export function defaultSitesFilePath(
    env: NodeJS.ProcessEnv = process.env,
    log: (msg: string) => void = (msg) => { process.stderr.write(msg + '\n'); },
): string {
    const xdg = env.XDG_CONFIG_HOME;
    if (xdg !== undefined && xdg.length > 0) {
        return join(xdg, 'yt-builder-mcp', 'sites.json');
    }
    const home = env.HOME;
    if (home !== undefined && home.length > 0) {
        return join(home, '.config', 'yt-builder-mcp', 'sites.json');
    }
    const tmp = tmpdir();
    log(
        `[yt-builder-mcp] WARN: neither XDG_CONFIG_HOME nor HOME is set — ` +
        `sites file will be stored in ${tmp}/yt-builder-mcp/sites.json. ` +
        `This is ephemeral; set HOME or XDG_CONFIG_HOME for persistence.`,
    );
    return join(tmp, 'yt-builder-mcp', 'sites.json');
}

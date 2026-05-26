/**
 * W9 — `list-sites` CLI subcommand.
 *
 * Reads `sites.json`, prints a tabular view matching the structure of
 * the `yootheme_builder_sites_list` MCP tool. NEVER reaches for the
 * secret-resolver — the table only exposes `bearer_source: 'plain' |
 * 'op'` so an in-progress 1Password unlock cannot wedge the CLI.
 *
 * Output contract (verbatim columns, in order):
 *   SITE_ID  URL  PLATFORM  DEFAULT  LABEL  BEARER
 *
 * Empty registry prints "(no sites configured)" + a discovery hint
 * pointing at `add-site`. The exit code is always 0 on a successful
 * read — even an empty registry is a normal state, not an error.
 *
 * @license MIT
 */

import { SiteRegistry } from '../registry.js';
import { loadSitesFile } from '../store.js';
import type { SitesFileT } from '../schema.js';

/** Test-injection seam — production callers use the defaults below. */
export interface ListSitesDeps {
    readonly load: (path: string) => Promise<SitesFileT>;
    readonly log?: (line: string) => void;
}

/**
 * Render the sites registry to stdout. Returns the rendered lines so
 * tests can assert without parsing captured stdout; the caller is
 * still expected to print them via `deps.log` for the live CLI path.
 */
export async function listSitesCommand(
    path: string,
    deps: ListSitesDeps,
): Promise<readonly string[]> {
    const file = await deps.load(path);
    const lines = renderSitesTable(file);
    if (deps.log !== undefined) {
        for (const line of lines) deps.log(line);
    }
    return lines;
}

/**
 * Pure renderer — exported for direct test coverage of the layout.
 * Layout: fixed-width left-padded columns, header on row 0, divider on
 * row 1, one row per site. Last line always carries the discovery hint
 * (or empty-registry hint) so a Maria-class user always sees the next
 * step.
 */
export function renderSitesTable(file: SitesFileT): readonly string[] {
    if (file.sites.length === 0) {
        return [
            '(no sites configured)',
            '',
            'Add one with: yt-builder-mcp add-site --url https://example.com --token <bearer>',
        ];
    }

    // Build a registry purely so we can reuse listForDisplay's shape.
    // The platform probe is never triggered because this CLI never
    // calls platformFor().
    const registry = new SiteRegistry(file);
    const rows = registry.listForDisplay();
    const defaultSiteId = registry.defaultSiteId();

    // W12-R1.3 — bearer_source column dropped for consistency with the
    // MCP `sites_list` tool + the `sites://current` resource. The CLI
    // never needed it (the operator already knows which form they
    // chose); keeping it here would be a divergence surface only.
    const COLUMNS = [
        { key: 'site_id', label: 'SITE_ID', width: 20 },
        { key: 'url', label: 'URL', width: 36 },
        { key: 'platform', label: 'PLATFORM', width: 11 },
        { key: 'default', label: 'DEFAULT', width: 9 },
        { key: 'label', label: 'LABEL', width: 24 },
    ] as const;

    function pad(text: string, width: number): string {
        if (text.length >= width) return text.slice(0, width);
        return text + ' '.repeat(width - text.length);
    }

    /**
     * W12-R1.3 (A2-L2): strip ANSI escape codes + control characters
     * from operator-provided strings before printing. A malicious or
     * accidentally-pasted label could otherwise repaint the terminal
     * ("\x1b[2J\x1b[H", "\r" cursor reset, etc.) and hide other rows.
     * Replaces all ESC + C0 control bytes with `?` so the cell stays
     * fixed-width.
     */
    function sanitiseForTerminal(text: string): string {
        return text.replace(/[\x00-\x1f\x7f]/g, '?');
    }

    const header = COLUMNS.map((c) => pad(c.label, c.width)).join('  ');
    const divider = COLUMNS.map((c) => '-'.repeat(c.width)).join('  ');

    const dataLines = rows.map((row) => {
        const platform = row.platform_resolved ?? row.platform_hint;
        const def = row.is_default ? 'yes' : 'no';
        const label = sanitiseForTerminal(row.label ?? '');
        const cells = [
            pad(row.site_id, COLUMNS[0].width),
            pad(row.url, COLUMNS[1].width),
            pad(platform, COLUMNS[2].width),
            pad(def, COLUMNS[3].width),
            pad(label, COLUMNS[4].width),
        ];
        return cells.join('  ');
    });

    const total = rows.length;
    const summary =
        `${String(total)} configured site${total === 1 ? '' : 's'}` +
        (defaultSiteId !== null
            ? ` (default: ${defaultSiteId})`
            : ' (no default configured)');

    return [
        summary,
        '',
        header,
        divider,
        ...dataLines,
        '',
        'Use `yt-builder-mcp test-site <site_id>` to verify connectivity.',
    ];
}

/** Default deps wired to the real store. */
export const DEFAULT_LIST_SITES_DEPS: ListSitesDeps = {
    load: loadSitesFile,
};

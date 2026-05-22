/**
 * Playwright config — yt-builder-mcp E2E skeleton.
 *
 * Wave 5 ships the skeleton only. Implementation is gated on Wave 6
 * (10/10 6-Axis Audit pass) and an explicit Thomas-approval to run the
 * suite against dev.wootsup.com (mutating writes against a real site).
 *
 * Until then every spec is `.skip`'d.
 */

import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.YTB_MCP_E2E_BASE_URL ?? 'https://dev.wootsup.com/wordpress';

export default defineConfig({
    testDir: '.',
    fullyParallel: false, // builder writes mutate shared state — never parallelise
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1, // single-worker — see fullyParallel above
    reporter: process.env.CI ? [['github'], ['list']] : 'list',
    timeout: 60_000,
    expect: { timeout: 10_000 },

    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
        // Bearer token sourced from env — never committed.
        extraHTTPHeaders: process.env.YTB_MCP_E2E_BEARER
            ? { Authorization: `Bearer ${process.env.YTB_MCP_E2E_BEARER}` }
            : undefined,
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});

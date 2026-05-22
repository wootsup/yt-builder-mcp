import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        globals: false,
        environment: 'node',
        include: ['tests/**/*.test.ts'],
        env: {
            // Prevent src/index.ts from auto-starting the stdio server
            // when imported by tests.
            YTB_MCP_NO_AUTORUN: '1',
        },
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html'],
            include: ['src/**/*.ts'],
            exclude: [
                'src/**/*.test.ts',
                'src/**/*.d.ts',
                // setup.ts contains the interactive wizard; its smoke test
                // exercises a different surface (clack-prompts mocking).
                'src/setup.ts',
                // stdio entry-point — short-circuited under YTB_MCP_NO_AUTORUN.
                // The real work lives in `src/server.ts` and `src/tools/**`,
                // both of which carry full coverage.
                'src/index.ts',
                // Setup-CLI is the interactive Clack wizard. Covered separately
                // by tests/setup/cli.test.ts (clack mocked); the bulk of the
                // file is keystroke-driven UI not amenable to unit coverage.
                'src/setup-cli.ts',
            ],
            // Wave G.8 — NO-COMPROMISE coverage floor per Design §11 Achse 4.
            // Lines/Statements 85%, Branches 80%, Functions 85%.
            thresholds: {
                lines: 85,
                statements: 85,
                branches: 80,
                functions: 85,
            },
        },
        poolOptions: {
            threads: {
                maxThreads: 2,
                minThreads: 1,
            },
        },
    },
});

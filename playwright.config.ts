import { defineConfig, devices } from '@playwright/test';
import { resolveBaseUrl } from './tests/e2e/_fixtures/resolve-base-url.cjs';

/**
 * BuddyNext Playwright config.
 *
 * Runs every spec under `tests/e2e/` across three viewports:
 *   - desktop (1440x900 Chrome)
 *   - ipad    (Apple iPad gen 7)
 *   - mobile  (iPhone 14)
 *
 * The base URL is resolved from the Local site this plugin is installed in (see
 * resolve-base-url.mjs) — never a hardcoded dev host, since BuddyNext ships to
 * anyone. Set BN_BASE_URL to override (CI, or a non-Local setup).
 * Set BN_PRO=1 to unmask Pro-only journeys that are otherwise `test.fixme()`.
 */
export default defineConfig({
    testDir: './tests/e2e',
    // Disables the per-minute comment rate limit for the serial suite (see the
    // file for why) so a burst of test comments doesn't 429 and flake specs red.
    globalSetup: './tests/e2e/_fixtures/global-setup.ts',
    // Sweeps the data the specs wrote to the target site. Without it a shared
    // dev site accumulates test posts/users/spaces every run, and that junk gets
    // reported as product bugs. BN_E2E_KEEP_DATA=1 keeps it for debugging.
    globalTeardown: './tests/e2e/_fixtures/global-teardown.ts',
    timeout: 30_000,
    expect: { timeout: 5_000 },
    fullyParallel: false, // WP shares one DB, so don't blast it
    workers: 1, // single worker against one shared WP + DB
    // A single shared WP + php-fpm saturates on a cold serial run, producing
    // transient timeouts that pass on retry (not logic failures). Retry to keep
    // the gate trustworthy; a spec that fails on every retry is a real defect.
    retries: process.env.CI ? 2 : 1,
    reporter: [['html', { outputFolder: 'tests/e2e/_report', open: 'never' }], ['list']],
    use: {
        baseURL: resolveBaseUrl(),
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 10_000,
        navigationTimeout: 15_000,
    },
    projects: [
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
        {
            name: 'ipad',
            use: { ...devices['iPad (gen 7)'] },
        },
        {
            name: 'mobile',
            use: { ...devices['iPhone 14'] },
        },
    ],
});

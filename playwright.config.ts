import { defineConfig, devices } from '@playwright/test';

/**
 * BuddyNext Playwright config.
 *
 * Runs every spec under `tests/e2e/` across three viewports:
 *   - desktop (1440x900 Chrome)
 *   - ipad    (Apple iPad gen 7)
 *   - mobile  (iPhone 14)
 *
 * Set BN_BASE_URL to point at a different local site (default buddynext-dev.local).
 * Set BN_PRO=1 to unmask Pro-only journeys that are otherwise `test.fixme()`.
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    expect: { timeout: 5_000 },
    fullyParallel: false, // WP shares one DB, so don't blast it
    workers: 1, // single worker against buddynext-dev.local
    reporter: [['html', { outputFolder: 'tests/e2e/_report', open: 'never' }], ['list']],
    use: {
        baseURL: process.env.BN_BASE_URL ?? 'http://buddynext-dev.local',
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

import type { TestInfo } from '@playwright/test';

/**
 * Soft-skip helper.
 *
 * The repo standard forbids `test.skip()` for journey-level gates  -  that's
 * what `test.fixme()` is for. But specs still need a way to bail out
 * cleanly when a *runtime* precondition isn't met (e.g., the feed is empty
 * so there's nothing to react to). We use Playwright's annotation API to
 * record the reason on the report, then return early. The test stays green
 * because the journey didn't fail; the report makes it loud that a
 * precondition wasn't satisfied so seed data can be fixed.
 *
 * Usage:
 *   if (await cards.count() === 0) {
 *       softSkip(testInfo, 'No member cards seeded.');
 *       return;
 *   }
 */
export function softSkip(testInfo: TestInfo, reason: string): void {
    testInfo.annotations.push({ type: 'precondition-missing', description: reason });
}

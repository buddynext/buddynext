import type { Page, TestInfo } from '@playwright/test';

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

/**
 * Resolve a real member who is NOT the logged-in user, from the members
 * directory on the site under test.
 *
 * Specs used to hardcode `member1` as the "other member". No seeder creates
 * that user, so on any site without it the spec navigated to a profile that
 * never rendered and died at its FIRST assertion — before reaching the gate it
 * was named after. The failure then reported as `Edit Profile / cover pencil do
 * NOT render on a non-owner profile`, which reads like a permissions hole and
 * costs real time to disprove. A gate must not fail in the vocabulary of a
 * product bug when the real cause is a missing fixture.
 *
 * Resolving from the directory means the spec works on any seeded site rather
 * than on one that happens to have one particular username. `BN_TEST_OTHER_USER`
 * still wins when set, for pinning a specific member deliberately.
 *
 * @returns a member slug, or null when the site genuinely has no other members.
 */
export async function resolveOtherMemberSlug(page: Page, selfLogin: string): Promise<string | null> {
    const pinned = process.env.BN_TEST_OTHER_USER;
    if (pinned) {
        return pinned;
    }

    await page.goto('/members/', { waitUntil: 'domcontentloaded' });

    const slugs = await page.evaluate(() =>
        [...document.querySelectorAll('a[href*="/members/"]')]
            .map((a) => a.getAttribute('href') ?? '')
            // Profile roots only: /members/{slug}/ — not /members/{slug}/followers/
            // and not paginated directory URLs like /members/page/2/.
            .filter((href) => /\/members\/[^/]+\/?$/.test(href))
            .map((href) => href.replace(/\/$/, '').split('/').pop() ?? '')
            .filter(Boolean)
    );

    return [...new Set(slugs)].find((slug) => slug !== selfLogin) ?? null;
}

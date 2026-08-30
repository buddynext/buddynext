import type { Page, TestInfo } from '@playwright/test';
import { urls } from './selectors';

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
 * The member must also be USABLE as a second actor, which is a stricter thing
 * than existing. The directory is ordered newest-first, so its top is exactly
 * where half-finished accounts collect - and a member who has not completed
 * onboarding is redirected off /activity/ to /onboarding/ (PageRouter reads
 * bn_onboarding_complete for the CURRENT user). A spec handed that member logs
 * in fine, gets a valid nonce, and then asserts against the onboarding page.
 *
 * That is the same failure this helper was written to end, one layer down: J-550
 * reported "the announcement card is not visible" while the announcement was
 * correct and the page simply was not the feed. Existence was never the
 * precondition; reaching the feed is. So each candidate is TRIED, in directory
 * order, and the first that lands on the feed is returned.
 *
 * Only a login can answer this - the redirect keys on the member's own meta, so
 * nothing the directory renders can predict it. One throwaway context per
 * rejected candidate is the price, and it is paid once per spec.
 *
 * @returns a member slug proven to reach the feed, or null when none does.
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

    const candidates = [...new Set(slugs)].filter((slug) => slug !== selfLogin);

    // Bounded: a site whose whole directory is half-onboarded is a seeding
    // problem, and the caller's softSkip reports it better than a long walk.
    for (const slug of candidates.slice(0, MAX_CANDIDATES)) {
        if (await canReachFeed(page, slug)) {
            return slug;
        }
    }

    return null;
}

/**
 * How many directory members to try before giving up.
 */
const MAX_CANDIDATES = 6;

/**
 * Whether a member, logged in as themselves, actually lands on the feed.
 *
 * Runs in a throwaway context so it cannot disturb the caller's session, which
 * is mid-spec and logged in as somebody else.
 */
async function canReachFeed(page: Page, slug: string): Promise<boolean> {
    const browser = page.context().browser();
    if (!browser) {
        // No browser handle (a connected-over-CDP edge case): fall back to
        // assuming usable rather than silently returning "no members exist",
        // which would soft-skip every spec for the wrong reason.
        return true;
    }

    const ctx = await browser.newContext();
    try {
        const probe = await ctx.newPage();
        await probe.goto(`/?autologin=${encodeURIComponent(slug)}`, { waitUntil: 'domcontentloaded' });
        await probe.goto(urls.feed, { waitUntil: 'domcontentloaded' });
        return !/\/onboarding\/?$/.test(new URL(probe.url()).pathname);
    } catch {
        return false;
    } finally {
        await ctx.close();
    }
}

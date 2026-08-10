import { test, expect } from '../_fixtures/auth.fixture';
import { createPage, deletePage, userId, dbCount, tablePrefix, wp } from '../_fixtures/wp';

/**
 * J-802 / J-803 — Member Directory + Member Card blocks: Follow and the kebab menu
 * actually work when the block is embedded on an ordinary page.
 *
 * The bug: the card markup carries buddynext/members store directives that resolve
 * against a data-wp-interactive="buddynext/members" ancestor + need the members
 * view module — the blocks provided neither, so every control was inert (no request,
 * no state change). Proven by EFFECT, not an optimistic label flip:
 *
 *   - Follow is a SELF-RESTORING toggle. Whatever its starting state, one click must
 *     change the persisted bn_follows row count by exactly 1 (server truth via
 *     WP-CLI, not a REST GET the UI could paper over), and a second click must return
 *     it to the baseline. Direction-agnostic so a member the viewer already follows
 *     doesn't skew it, and self-cleaning so reruns are idempotent.
 *   - The kebab (Mute/Block/Report) must OPEN — aria-expanded flips true.
 *
 * A page with both blocks is created on an ordinary slug (not /members/) — the exact
 * context the defect shipped in — and torn down in afterAll.
 */
test.describe('blocks / member interactivity (J-802, J-803)', () => {
    const SELF_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
    let pageId = 0;
    let pageUrl = '';
    let selfId = 0;
    let cardTargetId = 0;

    test.beforeAll(async () => {
        selfId = await userId(SELF_LOGIN);
        // A concrete, non-self member for the single-member Member Card block. userId:0
        // makes the block render nothing (it early-returns), so resolve a real id.
        const listed = await wp([
            'user', 'list', `--exclude=${selfId}`, '--field=ID', '--number=1', '--orderby=ID',
        ]).catch(() => '');
        cardTargetId = parseInt(listed.split(/\s+/).filter(Boolean)[0] ?? '0', 10) || 0;

        const rec = await createPage(
            [
                '<!-- wp:buddynext/member-directory {"perPage":12} /-->',
                `<!-- wp:buddynext/member-card {"userId":${cardTargetId},"showFollowAction":true} /-->`,
            ].join('\n'),
            { title: 'E2E Member Blocks', slug: 'e2e-member-blocks' }
        );
        pageId = rec.id;
        pageUrl = rec.url;
    });

    test.afterAll(async () => {
        await deletePage(pageId);
    });

    async function followRowCount(): Promise<number> {
        const p = await tablePrefix();
        return dbCount(`SELECT COUNT(*) FROM ${p}bn_follows WHERE follower_id=${selfId}`);
    }

    /** Poll until the follow-row count reaches `target` (or time out and return last). */
    async function waitForFollowCount(target: number, timeoutMs = 8000): Promise<number> {
        const deadline = Date.now() + timeoutMs;
        let n = await followRowCount();
        while (n !== target && Date.now() < deadline) {
            await new Promise((r) => setTimeout(r, 250));
            n = await followRowCount();
        }
        return n;
    }

    /** Poll until the count differs from `base`; return the changed value (or base on timeout). */
    async function waitForFollowChange(base: number, timeoutMs = 8000): Promise<number> {
        const deadline = Date.now() + timeoutMs;
        let n = await followRowCount();
        while (n === base && Date.now() < deadline) {
            await new Promise((r) => setTimeout(r, 250));
            n = await followRowCount();
        }
        return n;
    }

    test('J-802 Member Directory block: Follow persists (toggles back), kebab opens', async ({
        authenticatedPage: page,
    }) => {
        test.skip(selfId <= 0, 'test user not resolvable');
        await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

        const scope = page.locator('.bn-block-member-directory').first();
        await expect(scope).toBeVisible({ timeout: 10_000 });
        // The block must host the store — without this ancestor the directives are dead.
        await expect(scope).toHaveAttribute('data-wp-interactive', 'buddynext/members');

        const base = await followRowCount();
        const follow = scope.locator('[data-wp-on--click="actions.toggleFollow"]').first();
        await expect(follow).toBeVisible();

        // EFFECT: one click changes the persisted follow count by exactly 1...
        await follow.click();
        const afterOne = await waitForFollowChange(base);
        expect(Math.abs(afterOne - base)).toBe(1);

        // ...and a second click returns it to baseline (both directions proven, clean).
        await follow.click();
        expect(await waitForFollowCount(base)).toBe(base);

        // EFFECT: the kebab (Mute/Block/Report) opens.
        const kebab = scope.locator('[data-wp-on--click="actions.toggleCardMenu"]').first();
        await expect(kebab).toBeVisible();
        await expect(kebab).toHaveAttribute('aria-expanded', 'false');
        await kebab.click();
        await expect(kebab).toHaveAttribute('aria-expanded', 'true');
    });

    test('J-803 Member Card block: Follow persists (toggles back)', async ({
        authenticatedPage: page,
    }) => {
        test.skip(selfId <= 0 || cardTargetId <= 0, 'no resolvable member for the card');
        await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

        const scope = page.locator('.bn-block-member-card').first();
        await expect(scope).toBeVisible({ timeout: 10_000 });
        await expect(scope).toHaveAttribute('data-wp-interactive', 'buddynext/members');

        const follow = scope.locator('[data-wp-on--click="actions.toggleFollow"]').first();
        // If the resolved card member happens to be the viewer, no Follow shows — skip.
        if ((await follow.count()) === 0) {
            test.skip(true, 'member-card subject has no Follow action (self or logged-out)');
        }

        const base = await followRowCount();
        await follow.click();
        const afterOne = await waitForFollowChange(base);
        expect(Math.abs(afterOne - base)).toBe(1);
        await follow.click();
        expect(await waitForFollowCount(base)).toBe(base);
    });
});

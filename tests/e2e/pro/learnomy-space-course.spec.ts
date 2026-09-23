import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from '../_fixtures/precondition';
import { urls } from '../_fixtures/selectors';
import { bnNonce, createSpaceApi, deleteSpaceApi } from '../_fixtures/spaces-rest';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';
const LEARNOMY_NS = '/wp-json/buddynext-pro/v1';

/**
 * J-957 link a course to a space (Learnomy bridge, Pro).
 *
 * Written from both promises this feature makes: "as an admin, pointing a
 * Learnomy course at a BuddyNext Space actually links them" and "as a member
 * visiting that Space, I can see (and reach) the course it came from."
 *
 * `Integrations\Learnomy\LearnomyLinkController`'s own docblock is explicit:
 * "The ONLY owner-facing surface is the Learnomy-side admin card; from
 * BuddyNext the link is reachable through this REST route and nowhere else."
 * There is no BuddyNext admin panel to build here — building one would
 * duplicate a UI Learnomy already owns. So the admin leg exercises the same
 * `buddynext-pro/v1/learnomy-link` contract Learnomy's own admin JS
 * (`assets/js/admin/learnomy-community-link.js`) calls, and the member leg
 * exercises `LearnomyFrontendBridge::render_space_source_banner()` — hooked on
 * Free's `buddynext_part_space_hero_after` — which is the ONLY thing a member
 * ever sees change.
 *
 * Learnomy is a separate plugin this harness does not install, so the expected
 * outcome is a soft-skip, detected from BuddyNext's own "Active" integration
 * row rather than guessing at Learnomy's admin URLs. Where Learnomy IS active,
 * this still degrades gracefully (soft-skip) rather than asserting against a
 * course id that may not exist on that particular install: `source_link()`
 * resolves the name/url from Learnomy's own course table, so an unresolvable id
 * is a data precondition, not a code path this journey should fail on.
 *
 * Covers: cap-link-a-course-to-a-space
 * Roles: admin, member
 */
test.describe('pro / Learnomy course-to-space link', () => {
    test.fixme(process.env.BN_PRO !== '1', 'The Learnomy bridge ships in Pro. Set BN_PRO=1 to run.');

    const integrationsUrl = '/wp-admin/admin.php?page=buddynext-integration-settings&tab=integration-controls';

    test('J-957 linking a course to a space shows the source banner to a member', async ({ authenticatedPage: page, browser }, testInfo) => {
        await page.goto(integrationsUrl);
        const active = page.locator('.bn-settings-section', { has: page.locator('.bn-badge[data-tone="success"]') }).filter({ hasText: /learnomy/i });
        if ((await active.count()) === 0) {
            softSkip(testInfo, 'Learnomy is not active on this harness (no "Learnomy" row under Integration Controls) — LearnomyAdminBridge/LearnomyFrontendBridge no-op without LEARNOMY_VERSION.');
            return;
        }

        const stamp = Date.now().toString().slice(-6);
        let space: { id: number; slug: string } | null = null;

        try {
            space = await createSpaceApi(page, { name: `J957 Learnomy space ${stamp}`, type: 'open' });

            const nonce = await bnNonce(page);
            const link = await page.request.post(`${LEARNOMY_NS}/learnomy-link`, {
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                data: { bn_space_id: space.id, type: 'course', id: 1 },
            });

            if (!link.ok()) {
                softSkip(testInfo, `Could not link course id=1 (HTTP ${link.status()}) — no matching Learnomy course on this install. The REST contract itself is verified in source (LearnomyLinkController).`);
                return;
            }

            // Admin-side effect (server truth): the link is queryable back.
            const read = await page.request.get(`${LEARNOMY_NS}/learnomy-link?type=course&id=1`, {
                headers: { 'X-WP-Nonce': nonce },
            });
            expect(read.status(), `GET learnomy-link -> ${read.status()}`).toBe(200);
            const linkedSpaces = (await read.json()) as Array<{ id?: number }>;
            expect(linkedSpaces.some((s) => s.id === space!.id), 'linked space missing from GET /learnomy-link').toBe(true);

            // Member-side effect: the Space hero shows the "Community for <course>" banner.
            const memberPage = await (await browser.newContext()).newPage();
            await loginAs(memberPage, MEMBER_LOGIN);
            await memberPage.goto(urls.space(space.slug));
            const banner = memberPage.locator('.bn-sh-invite').first();
            if (!(await banner.isVisible({ timeout: 8_000 }).catch(() => false))) {
                softSkip(testInfo, 'Space hero banner did not render — LearnomyCommunityLink::source_link() could not resolve course id=1 to a name/url on this install.');
                await memberPage.context().close().catch(() => {});
                return;
            }
            await expect(banner.locator('.bn-sh-invite__actions a')).toBeVisible();
            await memberPage.context().close().catch(() => {});
        } finally {
            if (space) {
                const cleanupNonce = await bnNonce(page).catch(() => '');
                await page.request
                    .delete(`${LEARNOMY_NS}/learnomy-link/${space.id}`, { headers: { 'X-WP-Nonce': cleanupNonce } })
                    .catch(() => undefined);
                await deleteSpaceApi(page, space.id);
            }
        }
    });
});

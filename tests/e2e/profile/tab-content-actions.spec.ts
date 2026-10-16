import { test, expect, type Browser, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId, ensureUser, setUserMeta } from '../_fixtures/wp';
import {
    openMemberSession,
    restPost,
    type MemberSession,
} from '../_fixtures/feed-wave1.helpers';
import { restList, idsOf } from '../_fixtures/effects';

/**
 * Wave-3 PROFILE A3 tab CONTENT — EFFECT-BASED (J-730..J-734).
 *
 * The MEMBER-ACTION-COVERAGE-MATRIX marks the A3 Posts / Replies / Likes /
 * Scheduled / Media tab-content rows MISSING: the only A3 spec that existed
 * (profile/view.spec.ts) asserted "a tab OR stats is visible" — presence, not
 * content. Every test here instead asserts the REAL thing a member promises the
 * tab shows, seen by a SECOND viewer where "who sees what" is the point:
 *
 *   J-730 Posts tab — a post the owner actually authored is rendered as a card
 *         in the Posts panel for a different viewer (not "a tab exists").
 *   J-731 Replies tab — a reply the owner posted on an activity is rendered in
 *         the Replies panel for a different viewer.
 *   J-732 Likes tab — a post the owner reacted to is rendered in the Likes panel
 *         for a different viewer (owner-liked, viewer-visible).
 *   J-733 Scheduled tab — owner-only: present + lists the queued post for the
 *         owner; ABSENT from the nav for another viewer, and that viewer cannot
 *         reach the queued post at the /scheduled/ URL (gated, not broken).
 *   J-734 Media tab — the integration-on branch this harness demands: the tab is
 *         present AND its panel (.bn-media-shell) actually renders (J-724 already
 *         covers the toggle-OFF retire path; this covers the panel working).
 *
 * Content is SEEDED through the real REST write path (POST /posts, /comments,
 * /reactions/toggle) using the owner's own session + wp_rest nonce — never raw
 * SQL — so the rows carry every canonical side effect (feed index, counts). The
 * action a viewer then SEES is asserted in the rendered tab. Every seeded row is
 * removed in a finally, so reruns are idempotent.
 *
 * Actors: A = varundubey (owner, admin — has schedule-post). B = bn_e2e_target
 * (a seeded member used as the "different viewer"). Selectors are declared
 * locally (repo rule: never edit the shared selectors.ts) from
 * templates/parts/profile/{posts,replies}-panel.php, templates/partials/
 * media-tab.php and templates/parts/nav-bar.php.
 *
 * Covers: cap-run-an-activity-feed-members-post-to, cap-let-members-react-comment-and-reply, cap-schedule-a-post-to-publish-later
 * Roles: admin, member
 * Note: J-730 (Posts tab) covers the feed promise, J-731/J-732 (Replies/Likes)
 * cover react-comment-reply, J-733 (Scheduled tab, Pro) covers
 * schedule-a-post-to-publish-later; every one is viewed by B (member) via
 * loginAs while A (admin) authors through a REST session. J-734 (Media tab
 * panel) does not map to any current CAPABILITIES.md row and is left unpinned.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

const memberUrl = (login: string) => `/members/${login}/`;
const repliesUrl = (login: string) => `/members/${login}/replies/`;
const likesUrl = (login: string) => `/members/${login}/likes/`;
const scheduledUrl = (login: string) => `/members/${login}/scheduled/`;
const mediaUrl = (login: string) => `/members/${login}/media/`;

// ---- live markup selectors ------------------------------------------------
const HERO = '.bn-pf-hero';
const TAB_CONTENT = '.bn-pf-tab-content';
const POST_CARD = '.bn-post-card';
const REPLY_CARD = '.bn-reply-card';
const MEDIA_SHELL = '.bn-media-shell';
const SCHEDULED_TAB = 'a.bn-tab[href$="/scheduled/"]';
const MEDIA_TAB = 'a.bn-tab[href$="/media/"]';
const ANY_TAB = 'a.bn-tab[role="tab"]';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `viewer "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    // A fresh member is force-redirected to onboarding, so B never lands on the
    // real profile pages it needs to view. Mark B onboarded.
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

type CreatedPost = { id: number; status: string };

/** Create a post as the given session (real REST write path). Returns its id. */
async function createPost(
    sess: MemberSession,
    content: string,
    extra: Record<string, unknown> = {}
): Promise<CreatedPost> {
    const { status, body } = await restPost<{ id?: number; ID?: number; status?: string }>(
        sess.request,
        sess.nonce,
        '/posts',
        { type: 'text', content, privacy: 'public', ...extra }
    );
    expect(status, `POST /posts must create the post (got ${status})`).toBe(201);
    const id = Number(body.id ?? body.ID ?? 0);
    expect(id, 'created post must carry a server id').toBeGreaterThan(0);
    return { id, status: String(body.status ?? 'published') };
}

/** Delete a post as the given session. Best-effort teardown. */
async function deletePost(sess: MemberSession, id: number): Promise<void> {
    if (!id) return;
    await sess.request
        .delete(`/wp-json/buddynext/v1/posts/${id}`, { headers: { 'X-WP-Nonce': sess.nonce } })
        .catch(() => undefined);
}

/** Open the owner's authoring session, run body, always close the context. */
async function withOwnerSession(
    browser: Browser,
    fn: (sess: MemberSession) => Promise<void>
): Promise<void> {
    const sess = await openMemberSession(browser, A_LOGIN);
    try {
        await fn(sess);
    } finally {
        await sess.ctx.close();
    }
}

test.describe('profile / A3 tab content (effect-based)', () => {
    test('J-730 Posts tab renders the owner\'s own post to a viewer', async ({ page, browser }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `Fresh tomatoes from the garden this morning ${stamp}`;
        await withOwnerSession(browser, async (owner) => {
            const post = await createPost(owner, content);
            try {
                // A DIFFERENT viewer opens the owner's Posts tab (the base URL).
                await loginAs(page, B_LOGIN);
                await page.goto(memberUrl(A_LOGIN));
                await expect(page.locator(HERO).first()).toBeVisible();
                await expect(
                    page.locator(`${TAB_CONTENT} ${POST_CARD}`).filter({ hasText: content }).first(),
                    'the authored post must render as a card in the Posts tab for a viewer'
                ).toBeVisible();
                // EFFECT: the viewer's read of the owner's feed route carries the post.
                const feed = await restList(page, `/users/${A_ID}/feed`);
                expect(feed.status, 'viewer GET /users/{A}/feed').toBe(200);
                expect(idsOf(feed.data), 'the post is in the owner\'s feed route for the viewer').toContain(post.id);
            } finally {
                await deletePost(owner, post.id);
            }
        });
    });

    test('J-731 Replies tab renders a reply the owner posted, to a viewer', async ({ page, browser }) => {
        const stamp = Date.now().toString().slice(-6);
        const postBody = `Anyone have a good recipe for plum jam ${stamp}`;
        const replyBody = `Try adding a little lemon zest ${stamp}`;
        await withOwnerSession(browser, async (owner) => {
            const post = await createPost(owner, postBody);
            // The owner replies on their own activity (real /comments write path).
            const { status, body } = await restPost<{ id?: number }>(
                owner.request,
                owner.nonce,
                '/comments',
                { object_type: 'post', object_id: post.id, content: replyBody }
            );
            expect(status, `POST /comments must create the reply (got ${status})`).toBeLessThan(300);
            const commentId = Number(body.id ?? 0);
            try {
                await loginAs(page, B_LOGIN);
                await page.goto(repliesUrl(A_LOGIN));
                await expect(page.locator(HERO).first()).toBeVisible();
                await expect(
                    page.locator(`${TAB_CONTENT} ${REPLY_CARD}`).filter({ hasText: replyBody }).first(),
                    'the owner\'s reply must render in the Replies tab for a viewer'
                ).toBeVisible();
                // EFFECT: the replies route lists that reply, attached to that post.
                const replies = await restList(page, `/users/${A_ID}/replies`);
                expect(replies.status, 'viewer GET /users/{A}/replies').toBe(200);
                const row = replies.items.find((r) => Number(r.id) === commentId);
                expect(row, 'the reply is in the owner\'s replies route for the viewer').toBeTruthy();
                expect(Number(row?.post_id), 'the reply row points at its post').toBe(post.id);
                expect(String(row?.content ?? ''), 'the reply row carries the typed text').toContain(replyBody);
            } finally {
                if (commentId) {
                    await owner.request
                        .delete(`/wp-json/buddynext/v1/comments/${commentId}`, {
                            headers: { 'X-WP-Nonce': owner.nonce },
                        })
                        .catch(() => undefined);
                }
                await deletePost(owner, post.id);
            }
        });
    });

    test('J-732 Likes tab renders a post the owner reacted to, to a viewer', async ({ page, browser }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `Sunrise from the top of the ridge today ${stamp}`;
        await withOwnerSession(browser, async (owner) => {
            const post = await createPost(owner, content);
            // The owner reacts to the post (real /reactions/toggle write path).
            const { status } = await restPost(owner.request, owner.nonce, '/reactions/toggle', {
                object_type: 'post',
                object_id: post.id,
                emoji: 'like',
            });
            expect(status, `POST /reactions/toggle must succeed (got ${status})`).toBeLessThan(300);
            try {
                await loginAs(page, B_LOGIN);
                await page.goto(likesUrl(A_LOGIN));
                await expect(page.locator(HERO).first()).toBeVisible();
                await expect(
                    page.locator(`${TAB_CONTENT} ${POST_CARD}`).filter({ hasText: content }).first(),
                    'a post the owner liked must render in the Likes tab for a viewer'
                ).toBeVisible();
                // EFFECT: the likes route lists the post the owner reacted to.
                const likes = await restList(page, `/users/${A_ID}/likes`);
                expect(likes.status, 'viewer GET /users/{A}/likes').toBe(200);
                expect(idsOf(likes.data), 'the liked post is in the owner\'s likes route').toContain(post.id);
            } finally {
                // Un-react so the like row does not leak into later runs, then delete.
                await restPost(owner.request, owner.nonce, '/reactions/toggle', {
                    object_type: 'post',
                    object_id: post.id,
                    emoji: 'like',
                }).catch(() => undefined);
                await deletePost(owner, post.id);
            }
        });
    });

    test('J-733 Scheduled tab is owner-only and gates its content from other viewers', async ({ page, browser }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `Reminder: the farmers market opens at eight tomorrow ${stamp}`;
        // 7 days out, UTC Y-m-d H:i:s (the format the scheduler expects).
        const future = new Date(Date.now() + 7 * 24 * 3600 * 1000)
            .toISOString()
            .replace('T', ' ')
            .slice(0, 19);

        await withOwnerSession(browser, async (owner) => {
            const post = await createPost(owner, content, { scheduled_at: future });
            expect(post.status, 'the post must be queued as scheduled').toBe('scheduled');
            try {
                // Owner: the Scheduled tab is present and lists the queued post.
                await loginAs(page, A_LOGIN);
                await page.goto(scheduledUrl(A_LOGIN));
                await expect(page.locator(HERO).first()).toBeVisible();
                await expect(
                    page.locator(SCHEDULED_TAB).first(),
                    'the owner sees the Scheduled tab'
                ).toBeVisible();
                await expect(
                    page.locator(`${TAB_CONTENT} ${POST_CARD}`).filter({ hasText: content }).first(),
                    'the owner\'s Scheduled tab lists the queued post'
                ).toBeVisible();
                // EFFECT: the owner's scheduled-posts route holds it, and the public
                // feed route does not (queued, not published).
                const queued = await restList(page, '/me/scheduled-posts');
                expect(queued.status, 'owner GET /me/scheduled-posts').toBe(200);
                expect(idsOf(queued.data), 'the queued post is in the owner\'s scheduled route').toContain(post.id);
                expect(idsOf((await restList(page, `/users/${A_ID}/feed`)).data), 'a scheduled post is not in the feed yet').not.toContain(post.id);

                // A different viewer: the Scheduled tab is absent from the nav...
                await loginAs(page, B_LOGIN);
                await page.goto(memberUrl(A_LOGIN));
                await expect(page.locator(HERO).first()).toBeVisible();
                await expect(
                    page.locator(SCHEDULED_TAB),
                    'the Scheduled tab must not render for another viewer'
                ).toHaveCount(0);

                // ...and hitting the URL directly never exposes the queued post
                // (gated, not broken: the profile still renders).
                await page.goto(scheduledUrl(A_LOGIN));
                await expect(page.locator(HERO).first()).toBeVisible();
                await expect(
                    page.locator(TAB_CONTENT).getByText(content),
                    'a viewer must never see the owner\'s scheduled post'
                ).toHaveCount(0);
                // EFFECT: REST is gated the same way for the other member: not in the
                // owner's feed, not in the viewer's own queue, and not readable by id.
                expect(idsOf((await restList(page, `/users/${A_ID}/feed`)).data), 'viewer feed read omits the scheduled post').not.toContain(post.id);
                expect(idsOf((await restList(page, '/me/scheduled-posts')).data), 'the viewer\'s own queue omits it').not.toContain(post.id);
                const single = await restList(page, `/posts/${post.id}`);
                expect(single.status, 'a viewer cannot read the scheduled post by id').toBeGreaterThanOrEqual(400);
                expect(JSON.stringify(single.data ?? null), 'the refusal does not leak the text').not.toContain(content);
            } finally {
                await deletePost(owner, post.id);
            }
        });
    });

    test('J-734 Media tab is present and its panel renders (integration on)', async ({ page }) => {
        // This harness runs with WPMediaVerse active and the media nav integration
        // ON (buddynext_integration_media_nav=1) — the branch to assert here. The
        // OFF/retire path is covered by profile-tabs.spec.ts J-724; this asserts the
        // ON path actually paints a working panel, not just that a tab exists.
        await loginAs(page, A_LOGIN);
        await page.goto(mediaUrl(A_LOGIN));
        await expect(page.locator(HERO).first()).toBeVisible();
        await expect(
            page.locator(MEDIA_TAB).first(),
            'the Media tab is present while the integration is on'
        ).toBeVisible();
        await expect(
            page.locator(`${TAB_CONTENT} ${MEDIA_SHELL}`).first(),
            'the Media panel must actually render (not a broken tab body)'
        ).toBeVisible();
        // EFFECT: the nav the profile route resolves carries the Media tab too, so
        // the panel is there because the integration is on, not by template accident.
        const prof = await restList(page, `/users/${A_ID}/profile`);
        expect(prof.status, 'GET /users/{A}/profile').toBe(200);
        const tabs = (((prof.data ?? {}) as { nav?: { tabs?: Array<{ id?: string }> } }).nav?.tabs ?? []).map((t) => String(t.id ?? ''));
        expect(tabs, 'route nav lists the Media tab').toContain('media');
        // Sanity: the tab bar rendered more than just Media.
        expect(await page.locator(ANY_TAB).count(), 'the profile tab bar renders').toBeGreaterThan(1);
    });
});

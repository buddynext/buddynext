import { test, expect, type Browser, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId, ensureUser, setUserMeta } from '../_fixtures/wp';
import {
    openMemberSession,
    restPost,
    type MemberSession,
} from '../_fixtures/feed-wave1.helpers';

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
        const content = `e2e post ${stamp}`;
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
            } finally {
                await deletePost(owner, post.id);
            }
        });
    });

    test('J-731 Replies tab renders a reply the owner posted, to a viewer', async ({ page, browser }) => {
        const stamp = Date.now().toString().slice(-6);
        const postBody = `e2e host post ${stamp}`;
        const replyBody = `e2e reply ${stamp}`;
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
        const content = `e2e liked ${stamp}`;
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
        const content = `e2e scheduled ${stamp}`;
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
        // Sanity: the tab bar rendered more than just Media.
        expect(await page.locator(ANY_TAB).count(), 'the profile tab bar renders').toBeGreaterThan(1);
    });
});

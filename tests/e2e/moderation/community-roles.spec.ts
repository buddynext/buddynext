import { test, expect, type Page } from '@playwright/test';
import { ensureUser, setUserMeta, deleteUserMeta, wp, dbScalar, tablePrefix, endActiveAnnouncements } from '../_fixtures/wp';
import { loginAs } from '../_fixtures/actor';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-993 / J-994 community roles do their job without WordPress admin.
 *
 * Written from the owner's promise: "I made someone a community moderator or a
 * community admin under Roles & Capabilities, and they can do that job on the
 * site, while ordinary members cannot." Every actor here is a WordPress
 * Subscriber; only bn_community_role differs (permissions program, card
 * 10322714806).
 *
 * Effect read-back: the removed comment is soft-deleted in the database and no
 * longer served; the announcement is the one members are shown, and then not.
 *
 * Covers: cap-let-members-react-comment-and-reply, cap-post-announcements
 * Roles: moderator, community-admin, member
 */

const MOD = 'bn_jr_moderator';
const CADMIN = 'bn_jr_community_admin';
const MEMBER = 'bn_jr_member';
const READER = 'bn_jr_reader';

const php = async (code: string): Promise<string> => (await wp(['eval', code])).trim();

test.describe('moderation / community roles', () => {
    let modId = 0;
    let cadminId = 0;
    let memberId = 0;
    let readerId = 0;

    test.beforeAll(async () => {
        // About 25 WP-CLI boots (four members, then their meta). On a site running the
        // full partner suite each boot is ~2 s, past the 30 s default for a hook.
        test.setTimeout(120_000);
        modId = await ensureUser(MOD, `${MOD}@example.com`, 'Priya Raman');
        cadminId = await ensureUser(CADMIN, `${CADMIN}@example.com`, 'Marcus Webb');
        memberId = await ensureUser(MEMBER, `${MEMBER}@example.com`, 'Hannah Ortiz');
        readerId = await ensureUser(READER, `${READER}@example.com`, 'Leo Brandt');
        // Settled members: past the first-run wizard.
        for (const id of [modId, cadminId, memberId, readerId]) {
            await setUserMeta(id, 'bn_onboarding_complete', '1');
        }
        await setUserMeta(modId, 'bn_community_role', 'moderator');
        await setUserMeta(cadminId, 'bn_community_role', 'admin');
    });

    test.afterAll(async () => {
        await deleteUserMeta(modId, 'bn_community_role').catch(() => undefined);
        await deleteUserMeta(cadminId, 'bn_community_role').catch(() => undefined);
    });

    test('J-993 a community moderator removes a member\'s comment; an ordinary member cannot', async ({ page }) => {
        const stamp = Date.now().toString().slice(-6);
        const postId = Number(
            await php(
                `$r = ( new \\BuddyNext\\Feed\\PostService() )->create( ${memberId}, array( 'type' => 'text', 'content' => 'Who is bringing chairs to the park cleanup on Sunday? ${stamp}' ) ); echo is_array( $r ) ? (int) $r['id'] : (int) $r;`
            )
        );
        expect(postId).toBeGreaterThan(0);
        const commentId = Number(
            await php(`echo (int) buddynext_service( 'comments' )->create( ${memberId}, 'post', ${postId}, 'Cheap sunglasses 90% off, click my profile link ${stamp}' );`)
        );
        expect(commentId).toBeGreaterThan(0);
        const postUrl = await php(`echo \\BuddyNext\\Core\\PageRouter::post_url( ${postId} );`);
        const node = (p: Page) => p.locator(`.bn-comment-card[data-comment-id="${commentId}"]`).first();

        try {
            // Another ordinary member sees the comment but has no Delete on it.
            await loginAs(page, READER);
            await page.goto(postUrl);
            await expect(node(page)).toBeVisible({ timeout: 10_000 });
            await expect(node(page).locator('.bn-comment__delete-btn')).toHaveCount(0);

            // The community moderator removes it through the real Delete + confirm.
            await loginAs(page, MOD);
            await page.goto(postUrl);
            await expect(node(page)).toBeVisible({ timeout: 10_000 });
            await node(page).locator('.bn-comment__delete-btn').first().click();
            const modal = page.locator('.bn-modal-backdrop').last();
            await expect(modal).toBeVisible({ timeout: 5_000 });
            const deleted = page.waitForResponse((r) => r.request().method() === 'DELETE' && r.url().includes(`/comments/${commentId}`));
            await modal.getByRole('button', { name: 'Delete', exact: true }).first().click();
            expect((await deleted).status(), 'DELETE /comments/{id} as a community moderator').toBeLessThan(300);

            // EFFECT: soft-deleted in the database and gone after a reload.
            const p = await tablePrefix();
            expect(await dbScalar(`SELECT is_deleted FROM ${p}bn_comments WHERE id=${commentId};`)).toBe('1');
            await page.goto(postUrl);
            await expect(page.locator(`.bn-comment-card[data-comment-id="${commentId}"]`)).toHaveCount(0);
        } finally {
            await php(`( new \\BuddyNext\\Feed\\PostService() )->delete( ${postId}, 1 ); echo 'ok';`).catch(() => undefined);
        }
    });

    test('J-994 a community admin posts and ends a site-wide announcement; a moderator has no announcement tool', async ({ page }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `The community garden opens for spring planting this Saturday at 9am ${stamp}`;
        const tool = page.locator('button.bn-composer__tool[aria-label="Post as announcement"]').first();
        let postId = 0;

        try {
            await endActiveAnnouncements();

            await loginAs(page, MOD);
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            await expect(tool, 'a moderator is not offered site-wide announcements').toHaveCount(0);

            await loginAs(page, CADMIN);
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            await page.locator(sel.composerTextarea).first().fill(content);
            await tool.click();
            await expect(tool).toHaveAttribute('aria-pressed', 'true');
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            // EFFECT: the announcements route lists it.
            postId = Number(await php(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND content LIKE %s ORDER BY id DESC LIMIT 1", ${cadminId}, '%${stamp}%' ) );`));
            expect(postId).toBeGreaterThan(0);
            // The announcement every member is shown right now (what the pinned banner reads).
            const active = async () => Number(await php(`$a = buddynext_service( 'feed' )->active_announcement( ${memberId} ); echo (int) ( $a['id'] ?? 0 );`));
            expect(await active(), 'members are shown it').toBe(postId);

            // The same community admin ends it from the card.
            await page.goto(urls.feed);
            const card = page.locator('.bn-post-card--announcement').filter({ hasText: content }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            const ended = page.waitForResponse((r) => r.url().includes(`/feed/announcements/${postId}/end`));
            await card.locator('.bn-post-card__ann-end').click();
            expect((await ended).status(), 'POST /feed/announcements/{id}/end as a community admin').toBe(200);
            await expect(page.getByText('Announcement ended').first()).toBeVisible({ timeout: 5_000 });
            expect(await active(), 'no longer shown to members').not.toBe(postId);
        } finally {
            if (postId > 0) {
                await php(`( new \\BuddyNext\\Feed\\PostService() )->delete( ${postId}, 1 ); echo 'ok';`).catch(() => undefined);
            }
        }
    });
});

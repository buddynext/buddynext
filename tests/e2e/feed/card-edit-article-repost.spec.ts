import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-811 Edit only where it works, J-812 blog article card follows its post,
 * J-813 deleting a repost un-shares.
 *
 *   J-811  A member's own blog article card and caption-less share offer Delete
 *          but not Edit (there is no text to edit; Edit used to fail with "This
 *          post cannot be edited"). A text post's Edit still opens the editor.
 *   J-812  A blog post published without a featured image, then given an image
 *          and a new title, shows both on its feed card without re-publishing.
 *   J-813  Deleting a repost from its menu lowers the original's share count
 *          and removes the share record, so the member can share it again.
 *
 * Fixtures come from the services over wp-cli and are removed in afterAll; the
 * actions under test (menu, delete) run through the real UI.
 */

test.describe.configure({ mode: 'serial' });

const MEMBER = 'bn_e2e_cards_member';

let memberId = 0;
let textPost = 0;
let original = 0;
let wpPost = 0;
let articleCard = 0;
let shareCaptionless = 0;

async function php(code: string): Promise<string> {
    const out = (await wp(['eval', code])).trim();
    return out.split('\n').pop() ?? '';
}

async function menuItems(page: Page, postId: number): Promise<string[]> {
    await page.goto(`/p/${postId}/`);
    const card = page.locator('.bn-post-card').first();
    await expect(card).toBeVisible();
    return card.locator('button.bn-post-card__menu-item, a.bn-post-card__menu-item').evaluateAll((els) =>
        els.map((e) => (e.textContent ?? '').replace(/\s+/g, ' ').trim())
    );
}

test.beforeAll(async () => {
    memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'Cards Member');
    await wp(['user', 'meta', 'update', String(memberId), 'bn_onboarding_complete', '1']);
    await wp(['user', 'set-role', String(memberId), 'author']);
    // Sites that require email verification hold unverified members' posts.
    await wp(['user', 'meta', 'update', String(memberId), 'buddynext_email_verified', '1']);

    const ids = JSON.parse(
        await php(`
            $ps = new \\BuddyNext\\Feed\\PostService();
            $text = $ps->create( ${memberId}, array( 'type' => 'text', 'content' => 'E2E cards text post' ) );
            $orig = $ps->create( 1, array( 'type' => 'text', 'content' => 'E2E cards original to share' ) );
            $wp_post = wp_insert_post( array( 'post_title' => 'E2E article first title', 'post_content' => 'Body.', 'post_status' => 'publish', 'post_author' => ${memberId} ) );
            $card = \\BuddyNext\\Feed\\BlogPostListener::card_id_for_post( (int) $wp_post );
            echo wp_json_encode( array( 'text' => $text, 'orig' => $orig, 'wp' => $wp_post, 'card' => $card ) );
        `)
    );
    textPost = Number(ids.text);
    original = Number(ids.orig);
    wpPost = Number(ids.wp);
    articleCard = Number(ids.card);
    expect(articleCard, 'publishing a blog post creates an article card').toBeGreaterThan(0);
});

test.afterAll(async () => {
    await php(`
        $ps = new \\BuddyNext\\Feed\\PostService();
        ( new \\BuddyNext\\Feed\\ShareService() )->unshare( ${memberId}, ${original} );
        foreach ( array( ${textPost}, ${original} ) as $id ) { if ( $id ) { $ps->delete( $id, 1 ); } }
        if ( ${wpPost} > 0 ) { wp_delete_post( ${wpPost}, true ); }
        echo 'ok';
    `);
});

test('J-811 Edit is offered only on cards whose text can be edited', async ({ page }) => {
    shareCaptionless = Number(await php(`echo (int) ( new \\BuddyNext\\Feed\\ShareService() )->share( ${memberId}, ${original}, '' );`));
    expect(shareCaptionless).toBeGreaterThan(0);

    await loginAs(page, MEMBER);

    const article = await menuItems(page, articleCard);
    expect(article, 'article card: Delete, no Edit').toContain('Delete');
    expect(article).not.toContain('Edit');

    const share = await menuItems(page, shareCaptionless);
    expect(share, 'caption-less share: Delete, no Edit').toContain('Delete');
    expect(share).not.toContain('Edit');

    const text = await menuItems(page, textPost);
    expect(text, 'text post keeps Edit').toContain('Edit');
    const card = page.locator('.bn-post-card').first();
    await card.locator('.bn-post-card__menu').click();
    await card.locator('button.bn-post-card__menu-item', { hasText: 'Edit' }).click();
    await expect(page.locator('.bn-post-card__edit-input').first(), 'Edit opens the inline editor').toBeVisible();
    await expect(page.getByText('This post cannot be edited.')).toHaveCount(0);

    // Leave no share behind for J-813.
    await php(`( new \\BuddyNext\\Feed\\ShareService() )->unshare( ${memberId}, ${original} ); echo 'ok';`);
});

test('J-812 the article card shows an image and title set after publishing', async ({ page }) => {
    const before = JSON.parse(await php(`$p = ( new \\BuddyNext\\Feed\\PostService() ); $c = $p->hydrate( (array) $p->get( ${articleCard} ) ); echo wp_json_encode( $c['link_meta'] );`));
    expect(before.thumbnail ?? '', 'no image before').toBe('');

    const attachment = await php(`echo (int) get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1, 'fields' => 'ids' ) )[0];`);
    test.skip(Number(attachment) <= 0, 'No image attachment on this site.');
    await php(`set_post_thumbnail( ${wpPost}, ${attachment} ); wp_update_post( array( 'ID' => ${wpPost}, 'post_title' => 'E2E article updated title' ) ); echo 'ok';`);

    await loginAs(page, MEMBER);
    await page.goto(`/p/${articleCard}/`);
    const card = page.locator('.bn-post-card').first();
    await expect(card.locator('.bn-post-card__article-title'), 'edited title on the card').toHaveText('E2E article updated title');
    const expectedImage = await php(`echo get_the_post_thumbnail_url( ${wpPost}, 'medium_large' );`);
    await expect(card.locator('.bn-post-card__article-cover img'), 'image added after publish on the card').toHaveAttribute('src', expectedImage);
});

test('J-813 deleting a repost lowers the share count and allows sharing again', async ({ page }) => {
    const repost = Number(await php(`echo (int) ( new \\BuddyNext\\Feed\\ShareService() )->share( ${memberId}, ${original}, 'E2E repost with a comment' );`));
    expect(repost).toBeGreaterThan(0);
    const countOf = async () => Number(await php(`global $wpdb; echo (int) $wpdb->get_var( "SELECT share_count FROM {$wpdb->prefix}bn_posts WHERE id = ${original}" );`));
    const recordOf = async () => Number(await php(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_shares WHERE user_id = ${memberId} AND post_id = ${original}" );`));
    expect(await countOf()).toBe(1);

    await loginAs(page, MEMBER);
    await page.goto(`/p/${repost}/`);
    const card = page.locator('.bn-post-card').first();
    await card.locator('.bn-post-card__menu').click();
    page.once('dialog', (d) => void d.accept());
    await card.locator('button.bn-post-card__menu-item', { hasText: 'Delete' }).click();
    const confirm = page.getByRole('button', { name: /^delete$/i });
    if (await confirm.first().isVisible().catch(() => false)) {
        await confirm.first().click();
    }

    await expect.poll(countOf, { message: 'original share count drops' }).toBe(0);
    expect(await recordOf(), 'share record removed').toBe(0);
    const again = Number(await php(`$r = ( new \\BuddyNext\\Feed\\ShareService() )->share( ${memberId}, ${original}, '' ); echo is_wp_error( $r ) ? 0 : (int) $r;`));
    expect(again, 'the member can share it again').toBeGreaterThan(0);
});

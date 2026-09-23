import { test, expect } from '@playwright/test';

import { wp } from '../_fixtures/wp';

/**
 * J-810 built-in cookie notice (Privacy & Data > Cookie Consent).
 *
 * Covers: cap-show-a-built-in-cookie-consent-notice
 * notice, though `buddynext_cookie_consent` is a real, code-verified,
 * owner-configurable option. Flagging as friction.
 * Roles: anon
 *
 * What a logged-out visitor experiences on any page, not only BuddyNext pages:
 *
 *   - the notice shows, in the site's colours (not the BuddyNext default when the
 *     theme sets its own accent), and "Got it" hides it across reloads;
 *   - editing the privacy policy page shows it to everyone again;
 *   - at phone width it is a compact card that leaves the page usable.
 *
 * Runs on every project, so the mobile (iPhone 14, 390px) pass is the phone check.
 * The option and the privacy page's modified date are restored in afterAll.
 */

test.describe.configure({ mode: 'serial' });

let previousOption = '';
let privacyPath = '/';
let privacyId = 0;

async function php(code: string): Promise<string> {
    return (await wp(['eval', code])).trim();
}

test.beforeAll(async () => {
    previousOption = await php(`echo (string) get_option( 'buddynext_cookie_consent', '' );`);
    await wp(['option', 'update', 'buddynext_cookie_consent', '1']);
    privacyId = parseInt(await php(`echo (int) get_option( 'wp_page_for_privacy_policy' );`), 10) || 0;
    if (privacyId > 0) {
        privacyPath = new URL(await php(`echo get_permalink( ${privacyId} );`)).pathname;
    }
});

test.afterAll(async () => {
    if ('' === previousOption) {
        await wp(['option', 'delete', 'buddynext_cookie_consent']).catch(() => '');
    } else {
        await wp(['option', 'update', 'buddynext_cookie_consent', previousOption]);
    }
});

test('J-810 cookie notice shows on any page, remembers Got it, and returns when the policy changes', async ({ page }) => {
    await page.context().clearCookies();
    // A site may also run a consent plugin (this harness runs WPConsent). Its own
    // banner is not under test here, so the visitor has already answered it.
    const origin = new URL(page.url() === 'about:blank' ? (test.info().project.use.baseURL as string) : page.url()).origin;
    await page.context().addCookies([
        { name: 'wpconsent_preferences', value: '{"essential":true,"statistics":false,"marketing":false}', url: origin },
    ]);
    await page.goto(privacyPath);

    const notice = page.locator('[data-bn-cookie-consent]');
    await expect(notice, 'shown to a visitor who has not accepted').toBeVisible();

    // Site colours: the button follows the page's resolved accent inside the notice.
    const colours = await notice.evaluate((el) => {
        const btn = el.querySelector('.bn-btn') as HTMLElement;
        const probe = document.createElement('span');
        probe.style.color = 'var(--bn-accent)';
        el.appendChild(probe);
        const accent = getComputedStyle(probe).color;
        probe.remove();
        return { button: getComputedStyle(btn).backgroundColor, accent };
    });
    expect(colours.button, 'button uses the resolved site accent').toBe(colours.accent);

    // Phone width: compact, fully on screen, no sideways scroll.
    const box = await notice.boundingBox();
    const viewport = page.viewportSize();
    expect(box).not.toBeNull();
    if (box && viewport) {
        expect(box.y + box.height, 'fully on screen').toBeLessThanOrEqual(viewport.height);
        if (viewport.width <= 640) {
            expect(box.height, 'compact on a phone').toBeLessThan(viewport.height * 0.4);
        }
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

    // Got it hides it, and it stays hidden on reload.
    await notice.locator('[data-bn-cookie-accept]').click();
    await expect(notice).toHaveCount(0);
    await page.reload();
    await expect(page.locator('[data-bn-cookie-consent]'), 'stays hidden after accepting').toHaveCount(0);

    // Owner edits the privacy policy page: the notice returns for this visitor.
    test.skip(privacyId === 0, 'No privacy policy page is set on this site.');
    const modified = await php(`echo get_post_field( 'post_modified_gmt', ${privacyId} );`);
    try {
        await php(
            `global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'ID' => ${privacyId} ) ); clean_post_cache( ${privacyId} );`
        );
        await page.reload();
        await expect(page.locator('[data-bn-cookie-consent]'), 'a changed policy asks again').toBeVisible();
    } finally {
        await php(
            `global $wpdb; $wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '${modified}' ), array( 'ID' => ${privacyId} ) ); clean_post_cache( ${privacyId} );`
        );
    }
});

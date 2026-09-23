import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from '../_fixtures/precondition';
import { urls } from '../_fixtures/selectors';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-951 let a member choose which pushes they get (Pro).
 *
 * Written from the member's promise: "I can turn off push for one kind of
 * notification (say, likes) without losing push for the rest." This is the
 * PREFERENCE surface, not device delivery: `WebPushAssets::render_panel()`
 * hooks `buddynext_notification_prefs_after` on Settings -> Notifications and
 * renders one `input[type=checkbox][data-type="<notif-type>"]` per catalog
 * entry inside `.bn-webpush__types`, wired to
 * `assets/js/web-push/store.js` `actions.toggleType`, which PUTs
 * `{ [type]: bool }` to `buddynext-pro/v1/me/push-prefs`
 * (`PushPrefsController`). `PushPrefService::get_all_push_prefs()` is the single
 * source both the checkbox's initial `checked` state and this spec's REST
 * verification read from.
 *
 * The per-type panel only renders when the site has web-push CONFIGURED
 * (`WebPushAssets::is_prefs_page()` -> `$web_configured`); without Firebase web
 * keys the section renders `.bn-prefs-empty` instead. This dev harness has no
 * VAPID/Firebase keys configured, so the realistic outcome here is a soft-skip
 * naming that precondition — the seam (REST contract + markup) is verified by
 * reading `PushPrefsController`/`WebPushAssets` in source; only the live browser
 * walk needs the site-level config this harness doesn't carry.
 *
 * Effect-based when configured: flip one type's checkbox off, confirm
 * `GET /me/push-prefs` reports that type false and every other type unchanged,
 * confirm persistence after reload, then flip it back on in `finally`.
 *
 * Covers: cap-let-members-choose-which-pushes-they-get
 * Roles: member
 */
test.describe('pro / push notification preferences', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Web push preferences are a Pro feature. Set BN_PRO=1 to run.');

    const typesSection = '.bn-webpush__types';
    const typeCheckbox = '.bn-webpush__types input[type="checkbox"][data-type]';
    const prefsUrl = '/wp-json/buddynext-pro/v1/me/push-prefs';

    test('J-951 toggling a single push type off leaves the others untouched (server truth)', async ({ page }, testInfo) => {
        await loginAs(page, MEMBER_LOGIN);

        const bnNonce = async (): Promise<string> =>
            page.evaluate(async () => {
                const r = await fetch('/wp-json/buddynext/v1/auth/nonce', { credentials: 'same-origin' });
                const j = (await r.json().catch(() => ({}))) as { nonce?: string };
                return j.nonce ?? '';
            });

        const readPrefs = async (): Promise<Record<string, boolean>> => {
            const nonce = await bnNonce();
            const res = await page.request.get(prefsUrl, { headers: { 'X-WP-Nonce': nonce } });
            expect(res.status(), `GET push-prefs -> ${res.status()}`).toBe(200);
            const body = (await res.json()) as { prefs?: Record<string, boolean> };
            return body.prefs ?? {};
        };

        await page.goto(urls.settingsNotifications);

        const section = page.locator(typesSection).first();
        if (!(await section.isVisible({ timeout: 8_000 }).catch(() => false))) {
            softSkip(testInfo, 'Web push is not configured on this harness (no Firebase VAPID keys) — the per-type panel only renders once an admin adds them under BuddyNext -> Push.');
            return;
        }

        const checkbox = page.locator(typeCheckbox).first();
        await expect(checkbox).toBeVisible();
        const type = await checkbox.getAttribute('data-type');
        expect(type, 'push-pref checkbox missing data-type').toBeTruthy();

        const wasOn = await checkbox.isChecked();

        try {
            const before = await readPrefs();
            const otherTypes = Object.keys(before).filter((k) => k !== type);

            // Flip: toggle this one type off (or on, if it started off) and confirm
            // the server records the new value for THIS type only.
            if (wasOn) {
                await checkbox.uncheck();
            } else {
                await checkbox.check();
            }
            await expect.poll(async () => (await readPrefs())[type as string], { timeout: 8_000 }).toBe(!wasOn);

            // Every other type's stored value is untouched by this one PUT.
            const after = await readPrefs();
            for (const other of otherTypes) {
                expect(after[other], `unrelated push pref "${other}" changed`).toBe(before[other]);
            }

            // Persistence: the checkbox re-renders in the new state after reload.
            await page.goto(urls.settingsNotifications);
            const checkboxAfter = page.locator(`.bn-webpush__types input[type="checkbox"][data-type="${type}"]`).first();
            await expect(checkboxAfter).toBeVisible({ timeout: 8_000 });
            if (wasOn) {
                await expect(checkboxAfter).not.toBeChecked();
            } else {
                await expect(checkboxAfter).toBeChecked();
            }
        } finally {
            // Restore the original state for other specs/runs.
            const nonce = await bnNonce();
            if (nonce && type) {
                await page.request
                    .put(prefsUrl, {
                        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                        data: { [type]: wasOn },
                    })
                    .catch(() => undefined);
            }
        }
    });
});

import { expect, type Page } from '@playwright/test';

/**
 * Switch the browser session to a specific member via the dev autologin
 * mu-plugin (?autologin=<login>).
 *
 * The mu-plugin self-guards with `is_user_logged_in()` — so it refuses to switch
 * accounts while a session already exists. Multi-actor specs (A acts, then B
 * responds) therefore MUST clear cookies before each switch, which this does.
 * Verifies a logged-in cookie actually landed so a silent auth failure surfaces
 * here, not three assertions later as a mystery "control missing".
 */
export async function loginAs(page: Page, login: string): Promise<void> {
    await page.context().clearCookies();
    await page.goto(`/?autologin=${encodeURIComponent(login)}`, {
        waitUntil: 'domcontentloaded',
    });
    const cookies = await page.context().cookies();
    expect(
        cookies.some((c) => c.name.startsWith('wordpress_logged_in')),
        `autologin=${login} did not establish a session (no wordpress_logged_in cookie)`
    ).toBeTruthy();
}

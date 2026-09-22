import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from '../_fixtures/precondition';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-953 save a search (Pro).
 *
 * Written from the member's promise: "the search I ran shows up again later so
 * I don't have to retype it." The UI lives on Free's own
 * `templates/search/results.php` ("Saved searches" aside card, rendered for any
 * logged-in visitor), talking to Pro's `buddynext-pro/v1/me/saved-searches`
 * (`SavedSearchController` / `SavedSearchService`) — this is the free/pro seam
 * (`assets/js/search/store.js` `actions.saveCurrent` / `loadSavedList` /
 * `deleteSaved`), not a Pro-only screen.
 *
 * FINDING (verified in source, not asserted here as if it existed): despite the
 * capability's name, there is no "alert" mechanism anywhere in
 * `SavedSearchService`/`SavedSearchController` — no cron, no notify flag, no
 * opt-in field in the save form. A saved search here is retrieve-and-run only.
 * This journey proves the half that is real (save -> persists -> lists -> runs
 * -> deletes) and does not fabricate an alert opt-in the code does not have.
 *
 * Effect-based: save is confirmed via `.bn-search-saved__msg`, persistence via
 * a full reload re-fetching the list (`loadSavedList` runs on every page visit
 * for a logged-in member, independent of the current query), and "run" via the
 * item's link carrying the original query back into the URL. Deleted in the
 * test itself (not just `finally`) so removal is also verified as part of the
 * promise, not assumed.
 *
 * Covers: cap-save-a-search-and-be-alerted
 * Roles: member
 */
test.describe('pro / saved search', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Saved searches persist through a Pro REST collection. Set BN_PRO=1 to run.');

    const savedCard = '.bn-search-saved';
    const nameInput = '#bn-saved-name';
    const saveBtn = '.bn-search-saved__save button[data-wp-on--click="actions.saveCurrent"]';
    const msg = '.bn-search-saved__msg';
    const item = (name: string) => `.bn-search-saved__item:has(.bn-search-saved__run:text-is("${name}"))`;

    test('J-953 a saved search persists, lists, and re-runs the original query', async ({ page }, testInfo) => {
        await loginAs(page, MEMBER_LOGIN);
        const stamp = Date.now().toString().slice(-6);
        const term = `j953term${stamp}`;
        const name = `J905 saved ${stamp}`;

        await page.goto(`/activity/search/?q=${term}`);
        const card = page.locator(savedCard).first();
        if (!(await card.isVisible({ timeout: 8_000 }).catch(() => false))) {
            softSkip(testInfo, 'Saved-searches card absent — logged-out view, or the search hub is off.');
            return;
        }

        await page.locator(nameInput).fill(name);
        await page.locator(saveBtn).first().click();

        const status = page.locator(msg).first();
        await expect(status).toBeVisible({ timeout: 8_000 });
        const statusText = (await status.innerText()).trim();
        if (/pro/i.test(statusText) && /could not save|requires/i.test(statusText)) {
            softSkip(testInfo, `Saved-search REST route unavailable (${statusText}) — BuddyNext Pro not active on this collection.`);
            return;
        }

        // Persistence: revisit the search hub on a DIFFERENT query — the saved
        // list is fetched independently of the current query string, so this
        // proves the row survived a reload rather than living only in memory.
        await page.goto('/activity/search/?q=unrelated');
        const row = page.locator(item(name)).first();
        await expect(row).toBeVisible({ timeout: 8_000 });

        // Effect: running the saved search carries the ORIGINAL term back into the URL.
        const runLink = row.locator('.bn-search-saved__run').first();
        const href = await runLink.getAttribute('href');
        expect(href, 'saved search link missing href').toBeTruthy();
        expect(decodeURIComponent(href as string)).toContain(term);

        // Cleanup, verified: delete removes it from the list (not just from the DB).
        await row.locator('button[data-wp-on--click="actions.deleteSaved"]').first().click();
        await expect(page.locator(item(name))).toHaveCount(0, { timeout: 5_000 });
    });
});

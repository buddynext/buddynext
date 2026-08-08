import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import type { Page } from '@playwright/test';

/**
 * J-68 repeater day-30 lifecycle, J-69 emptied-group Add Entry.
 *
 * Written from the MEMBER'S promise, not from the implementation:
 * "every entry I add shows exactly what I typed after a reload, an entry I
 * delete stays deleted, and the Add button never dies."
 *
 * Exists because the 2026-07-16 wave shipped 4 repeater bugs (Zoho #40911)
 * that all sat behind journeys which only created data and never came back:
 * deletes that resurrected on reload, values missing from entries 2+, and an
 * Add Entry button that silently died once a group was emptied. Day-1 create
 * journeys can never catch day-30 behaviour — this spec IS the day-30 loop.
 *
 * Round-trip assertions use auto-waiting locators (expect.toHaveValue /
 * toHaveCount poll until the Interactivity store finishes repainting the
 * repeater), never a one-shot DOM snapshot — a snapshot read races hydration.
 * Every added row is removed again in the same test, so the account is left
 * exactly as it was found even when an assertion fails mid-way.
 */
test.describe('profile / repeater lifecycle (Work Experience)', () => {
    const user = process.env.BN_TEST_USER ?? 'varundubey';
    const container = '#bn-ep-work-experience-entries';
    const addBtn = 'button.bn-ep-add-entry[data-group="work_experience"]';
    const entrySel = `${container} .bn-ep-repeater-entry`;
    const companyInputs = `${container} input[name$="[work_company]"]`;
    const save = 'button[type="submit"]';

    /** Index of the entry whose company === value, or -1 (auto-waited beforehand). */
    const indexOfCompany = (page: Page, value: string): Promise<number> =>
        page.locator(companyInputs).evaluateAll(
            (els, v) => els.findIndex((e) => (e as HTMLInputElement).value === v),
            value
        );

    /** All work_company values currently rendered, read as live .value props. */
    const companies = (page: Page): Promise<string[]> =>
        page.locator(companyInputs).evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));

    const expectCompany = async (page: Page, value: string, present: boolean): Promise<void> => {
        await expect.poll(async () => (await companies(page)).includes(value), { timeout: 8000 }).toBe(present);
    };

    const expectEntryCount = async (page: Page, n: number): Promise<void> => {
        await expect.poll(async () => (await companies(page)).length, { timeout: 8000 }).toBe(n);
    };

    const saveAndReload = async (page: Page): Promise<void> => {
        // Wait for the SAVE ITSELF, not for a navigation.
        //
        // The concern this helper was written around is real and still applies:
        // never race the reload against the in-flight PUT, or it is aborted and
        // nothing persists. What changed is the signal. Saving used to redirect
        // to the member profile, so waiting for that navigation was a usable
        // proxy for "the save finished". It no longer redirects — the member
        // stays on the edit screen, which is the point of the fix — so the proxy
        // is gone and we wait on the request instead.
        //
        // This is the stronger assertion anyway: it proves the PUT completed and
        // succeeded, rather than inferring it from a side effect.
        await Promise.all([
            page.waitForResponse(
                (r) => r.url().includes('/me/profile') && r.request().method() === 'PUT' && r.status() === 200,
                { timeout: 15000 }
            ),
            page.locator(save).first().click(),
        ]);
        await page.goto(urls.memberEdit(user));
        await expect(page.locator(entrySel).first()).toBeVisible();
    };

    /** Remove the entry at company===value and confirm it stays gone after save. */
    const removeCompany = async (page: Page, value: string): Promise<void> => {
        const i = await indexOfCompany(page, value);
        if (i < 0) return;
        await page.locator(entrySel).nth(i).locator('.bn-ep-repeater-remove').click();
        await saveAndReload(page);
    };

    test('J-68 add → verify typed values → add second → delete first → deleted stays deleted', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        await expect(page.locator(sel.app)).toBeVisible();
        if (!(await page.locator(container).count())) {
            softSkip(testInfo, 'Work Experience repeater not on the edit form.');
            return;
        }

        const stamp = Date.now().toString().slice(-6);
        const companyA = `e2e Alpha ${stamp}`;
        const companyB = `e2e Beta ${stamp}`;
        const baseCount = await page.locator(entrySel).count();

        try {
            // Add entry A and type into EVERY text sub-field the row offers — the
            // round-trip asserts what was typed, not that a box exists.
            await page.locator(addBtn).click();
            let idx = (await page.locator(entrySel).count()) - 1;
            await page.locator(`${container} input[name="work_experience[${idx}][work_company]"]`).fill(companyA);
            await page.locator(`${container} input[name="work_experience[${idx}][work_title]"]`).fill('QA Trumpet');
            await page.locator(`${container} input[name="work_experience[${idx}][work_start_date]"]`).fill('2020-02-02');
            await saveAndReload(page);

            // Round-trip truth: auto-waited comparison against what was TYPED.
            await expectCompany(page, companyA, true);
            idx = await indexOfCompany(page, companyA);
            await expect(page.locator(`${container} input[name="work_experience[${idx}][work_title]"]`)).toHaveValue('QA Trumpet');
            await expect(page.locator(`${container} input[name="work_experience[${idx}][work_start_date]"]`)).toHaveValue('2020-02-02');

            // Second entry keeps its OWN values (entries 2+ regression, #40911).
            await page.locator(addBtn).click();
            idx = (await page.locator(entrySel).count()) - 1;
            await page.locator(`${container} input[name="work_experience[${idx}][work_company]"]`).fill(companyB);
            await saveAndReload(page);
            await expectCompany(page, companyA, true);
            await expectCompany(page, companyB, true);

            // Delete A (a middle delete, since B renumbers under it): A must STAY
            // deleted after save + reload — the exact #40911 failure ("The entry
            // remains even after saving").
            await removeCompany(page, companyA);
            await expectCompany(page, companyA, false);
            await expectCompany(page, companyB, true);
            await expectEntryCount(page, baseCount + 1);
        } finally {
            // Leave the account exactly as found, even on a mid-test failure.
            await page.goto(urls.memberEdit(user));
            await removeCompany(page, companyA);
            await removeCompany(page, companyB);
        }

        await expectEntryCount(page, baseCount);
    });

    test('J-69 Add Entry still works after the member empties the group', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.memberEdit(user));
        if (!(await page.locator(container).count())) {
            softSkip(testInfo, 'Work Experience repeater not on the edit form.');
            return;
        }

        // Remove every row client-side, then Add Entry must still produce a
        // fresh schema row (the clone path seeds from a page-init snapshot when
        // the live DOM has nothing left — regression guard for the emptied-group
        // dead-button bug). NOTHING is saved: accept the beforeunload dialog and
        // reload to restore the member's real entries.
        page.on('dialog', (d) => void d.accept());
        const removeAll = page.locator(`${container} .bn-ep-repeater-remove`);
        while (await removeAll.count()) {
            await removeAll.first().click();
        }
        await expect(page.locator(entrySel)).toHaveCount(0);

        await page.locator(addBtn).click();
        await expect(page.locator(entrySel)).toHaveCount(1);
        // The rebuilt row is a BLANK schema row, not a clone of old values.
        await expect(page.locator(`${container} input[name="work_experience[0][work_company]"]`)).toHaveValue('');

        // Discard — the member's stored entries stay untouched.
        await page.goto(urls.memberEdit(user));
    });
});

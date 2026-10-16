import { test, expect } from '../_fixtures/auth.fixture';
import { dbScalar, tablePrefix } from '../_fixtures/wp';

/**
 * J-988 Add rule shows the fields of the chosen rule type (Pro).
 *
 * On Moderation -> Rules, the Add rule form reveals only the section that belongs
 * to the selected type: Keywords for a keyword block, Blocked domains for a link
 * block, the hourly cap for a rate limit, the report threshold for threshold
 * remove. Before 1.2.5 the Keyword fields always showed and the others could never
 * be revealed, so a Link block rule could not be given a domain.
 *
 * Effect: a Link block rule saved through the form stores the domain typed into
 * its now-reachable field (bn_mod_rules.config). The rule is deleted afterwards.
 *
 * Covers: cap-automate-moderation-with-rules
 * Roles: admin
 */

const RULES = '/wp-admin/admin.php?page=buddynext-moderation&tab=rules';
const SECTIONS: Record<string, string> = {
    keyword_block: '#bnpro_keywords',
    link_block: '#bnpro_domains',
    rate_limit: '#bnpro_max_posts_per_hour',
    threshold_remove: '#bnpro_reports_threshold',
};

test.describe('pro / moderation rule type fields', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Moderation rules are a Pro feature. Set BN_PRO=1 to run.');

    test('J-988 each rule type shows only its own fields, and a Link block rule saves its domain', async ({ authenticatedPage: page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Desktop journey.');
        const stamp = Date.now().toString().slice(-6);
        const name = `J988 Link ${stamp}`;
        const domain = `j988-${stamp}.example`;

        await page.goto(RULES);
        const type = page.locator('#bnpro_rule_type');
        await expect(type).toBeVisible();
        for (const chosen of Object.keys(SECTIONS)) {
            await type.selectOption(chosen);
            for (const [other, field] of Object.entries(SECTIONS)) {
                const control = page.locator(`form:has(#bnpro_rule_type) ${field}`);
                if (other === chosen) {
                    await expect(control, `${chosen}: its own field is shown`).toBeVisible();
                } else {
                    await expect(control, `${chosen}: the ${other} field is hidden`).toBeHidden();
                }
            }
        }

        try {
            await page.locator('#bnpro_name').fill(name);
            await type.selectOption('link_block');
            await page.locator('#bnpro_domains').fill(domain);
            await page.getByRole('button', { name: 'Add Rule' }).click();
            await page.waitForLoadState('load');

            // EFFECT: the stored rule is a link block carrying the domain.
            const prefix = await tablePrefix();
            const row = await dbScalar(
                `SELECT CONCAT_WS('|', rule_type, config) FROM ${prefix}bn_mod_rules WHERE name = '${name}' ORDER BY id DESC LIMIT 1`
            );
            expect(row.split('|')[0], `stored rule: ${row}`).toBe('link_block');
            expect(row, 'the domain from the Link block field is stored').toContain(domain);
        } finally {
            await page.goto(RULES).catch(() => {});
            const del = page
                .locator(`tr:has-text("${name}") form:has(input[name="action"][value="bnpro_delete_mod_rule"]) button[type="submit"]`)
                .first();
            if (await del.isVisible().catch(() => false)) {
                await del.click().catch(() => {});
                const dialog = page.locator('.bn-dialog-backdrop');
                if (await dialog.isVisible({ timeout: 3_000 }).catch(() => false)) {
                    await dialog.locator('.bn-dialog__ok').click().catch(() => {});
                }
                await page.waitForLoadState('load');
            }
        }
    });
});

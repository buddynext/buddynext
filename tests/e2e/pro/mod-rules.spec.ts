import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, restPost } from '../_fixtures/feed-wave1.helpers';

/**
 * J-954 automate moderation with rules (Pro).
 *
 * Written from the admin's promise: "a rule I build actually stops the content
 * it names — this is enforcement, not a dry-run list." Built at
 * Moderation -> Rules (`ModRulesAdmin`, tab registered under `moderation:rules`,
 * page `buddynext-moderation&tab=rules`); "Add Rule" posts to
 * `admin-post.php?action=bnpro_create_mod_rule` ->
 * `RulesService::create_rule()`. Enforcement itself runs through
 * `SafeguardIntegration::apply_pro_rules()`, hooked on Free's
 * `buddynext_safeguard_check` filter — the SAME gate every `POST /posts` (and
 * comment) passes through — so a `keyword_block` rule with severity `block`
 * rejects a matching submission with HTTP 422 (`RulesService::evaluate_keyword_block()`).
 *
 * Effect-based: the rule is created with a unique, never-seen-before keyword (so
 * it cannot collide with real content), then a real `POST /posts` containing
 * that keyword is made over REST — the exact call the composer itself makes —
 * and must be rejected. A rule that is merely LISTED in the admin table but not
 * wired into the safeguard filter would create fine and still let the post
 * through, failing this. A control post WITHOUT the keyword confirms the rule
 * is scoped to the keyword, not blocking all posts outright. The rule is
 * deleted via the admin "Delete" form in `finally`, leaving the rule set as
 * found.
 *
 * Covers: cap-automate-moderation-with-rules
 * Roles: admin
 */
test.describe('pro / moderation rules', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Moderation rules are a Pro feature. Set BN_PRO=1 to run.');

    const rulesAdminUrl = '/wp-admin/admin.php?page=buddynext-moderation&tab=rules';
    const ruleRow = (name: string) => `tr:has-text("${name}")`;
    const deleteButton = (name: string) =>
        `${ruleRow(name)} form:has(input[name="action"][value="bnpro_delete_mod_rule"]) button[type="submit"]`;

    test('J-954 a keyword_block rule (severity=block) rejects a matching post over REST', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const ruleName = `J906 Rule ${stamp}`;
        const keyword = `j954forbidden${stamp}`;
        let nonce = '';

        try {
            // ── Build the rule ──────────────────────────────────────────────
            await page.goto(rulesAdminUrl);
            await page.locator('#bnpro_name').fill(ruleName);
            await page.locator('#bnpro_rule_type').selectOption('keyword_block');
            await page.locator('#bnpro_keywords').fill(keyword);
            await page.locator('#bnpro_severity').selectOption('block');
            await page.getByRole('button', { name: 'Add Rule' }).click();

            // Effect (server truth): the rule is actually persisted.
            await page.goto(rulesAdminUrl);
            const row = page.locator(ruleRow(ruleName));
            await expect(row).toBeVisible({ timeout: 10_000 });
            await expect(row.locator('.bn-badge[data-tone="success"]')).toBeVisible();

            // ── Enforcement: the SAME safeguard gate every post create uses ──
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const blocked = await restPost<{ code?: string }>(page.request, nonce, '/posts', {
                content: `this post mentions ${keyword} and should be rejected`,
                privacy: 'public',
            });
            expect(blocked.status, `keyword-matching post -> ${blocked.status} (expected 422)`).toBe(422);

            // Control: a post WITHOUT the keyword is unaffected by this rule.
            const allowed = await restPost<{ id?: number }>(page.request, nonce, '/posts', {
                content: `j954 control post, no forbidden term, ${stamp}`,
                privacy: 'public',
            });
            expect(allowed.status, `control post -> ${allowed.status}`).toBeLessThan(300);
            const controlId = allowed.body.id ?? 0;
            if (controlId) {
                await page.request
                    .delete(`/wp-json/buddynext/v1/posts/${controlId}`, { headers: { 'X-WP-Nonce': nonce } })
                    .catch(() => undefined);
            }
        } finally {
            await page.goto(rulesAdminUrl).catch(() => {});
            const del = page.locator(deleteButton(ruleName)).first();
            if (await del.isVisible().catch(() => false)) {
                await del.click().catch(() => {});
                const dialog = page.locator('.bn-dialog-backdrop');
                if (await dialog.isVisible({ timeout: 3_000 }).catch(() => false)) {
                    await dialog.locator('.bn-dialog__ok').click().catch(() => {});
                }
            }
        }
    });
});

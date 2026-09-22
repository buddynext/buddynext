import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restGet } from '../_fixtures/feed-wave1.helpers';
import { wp } from '../_fixtures/wp';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

type ReactionCount = { count: number; has_reacted?: boolean; emoji?: string | null };

/**
 * J-952 add custom reactions beyond the defaults (Pro).
 *
 * Written from the two promises this feature actually makes: "as an admin I can
 * extend the built-in six reactions with a custom one" and "as a member the
 * custom reaction is a real reaction I can pick, not decoration." The admin
 * screen lives at Settings -> Engagement -> Reactions
 * (`CustomReactionsAdmin`, tab registered under `settings:reactions`, relocated
 * by the IA placement map to `buddynext-engagement&tab=reactions`); "Add
 * Reaction" posts to `admin-post.php?action=buddynextpro_add_custom_reaction`
 * and `CustomReactionsService::add_reaction()` stores it enabled by default.
 * `CustomReactionsService` hooks Free's `buddynext_reaction_types` filter
 * (`Core\Plugin`), so the new slug is merged into the SAME picker every post
 * card renders (`templates/parts/post-actions.php`,
 * `button[data-reaction-type="<slug>"]`) — no separate Pro picker to test.
 *
 * Effect-based: the admin leg reads the newly-created row back from the
 * Reactions table (server truth, not the form's own success banner). The member
 * leg reacts to a fresh own-post with the EXACT custom slug (not "any" reaction)
 * and reads `GET /reactions?object_type=post&object_id={id}` — `emoji` must be
 * the custom slug, proving the reaction picker offered a REAL, storable
 * reaction type rather than a chip that renders but reacts as something else.
 * The custom reaction is removed via the admin "Remove" form in `finally`,
 * leaving the reaction palette as found.
 *
 * `buddynext_reaction_types` carries a SECOND hook the member leg must satisfy:
 * `EntitlementGates::gate_reaction_set()` (priority 20, after Pro's own merge at
 * 10) truncates the merged list to `limits.reactions_set` entries for anyone
 * not exempt - the catalog default is 6 (PlanSeeder.php), i.e. exactly the
 * built-in set, so a member on the site's default plan never sees ANY custom
 * chip regardless of how many the admin has added. The member fixture
 * temporarily grants MEMBER_LOGIN an unlimited (0) `limits.reactions_set`
 * subscription for the run, removed in `finally`.
 *
 * Covers: cap-add-custom-reactions-beyond-the-defaults
 * Roles: admin, member
 */
test.describe('pro / custom reactions', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Custom reactions are a Pro feature. Set BN_PRO=1 to run.');

    const reactionsAdminUrl = '/wp-admin/admin.php?page=buddynext-engagement&tab=reactions';
    const emojiRadio = '.bn-cr-emoji-grid input[type="radio"][name="buddynextpro_slug"]';
    const addSubmit = 'form:has(input[name="action"][value="buddynextpro_add_custom_reaction"]) button[type="submit"]';
    const reactionRow = (slug: string) => `tr:has(code:text-is("${slug}"))`;
    const removeButton = (slug: string) =>
        `${reactionRow(slug)} form:has(input[name="action"][value="buddynextpro_remove_custom_reaction"]) button[type="submit"]`;
    const TIER_SLUG = 'bn-e2e-custom-reactions-tier';

    test('J-952 an admin-added custom reaction is a real, pickable reaction for a member', async ({ authenticatedPage: page }, testInfo) => {
        let slug = '';
        let createdId = 0;
        let nonce = '';
        let tierId = 0;
        const stamp = Date.now().toString().slice(-6);
        const label = `J952 Reaction ${stamp}`;

        try {
            const out = await wp([
                'eval',
                `global $wpdb;` +
                    ` $member = get_user_by( 'login', '${MEMBER_LOGIN}' );` +
                    ` if ( ! $member ) { echo 'TIER_ID:0'; return; }` +
                    ` $tsvc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_membership_tiers WHERE slug = %s", '${TIER_SLUG}' ) );` +
                    ` if ( $existing ) { $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => (int) $existing ) ); $tsvc->delete_tier( (int) $existing ); }` +
                    ` $tier_id = (int) $tsvc->create_tier( '${TIER_SLUG}', 'E2E Custom Reactions Tier', '', 0,` +
                    `   array( 'status' => 'active', 'price' => 2.0, 'billing_type' => 'recurring', 'billing_interval' => 'month',` +
                    `     'entitlements' => array( 'limits.reactions_set' => 0 ) ) );` +
                    ` ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->create_subscription(` +
                    `   $member->ID, $tier_id, 'offline', gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), '', 'active' );` +
                    ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();` +
                    ` echo 'TIER_ID:' . $tier_id;`,
            ]);
            tierId = Number((out.match(/TIER_ID:(\d+)/) ?? [])[1] ?? 0);
            expect(tierId, 'the E2E custom-reactions tier should be created and assigned').toBeGreaterThan(0);

            // ── Admin: add a custom reaction ────────────────────────────────
            await page.goto(reactionsAdminUrl);
            const radio = page.locator(emojiRadio).first();
            if ((await page.locator(emojiRadio).count()) === 0) {
                softSkip(testInfo, 'No pickable emoji slug available — the reaction palette is already at its 20-reaction cap on this site.');
                return;
            }
            await expect(radio).toBeVisible({ timeout: 10_000 });
            slug = (await radio.getAttribute('value')) ?? '';
            expect(slug, 'emoji radio rendered with no value attribute').toBeTruthy();
            // The tile's <img> sits on top of the radio inside the same
            // `.bn-cr-emoji-tile` <label> and always intercepts the pointer,
            // so `.check()` on the input directly times out. Click the
            // wrapping label instead, exactly what a real user does.
            await radio.locator('xpath=ancestor::label').first().click();
            await expect(radio, 'the emoji tile should now be selected').toBeChecked();
            await page.locator('#buddynextpro_label').fill(label);
            await page.locator(addSubmit).first().click();

            // Effect (server truth): the row is actually persisted, not just a
            // client-side optimistic add — reload the admin screen and read it back.
            await page.goto(reactionsAdminUrl);
            const row = page.locator(reactionRow(slug));
            await expect(row).toBeVisible({ timeout: 10_000 });
            await expect(row.locator('.bn-cr-status.is-on')).toBeVisible();

            // ── Member: react to a fresh own post with the custom reaction ──
            await loginAs(page, MEMBER_LOGIN);
            const body = `j952 custom-reaction target ${stamp}`;
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(body);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: body }).first()).toBeVisible({ timeout: 10_000 });

            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: body }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, body);
            expect(createdId).toBeGreaterThan(0);

            await card.locator(sel.postReact).first().click();
            const customBtn = card.locator(`[data-reaction-type="${slug}"]`).first();
            await expect(customBtn).toBeVisible({ timeout: 5_000 });
            await customBtn.click();

            // Effect: the server records THIS exact custom slug, not just "a" reaction —
            // proving the picker's custom chip is wired to the real reaction type.
            const readReaction = async (): Promise<ReactionCount> =>
                (await restGet<ReactionCount>(page.request, nonce, `/reactions?object_type=post&object_id=${createdId}`)).body;
            await expect.poll(async () => (await readReaction()).emoji, { timeout: 8_000 }).toBe(slug);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
            if (tierId) {
                await wp([
                    'eval',
                    `global $wpdb; $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => ${tierId} ) );` +
                        ` ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->delete_tier( ${tierId} );`,
                ]).catch(() => undefined);
            }
            if (slug) {
                await page.goto(reactionsAdminUrl).catch(() => {});
                const remove = page.locator(removeButton(slug)).first();
                if (await remove.isVisible().catch(() => false)) {
                    // Remove is gated by the shared JS confirm dialog (data-bn-confirm),
                    // not a native window.confirm — accept it via its own OK button.
                    // The shared shell dialog (assets/js/shell/dialog.js) renders as
                    // `.bn-modal-backdrop` with a plain `.bn-btn` OK button (no
                    // `.bn-dialog-backdrop`/`.bn-dialog__ok` — those classes don't
                    // exist anywhere in this codebase), so target it by role + the
                    // confirm label ("Remove") instead.
                    await remove.click().catch(() => {});
                    const dialog = page.locator('.bn-modal-backdrop').last();
                    if (await dialog.isVisible({ timeout: 3_000 }).catch(() => false)) {
                        await dialog.getByRole('button', { name: 'Remove', exact: true }).click().catch(() => {});
                    }
                }
            }
        }
    });
});

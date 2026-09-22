import { test, expect } from '@playwright/test';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-900 member sees the real pricing page.
 *
 * Covers: cap-sell-paid-membership-tiers
 * Roles: member
 *
 * CAPABILITIES.md (buddynext-pro): "Sell paid membership tiers? YES —
 * `bn_membership_tiers`, 5 `/membership` + 2 `/tiers` routes, admin
 * `bnpro-membership-tiers`." The buyer-facing half of that promise is the
 * pricing page itself: `[buddynext_membership_pricing]` /
 * `buddynext/membership-pricing` rendered via `templates/membership/pricing.php`
 * (MembershipShortcodes::render_pricing()), reached at
 * `MembershipPages::pricing_url()`.
 *
 * EFFECT asserted: a real, currently-sellable paid tier (created through
 * MembershipTierService, the same door the admin Tiers screen writes through)
 * appears on the page with its name and price — not just that SOME markup
 * renders. A page whose pricing block was stripped (Basecamp precedent this
 * class's own docblock names) renders the shell with zero plan cards, which
 * this catches and a bare "page loads" assertion would not.
 *
 * No admin browser interaction is needed to prove this cap: MembershipPages
 * auto-provisions the page and seeds the pricing block on activation, so there
 * is no admin-authored screen action distinct from creating the tier itself —
 * tier creation is exercised as a genuine admin action in
 * membership-plan-change.spec.ts and membership-profile-field-groups.spec.ts.
 * needed_roles() bootstrap-inferred {member} only for this promise (no
 * admin/setting/plan/gate keyword in the question), matching this file's
 * single-role scope.
 */
test.describe('pro / pricing page lists real plans', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Membership pricing only exists when Pro is active.');

    const SLUG = 'bn-e2e-pricing-plan';
    const MEMBER = 'bn_e2e_pricing_member';

    let tierId = 0;
    let tierName = '';
    let pricingUrl = '';

    test.beforeAll(async () => {
        await ensureUser(MEMBER, `${MEMBER}@example.test`, 'E2E Pricing Member');

        const stamp = Date.now().toString().slice(-6);
        tierName = `E2E Pricing Plan ${stamp}`;

        tierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG}' );` +
                    ` if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` $id = $svc->create_tier( '${SLUG}', '${tierName}', '', 0, array(` +
                    `   'status' => 'active', 'price' => 9.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );` +
                    ` echo (int) $id;`,
            ])
        );
        expect(tierId, 'the E2E pricing test tier should be created').toBeGreaterThan(0);

        pricingUrl = (
            await wp(['eval', `echo \\BuddyNextPro\\Membership\\MembershipPages::pricing_url();`])
        ).trim();
    });

    test.afterAll(async () => {
        if (tierId > 0) {
            await wp(['eval', `( new \\BuddyNextPro\\Membership\\MembershipTierService() )->delete_tier( ${tierId} );`]).catch(
                () => undefined
            );
        }
    });

    for (const width of [1280, 390]) {
        test(`the pricing page lists the plan and its price at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`/?autologin=${MEMBER}`, { waitUntil: 'domcontentloaded' });
            await page.goto(pricingUrl, { waitUntil: 'domcontentloaded' });

            const card = page.locator(`#bnpro-plan-${tierId}`);
            await expect(card, 'the seeded paid plan should render as a card').toBeVisible({ timeout: 10_000 });
            await expect(card).toContainText(tierName);
            await expect(card.locator('.bn-membership-pricing__price')).toContainText('9');

            // A member should be offered a real way to buy it, not a dead end -
            // either a live buy button/form, or an honest "no gateway" notice, but
            // never neither (which would read as the page being broken).
            const buyForm = card.locator('.bn-membership-pricing__form');
            const noGateway = card.locator('.bn-membership-pricing__unavailable');
            expect(
                (await buyForm.count()) + (await noGateway.count()),
                'the plan card must offer a buy action or explain why it cannot'
            ).toBeGreaterThan(0);
        });
    }
});

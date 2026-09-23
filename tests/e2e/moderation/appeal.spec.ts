import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { urls } from '../_fixtures/selectors';
import { readRestNonce, openMemberSession, restGet, restPost, type MemberSession } from '../_fixtures/feed-wave1.helpers';
import type { Page } from '@playwright/test';

/**
 * J-61 a suspended member appeals; an admin sees it in the appeals queue.
 *
 * Covers: cap-let-a-suspended-member-appeal
 * Roles: member, admin
 *
 * Written from the two promises this capability makes: a suspended member can
 * tell the moderation team it was a mistake, and the team actually sees that
 * appeal land somewhere they look. Verified against the real code paths —
 * ModerationController::suspend_user / submit_appeal / resolve_appeal,
 * ModerationService::get_pending_appeals / get_user_appeals, the appeal form in
 * templates/moderation/account-status.php (#bn-acct-appeal-msg,
 * .bn-acct-appeal__actions), and the admin queue table in
 * includes/Admin/ModerationQueue.php::render_appeals() — rather than assumed
 * from the card.
 *
 * Setup (suspending the target) and the final resolve are plumbing done over
 * REST as the admin, exactly like feed/report.spec.ts seeds its target post
 * over REST before exercising the real UI action. The two capability-bearing
 * actions ARE real browser walks: the member fills in and submits the
 * account-status appeal FORM (not a raw POST), and the admin reads the
 * wp-admin Appeals tab TABLE (not a REST list).
 */
const ADMIN_LOGIN = 'varundubey';

/** Read the current user's id off the post-composer's Interactivity context. */
async function readComposerUserId(page: Page): Promise<number> {
    return page.evaluate(() => {
        const el = document.querySelector('[data-wp-interactive="buddynext/post-composer"]');
        if (!el) {
            return 0;
        }
        try {
            const ctx = JSON.parse(el.getAttribute('data-wp-context') ?? '{}');
            return Number(ctx.userId) || 0;
        } catch {
            return 0;
        }
    });
}

test.describe('moderation / appeal (J-61)', () => {
    test('a suspended member can appeal, and the admin appeals queue shows it', async ({ authenticatedPage: page, browser }, testInfo) => {
        await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
        const adminNonce = await readRestNonce(page).catch(() => '');
        if (!adminNonce) {
            softSkip(testInfo, 'Could not resolve the admin wp_rest nonce from the feed.');
            return;
        }

        const target = await resolveOtherMemberSlug(page, ADMIN_LOGIN);
        if (!target) {
            softSkip(testInfo, 'No other usable member found to suspend for the appeal journey.');
            return;
        }

        let member: MemberSession | null = null;
        let targetUserId = 0;
        let appealId = 0;
        const stamp = Date.now().toString().slice(-8);
        const appealMessage = `j61 this suspension was a mistake, please review ${stamp}`;

        try {
            member = await openMemberSession(browser, target);
            targetUserId = await readComposerUserId(member.page);
            if (!targetUserId) {
                softSkip(testInfo, `Could not resolve a numeric user id for member "${target}".`);
                return;
            }

            // Setup (plumbing): admin suspends the member over the same REST route
            // the admin's own moderation UI calls (assets/js/moderation/store.js
            // suspendUser()) — require_moderator, so the admin's nonce clears it.
            const susp = await restPost<{ suspension_id?: number }>(page.request, adminNonce, `/users/${targetUserId}/suspend`, {
                reason: `E2E appeal journey ${stamp}`,
                duration_days: 1,
                hide_posts: false,
            });
            if (susp.status >= 300) {
                softSkip(testInfo, `Suspend setup failed (${susp.status}) — cannot exercise the appeal journey.`);
                return;
            }
            expect(susp.body.suspension_id ?? 0).toBeGreaterThan(0);

            // MEMBER role, real UI: the account-status page renders the suspension
            // banner + appeal form, and submitting it is a real browser
            // interaction — fill the textarea, click Submit — not a bare REST call.
            await member.page.goto('/me/account-status/', { waitUntil: 'domcontentloaded' });
            await expect(member.page.locator('#bn-acct-susp-title')).toBeVisible({ timeout: 10_000 });

            const appealField = member.page.locator('#bn-acct-appeal-msg');
            if (!(await appealField.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Appeal form not rendered on the account-status page (already appealed, or the feature is off).');
                return;
            }
            await appealField.fill(appealMessage);

            const submitBtn = member.page.locator('.bn-acct-appeal__actions button[type="submit"]');
            await Promise.all([
                // submitAppeal() reloads the page on success (assets/js/moderation/store.js).
                member.page.waitForEvent('load', { timeout: 15_000 }).catch(() => undefined),
                submitBtn.click(),
            ]);

            // Effect, member side: the page now shows "under review" instead of
            // the form.
            await expect(member.page.locator('.bn-acct-banner__appeal')).toBeVisible({ timeout: 10_000 });

            // Effect, server truth: the appeal really exists for this member —
            // and gives us the id to resolve in cleanup.
            const mine = await restGet<Array<{ id: number | string; message: string }>>(member.request, member.nonce, '/me/appeals');
            const mineAppeal = mine.body.find((a) => String(a.message) === appealMessage);
            expect(mineAppeal, 'submitted appeal not found in GET /me/appeals').toBeTruthy();
            appealId = Number(mineAppeal?.id ?? 0);
            expect(appealId).toBeGreaterThan(0);

            // ADMIN role, real UI: the wp-admin Appeals tab TABLE, not a REST list.
            await page.goto('/wp-admin/admin.php?page=buddynext-moderation&tab=appeals', { waitUntil: 'domcontentloaded' });
            const row = page.locator('table.widefat tbody tr', { hasText: appealMessage });
            await expect(row).toBeVisible({ timeout: 10_000 });
        } finally {
            // Approving lifts the exact appealed suspension (ModerationService::
            // resolve_appeal()), so this one call cleans up both the appeal and
            // the suspension. The unsuspend call below is a belt-and-suspenders
            // fallback for whichever step failed before the appeal existed.
            if (appealId) {
                await restPost(page.request, adminNonce, `/appeals/${appealId}/resolve`, {
                    decision: 'approved',
                    reviewer_note: 'e2e cleanup',
                }).catch(() => undefined);
            }
            if (targetUserId && adminNonce) {
                await page.request
                    .delete(`/wp-json/buddynext/v1/users/${targetUserId}/suspend`, { headers: { 'X-WP-Nonce': adminNonce } })
                    .catch(() => undefined);
            }
            if (member) {
                await member.ctx.close().catch(() => undefined);
            }
        }
    });
});

import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { dbCount, dbScalar, tablePrefix, userId } from '../_fixtures/wp';
import { idsOf, restJson } from '../_fixtures/effects';
import { bnNonce } from '../_fixtures/spaces-rest';
import { softSkip } from '../_fixtures/precondition';

/**
 * J-46 new thread + J-47 request accept. Both blocked on WPMediaVerse bridge.
 *
 * Covers: cap-integrate-with-wbcom-plugins
 * Roles: member
 *
 * Only runs with BN_WPMEDIAVERSE=1 (fixme otherwise), so this capability is
 * proven in CI only when that bridge flag is set.
 */
test.describe('messages / thread', () => {
    test.fixme(
        !process.env.BN_WPMEDIAVERSE,
        'J-46 / J-47  -  DM thread interactions require the WPMediaVerse bridge. Set BN_WPMEDIAVERSE=1 once it is active.',
    );

    test('thread surface accepts a message send', async ({ authenticatedPage: page }, testInfo) => {
        const me = await userId(process.env.BN_TEST_USER ?? 'varundubey');
        const other = await userId(process.env.BN_TEST_OTHER_USER ?? 'alice');
        expect(me, 'test member must exist').toBeGreaterThan(0);
        expect(other, 'recipient member must exist').toBeGreaterThan(0);
        const p = await tablePrefix();
        const text = `hi from playwright ${Date.now().toString().slice(-8)}`;
        const mine = `FROM ${p}mvs_messages WHERE sender_id = ${me} AND content LIKE '%${text}%'`;

        try {
            // `?to=` opens the existing thread with that member, or a compose-pending pane whose
            // first send creates it (messages/native.php:100-122).
            await page.goto(`${urls.messages}?to=${other}`, { waitUntil: 'domcontentloaded' });
            const thread = page.locator(sel.dmThread).first();
            await expect(thread).toBeVisible();

            // The composer field is `.bn-dm-composer__input` (parts/dm-composer.php:138); the
            // shared `.bn-dm-input textarea` matches nothing.
            const input = page.locator('.bn-dm-composer__input').first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'No composer for this recipient (DM access level or a block refuses the pair).');
                return;
            }
            await input.fill(text);
            await input.press('Enter');
            await expect(thread).toContainText(text, { timeout: 10_000 });

            // EFFECT: exactly one row for THIS message from THIS sender (the thread could echo the
            // text optimistically; a time-window count would pass on anybody's message).
            await expect.poll(() => dbCount(`SELECT COUNT(*) ${mine}`), { timeout: 10_000 }).toBe(1);
            const convId = Number(await dbScalar(`SELECT conversation_id ${mine} LIMIT 1`));
            expect(convId, 'message row carries no conversation').toBeGreaterThan(0);

            // EFFECT: the conversation route serves it back, and the inbox route lists the thread.
            const msgs = await restJson(page, `/wp-json/mvs/v1/conversations/${convId}/messages`);
            expect(msgs.status, `GET conversations/${convId}/messages`).toBe(200);
            expect(JSON.stringify(msgs.data), 'sent message missing from the conversation route').toContain(text);
            const inbox = await restJson(page, '/wp-json/mvs/v1/me/conversations?per_page=50');
            expect(inbox.status, 'GET me/conversations').toBe(200);
            if (idsOf(inbox.data).length < 50) {
                expect(idsOf(inbox.data), 'thread missing from the sender inbox route').toContain(convId);
            }

            // EFFECT: it survives a reload as a server-rendered bubble in that thread.
            await page.goto(`${urls.messages}?conversation=${convId}`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.dmThread).first()).toContainText(text, { timeout: 10_000 });
        } finally {
            // Remove only the message this run sent.
            const id = Number(await dbScalar(`SELECT id ${mine} LIMIT 1`).catch(() => '0'));
            if (id > 0) {
                const nonce = await bnNonce(page);
                await page.request
                    .delete(`/wp-json/mvs/v1/messages/${id}`, { headers: { 'X-WP-Nonce': nonce } })
                    .catch(() => undefined);
            }
        }
    });
});

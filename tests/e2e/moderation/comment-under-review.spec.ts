import { test, expect } from '@playwright/test';
import { ensureUser, wp } from '../_fixtures/wp';
import { bnApi, loginContextAs } from '../_fixtures/spaces-rest';
import { getOption, setOption, deleteOption } from '../_fixtures/db.fixture';

/**
 * J-818-comment-under-review.
 *
 * Covers: cap-let-members-report-content
 * Roles: member, author, admin
 *
 * Reports past the auto-hide threshold put a comment "under review": other
 * members stop seeing it and the post count drops by exactly one, the author
 * still sees it flagged and cannot reply under it, and dismissing the reports
 * brings it back for everyone.
 *
 * Effect-first: every claim is read from the REST payload each actor gets, not
 * from a screen string. Reporters are backdated past the account-age gate so
 * their reports count. Seeded content is removed and the threshold restored.
 */

const THRESHOLD = '2';
const LOGIN = { author: 'bn_j818_author', r1: 'bn_j818_r1', r2: 'bn_j818_r2', viewer: 'bn_j818_viewer' };

type Row = { id: number; is_hidden?: boolean | number | string };

function rows(data: unknown): Row[] {
    const d = data as { items?: Row[] } | Row[] | null;
    return Array.isArray(d) ? d : (d?.items ?? []);
}

test.describe('moderation / comment under review', () => {
    test('J-818 hidden for members, flagged for the author, restored on dismiss', async ({ browser, baseURL }) => {
        const prev = await getOption('buddynext_auto_hide_threshold');
        for (const l of Object.values(LOGIN)) {
            await ensureUser(l, `${l}@example.test`, l);
        }
        for (const l of [LOGIN.r1, LOGIN.r2]) {
            await wp(['user', 'update', l, '--user_registered=2020-01-01 00:00:00']);
        }
        await setOption('buddynext_auto_hide_threshold', THRESHOLD);

        const as = async (login: string) => (await loginContextAs(browser, baseURL, login)).page;
        const author = await as(LOGIN.author);
        let postId = 0;
        try {
            const post = await bnApi(author, 'POST', '/posts', { content: 'J-818 thread' });
            postId = Number((post.data as { id: number }).id);
            const c = await bnApi(author, 'POST', '/comments', { object_type: 'post', object_id: postId, content: 'J-818 comment' });
            const commentId = Number((c.data as { id: number }).id);
            expect(commentId, 'seeded comment').toBeGreaterThan(0);

            const count = async (p: typeof author) => {
                const r = await bnApi(p, 'GET', `/posts/${postId}`);
                return Number((r.data as { comment_count: number }).comment_count);
            };
            const thread = async (p: typeof author) => rows((await bnApi(p, 'GET', `/comments?object_type=post&object_id=${postId}`)).data);
            const before = await count(author);

            const viewer = await as(LOGIN.viewer);
            expect((await thread(viewer)).map((r) => r.id), 'visible before any report').toContain(commentId);

            let reportId = 0;
            for (const l of [LOGIN.r1, LOGIN.r2]) {
                const rp = await as(l);
                const res = await bnApi(rp, 'POST', '/reports', { object_type: 'comment', object_id: commentId, reason: 'spam' });
                expect(res.status, `report from ${l}`).toBeLessThan(300);
                reportId = Number((res.data as { id?: number; report_id?: number }).id ?? (res.data as { report_id?: number }).report_id ?? reportId);
            }

            expect((await thread(viewer)).map((r) => r.id), 'gone for another member').not.toContain(commentId);
            expect(await count(viewer), 'post count dropped by exactly one').toBe(before - 1);

            const mine = (await thread(author)).find((r) => r.id === commentId);
            expect(mine, 'author still sees it').toBeTruthy();
            expect(Boolean(Number(mine?.is_hidden)), 'flagged as under review').toBe(true);
            const reply = await bnApi(author, 'POST', '/comments', { object_type: 'post', object_id: postId, content: 'reply', parent_id: commentId });
            expect(reply.status, 'no reply under a hidden comment').toBeGreaterThanOrEqual(400);

            const admin = await as(process.env.BN_TEST_USER ?? 'varundubey');
            expect(reportId, 'report id returned').toBeGreaterThan(0);
            const dismissed = await bnApi(admin, 'POST', `/reports/${reportId}/dismiss`);
            expect(dismissed.status, 'dismissed').toBeLessThan(300);

            expect((await thread(viewer)).map((r) => r.id), 'back for members after restore').toContain(commentId);
            expect(await count(viewer), 'count returns to the original value').toBe(before);
        } finally {
            if (postId > 0) await bnApi(author, 'DELETE', `/posts/${postId}`).catch(() => undefined);
            if (prev === '') await deleteOption('buddynext_auto_hide_threshold');
            else await setOption('buddynext_auto_hide_threshold', prev);
        }
    });
});

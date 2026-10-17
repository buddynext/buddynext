/**
 * Effect helpers for the "one data path, two layers" rule (CATALOGUE rule 7).
 *
 * A journey drives the screen; these read the same REST route or row the functional
 * suite reads, so a screen that says "Saved" while nothing was saved fails here.
 * Keep them tolerant of envelope shape (items / data / bare array) so a spec asserts
 * the behaviour, not the wrapper.
 */
import type { Page } from '@playwright/test';
import { bnApi, bnNonce } from './spaces-rest';

type Dict = Record<string, unknown>;

/** The list inside a collection response, whatever the envelope calls it. */
export function listOf(data: unknown): Dict[] {
    if (Array.isArray(data)) {
        return data as Dict[];
    }
    const d = (data ?? {}) as Dict;
    for (const key of ['items', 'posts', 'notifications', 'hashtags', 'members', 'results', 'data']) {
        if (Array.isArray(d[key])) {
            return d[key] as Dict[];
        }
    }
    return [];
}

/** Numeric ids of a collection response. */
export function idsOf(data: unknown): number[] {
    // Social-graph and bookmark routes answer `{ ids: [...] }` with no row objects.
    const bare = (data ?? {}) as Dict;
    if (Array.isArray(bare.ids)) {
        return (bare.ids as unknown[]).map(Number).filter((n) => n > 0);
    }
    return listOf(data)
        .map((row) => Number(row.id ?? row.ID ?? row.post_id ?? 0))
        .filter((n) => n > 0);
}

/** GET a buddynext/v1 route as the user who owns the page's cookie. */
export async function restList(page: Page, path: string): Promise<{ status: number; data: unknown; items: Dict[] }> {
    const r = await bnApi(page, 'GET', path);
    return { status: r.status, data: r.data, items: listOf(r.data) };
}

/** GET any wp-json path (another namespace, e.g. buddynext-pro/v1) with the member's nonce. */
export async function restJson(page: Page, path: string): Promise<{ status: number; data: unknown }> {
    const nonce = await bnNonce(page);
    const res = await page.request.get(path, { headers: { 'X-WP-Nonce': nonce } });
    let data: unknown = null;
    try {
        data = await res.json();
    } catch {
        data = null;
    }
    return { status: res.status(), data };
}

/** Whether the member who owns the page's cookie is signed in, per the profile route. */
export async function sessionStatus(page: Page): Promise<number> {
    return (await bnApi(page, 'GET', '/me/profile')).status;
}

/** Unread notification count, per the same route the bell reads. */
export async function unreadCount(page: Page): Promise<number> {
    const r = await bnApi(page, 'GET', '/me/notifications/unread-count');
    const d = (r.data ?? {}) as Dict;
    return Number(d.count ?? d.unread ?? d.total ?? (typeof r.data === 'number' ? r.data : 0));
}

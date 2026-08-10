/**
 * Shared REST helpers for the Spaces wave-1 effect specs (J-600..J-629).
 *
 * These specs are EFFECT-BASED: every member action is performed against the
 * real `buddynext/v1` endpoint the front-end store itself calls (via `restFetch`
 * with an `X-WP-Nonce`), and every assertion checks a real CONSEQUENCE — a REST
 * GET confirming persisted state, a second actor observing the effect, or a full
 * page reload showing the change survived. Nothing here asserts an optimistic
 * label flip, and nothing swallows a failure.
 *
 * The nonce is minted from the logged-in cookie via GET /auth/nonce
 * (permission_callback __return_true; validates the auth cookie and mints a
 * wp_rest nonce for that user — the same recovery path the shared REST client
 * uses). This works on ANY BuddyNext page regardless of which localized nonce
 * that page happened to expose, so the helpers are page-agnostic and the caller's
 * cookie alone decides the acting user (admin vs. a second actor).
 *
 * This file adds no selectors — per-spec selectors stay in their own spec files.
 */
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { expect } from './auth.fixture';

const NS = '/wp-json/buddynext/v1';

export type ApiResult = { status: number; ok: boolean; data: unknown };

/** A minimal valid space row shape (only the fields the specs read). */
export type SpaceRow = {
    id: number;
    slug: string;
    name: string;
    type: string;
    member_count?: number;
    owner_id?: number;
    cover_image_url?: string | null;
    avatar_url?: string | null;
    membership_status?: string;
    membership_role?: string;
    can_invite?: boolean;
    can_manage?: boolean;
    [k: string]: unknown;
};

/** Mint a fresh wp_rest nonce for whoever owns this page's cookie. */
export async function bnNonce(page: Page): Promise<string> {
    return page.evaluate(async () => {
        const r = await fetch('/wp-json/buddynext/v1/auth/nonce', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!r.ok) return '';
        const j = (await r.json()) as { nonce?: string };
        return j?.nonce ?? '';
    });
}

/**
 * Call a buddynext/v1 endpoint as the user who owns `page`'s cookie.
 *
 * @param page   Any page whose cookie identifies the acting user.
 * @param method HTTP verb.
 * @param path   Path under the buddynext/v1 namespace, e.g. '/spaces/42'.
 * @param body   Optional JSON body.
 * @param headers Optional extra request headers (e.g. the delete confirm name).
 */
export async function bnApi(
    page: Page,
    method: string,
    path: string,
    body?: unknown,
    headers?: Record<string, string>
): Promise<ApiResult> {
    const nonce = await bnNonce(page);
    return page.evaluate(
        async ({ method, path, body, nonce, headers, ns }) => {
            const h: Record<string, string> = { 'X-WP-Nonce': nonce, ...(headers || {}) };
            const opts: RequestInit = { method, credentials: 'same-origin', headers: h };
            if (body !== undefined && body !== null) {
                h['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            const r = await fetch(ns + path, opts);
            const text = await r.text();
            let data: unknown = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch {
                data = text;
            }
            return { status: r.status, ok: r.ok, data };
        },
        { method, path, body: body ?? null, nonce, headers: headers ?? {}, ns: NS }
    );
}

/**
 * Multipart image upload (space cover/avatar) — POSTs a real 1x1 PNG under the
 * `image` file field, exactly as the upload endpoints expect.
 */
export async function bnUploadImage(page: Page, path: string): Promise<ApiResult> {
    const nonce = await bnNonce(page);
    return page.evaluate(
        async ({ path, nonce, ns }) => {
            // A genuine 8x8 RGB PNG (valid IHDR/IDAT/IEND with correct CRCs, so
            // it survives wp_check_filetype_and_ext AND the ImageMagick decode the
            // upload pipeline runs — a corrupt IDAT here 500s the endpoint).
            const b64 =
                'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAEUlEQVR42mO4Y2ODFTEMLQkAXrdVAaRBiusAAAAASUVORK5CYII=';
            const bin = atob(b64);
            const arr = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
            const blob = new Blob([arr], { type: 'image/png' });
            const fd = new FormData();
            fd.append('image', blob, 'e2e-space.png');
            const r = await fetch(ns + path, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
                body: fd,
            });
            const text = await r.text();
            let data: unknown = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch {
                data = text;
            }
            return { status: r.status, ok: r.ok, data };
        },
        { path, nonce, ns: NS }
    );
}

/** GET /spaces/{id} as this page's user (viewer-relative membership + flags). */
export async function getSpace(page: Page, id: number): Promise<ApiResult> {
    return bnApi(page, 'GET', `/spaces/${id}`);
}

/**
 * Create a throwaway space via the real POST /spaces endpoint.
 * Returns the created row (id + slug). Caller is the owner.
 */
export async function createSpaceApi(
    page: Page,
    opts: { name: string; type?: string; description?: string }
): Promise<SpaceRow> {
    const res = await bnApi(page, 'POST', '/spaces', {
        name: opts.name,
        type: opts.type ?? 'open',
        description: opts.description ?? 'e2e throwaway space',
    });
    expect(res.status, `create space failed: ${JSON.stringify(res.data)}`).toBe(201);
    const row = res.data as SpaceRow;
    expect(row.id, 'created space carried no id').toBeGreaterThan(0);
    return row;
}

/** Best-effort delete of a throwaway space (cleanup; never throws). */
export async function deleteSpaceApi(page: Page, id: number): Promise<void> {
    try {
        await bnApi(page, 'DELETE', `/spaces/${id}`);
    } catch {
        /* leave-as-found best effort */
    }
}

/**
 * Ensure the user behind `page` is past the onboarding gate, so BuddyNext
 * front-end routes render instead of redirecting to /onboarding/. Uses the
 * self-service POST /me/onboarding/skip (idempotent — marks the wizard complete).
 * Seeded members other than the admin are often not onboarded, which otherwise
 * bounces a second actor away from every space UI.
 */
export async function ensureOnboarded(page: Page): Promise<void> {
    await bnApi(page, 'POST', '/me/onboarding/skip');
}

/**
 * Open a second browser context logged in as `login` (via the dev autologin
 * mu-plugin: ?autologin=<login>). Returns the context (close it in finally)
 * and a page already authenticated as that user.
 */
export async function loginContextAs(
    browser: Browser,
    baseURL: string | undefined,
    login: string
): Promise<{ ctx: BrowserContext; page: Page }> {
    const ctx = await browser.newContext({ baseURL });
    const page = await ctx.newPage();
    await page.goto(`/?autologin=${encodeURIComponent(login)}`, { waitUntil: 'domcontentloaded' });
    const cookies = await ctx.cookies();
    expect(
        cookies.some((c) => c.name.startsWith('wordpress_logged_in')),
        `autologin as "${login}" did not establish a session cookie`
    ).toBeTruthy();
    return { ctx, page };
}

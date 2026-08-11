import type { Browser, BrowserContext, Page, APIRequestContext } from '@playwright/test';
import { sel, urls } from './selectors';

/**
 * Shared helpers for the Feed wave-1 effect specs (J-500..J-529).
 *
 * These are intentionally kept OUT of the shared selectors.ts (which parallel
 * agents also touch). Everything here is effect-plumbing: reading the real
 * wp_rest nonce off the rendered page, resolving a card's server post id, and
 * spinning a second logged-in member so a cross-user effect (a @mention
 * notification, a report row) can be asserted from the OTHER side, not just
 * from the actor's optimistic UI.
 */

/**
 * Read the live `wp_rest` nonce the page actually uses.
 *
 * Primary source is the post-composer Interactivity context (restNonce), which
 * is the exact nonce the feed's own writes send — so a REST call made with it
 * exercises the same auth path as the UI. Falls back to scraping the rendered
 * HTML for any `restNonce":"…"` when the composer is absent (e.g. a member who
 * is bounced to onboarding). Throws rather than returning '' so a missing nonce
 * fails loudly as test-infra breakage, never as a false green.
 */
export async function readRestNonce(page: Page): Promise<string> {
    const fromComposer = await page.evaluate(() => {
        const el = document.querySelector('[data-wp-interactive="buddynext/post-composer"]');
        if (el) {
            try {
                const ctx = JSON.parse(el.getAttribute('data-wp-context') ?? '{}');
                if (ctx && typeof ctx.restNonce === 'string' && ctx.restNonce) {
                    return ctx.restNonce as string;
                }
            } catch {
                /* fall through to HTML scrape */
            }
        }
        const m = document.documentElement.outerHTML.match(/restNonce["']?\s*:\s*["']([a-f0-9]+)["']/i);
        return m ? m[1] : '';
    });
    if (!fromComposer) {
        throw new Error('readRestNonce: could not resolve a wp_rest nonce from the page.');
    }
    return fromComposer;
}

/** The server post id of the card containing exactly this text (0 if none). */
export async function postIdOfCard(page: Page, text: string): Promise<number> {
    const card = page.locator(sel.postCard).filter({ hasText: text }).first();
    if (!(await card.count())) {
        return 0;
    }
    return card.evaluate((el) => {
        try {
            const ctx = JSON.parse(el.getAttribute('data-wp-context') ?? '{}');
            return Number(ctx.postId) || 0;
        } catch {
            return 0;
        }
    });
}

/** DELETE a post via REST using the given context's cookies + nonce. Best-effort. */
export async function deletePostRest(request: APIRequestContext, nonce: string, postId: number): Promise<void> {
    if (!postId) {
        return;
    }
    await request
        .delete(`/wp-json/buddynext/v1/posts/${postId}`, { headers: { 'X-WP-Nonce': nonce } })
        .catch(() => undefined);
}

export type MemberSession = {
    ctx: BrowserContext;
    page: Page;
    request: APIRequestContext;
    nonce: string;
};

/**
 * Open a second, independent logged-in session for `login` via the autologin
 * mu-plugin, and resolve its wp_rest nonce. Used to assert an effect from the
 * RECIPIENT's side (e.g. did @alice actually get a mention notification) rather
 * than trusting the actor's own screen.
 */
export async function openMemberSession(browser: Browser, login: string): Promise<MemberSession> {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`/?autologin=${encodeURIComponent(login)}`, { waitUntil: 'domcontentloaded' });
    // Land on a BuddyNext surface that carries a wp_rest nonce. /activity/ may
    // redirect a not-yet-onboarded member to /onboarding/, which still embeds one.
    await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
    const nonce = await readRestNonce(page);
    return { ctx, page, request: ctx.request, nonce };
}

/** Bearer-less REST GET returning parsed JSON, using a context's cookies + nonce. */
export async function restGet<T = unknown>(request: APIRequestContext, nonce: string, path: string): Promise<{ status: number; body: T }> {
    const res = await request.get(`/wp-json/buddynext/v1${path}`, { headers: { 'X-WP-Nonce': nonce } });
    let body = {} as T;
    try {
        body = (await res.json()) as T;
    } catch {
        /* non-JSON */
    }
    return { status: res.status(), body };
}

/** REST POST returning status + parsed JSON, using a context's cookies + nonce. */
export async function restPost<T = unknown>(
    request: APIRequestContext,
    nonce: string,
    path: string,
    data: Record<string, unknown>
): Promise<{ status: number; body: T }> {
    const res = await request.post(`/wp-json/buddynext/v1${path}`, {
        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        data,
    });
    let body = {} as T;
    try {
        body = (await res.json()) as T;
    } catch {
        /* non-JSON */
    }
    return { status: res.status(), body };
}

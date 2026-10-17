import { test, expect, type Page } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { restList } from '../_fixtures/effects';

/**
 * J-986 badges, state labels and letter avatars are readable, light and dark.
 *
 * 1.2.5 promised every badge, state label and letter avatar the plugin ships
 * reads at 4.5 to 1. J-825 judges only the faint and accent text colours; this
 * journey judges the components themselves:
 *
 *   1. On the spaces directory, the members directory, the feed and a profile,
 *      the text inside every toned badge, tone avatar, saved / reacted state
 *      label and social chip, against the background it is drawn on, in the
 *      theme's own light and dark mode (BuddyX's switch, as J-825 does).
 *   2. The generated letter avatar the member routes serve (a data: SVG for a
 *      member with no photo): its white initials against its fill, for every
 *      fill the directory returns. Read from REST, the same image every surface
 *      and the apps draw.
 *
 * Read-only.
 *
 * Covers: cap-re-theme-without-touching-css
 * Roles: member (signed in)
 */

const MEMBER = process.env.BN_TEST_USER ?? 'varundubey';
const PAGES = ['/spaces/', '/members/', '/activity/', `/members/${MEMBER}/`];
const FLOOR = 4.5;
const COMPONENTS = [
    '.bn-badge',
    '.bn-avatar[data-tone]',
    '.bn-avatar[class*="bn-dm-tone-"]',
    '.bn-post-card__action-btn.is-bookmarked',
    '.bn-post-card__action-btn.is-reacted',
    '.bn-pf-social-chip',
].join(',');

/** WCAG relative luminance of an sRGB hex or [r,g,b]. */
function luminance(rgb: number[]): number {
    const f = (v: number) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
}
function hex(h: string): number[] {
    const m = h.replace('#', '');
    const full = m.length === 3 ? m.split('').map((c) => c + c).join('') : m;
    return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16));
}
function ratio(a: number[], b: number[]): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

async function measure(page: Page): Promise<{ judged: number; failures: string[] }> {
    return page.evaluate(({ selector, floor }) => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
        const rgba = (css: string): number[] => {
            ctx.clearRect(0, 0, 1, 1);
            ctx.fillStyle = '#000';
            ctx.fillStyle = css;
            ctx.fillRect(0, 0, 1, 1);
            const d = ctx.getImageData(0, 0, 1, 1).data;
            return [d[0], d[1], d[2], d[3] / 255];
        };
        const over = (t: number[], u: number[]) => [0, 1, 2].map((i) => t[i] * t[3] + u[i] * (1 - t[3])).concat(1);
        const lum = (c: number[]) => {
            const f = (v: number) => (v / 255 <= 0.03928 ? v / 255 / 12.92 : Math.pow((v / 255 + 0.055) / 1.055, 2.4));
            return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
        };
        const contrast = (a: number[], b: number[]) => {
            const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
            return (hi + 0.05) / (lo + 0.05);
        };
        const backdrop = (el: Element): number[] | null => {
            const layers: number[][] = [];
            for (let n: Element | null = el; n; n = n.parentElement) {
                const s = getComputedStyle(n);
                if (s.backgroundImage !== 'none') return null;
                const c = rgba(s.backgroundColor);
                if (c[3] > 0) {
                    layers.push(c);
                    if (c[3] === 1) break;
                }
            }
            let base = [255, 255, 255, 1];
            for (const l of layers.reverse()) base = over(l, base);
            return base;
        };
        const failures: string[] = [];
        let judged = 0;
        document.querySelectorAll(selector).forEach((host) => {
            [host, ...Array.from(host.querySelectorAll('*'))].forEach((el) => {
                const text = Array.from(el.childNodes)
                    .filter((n) => n.nodeType === Node.TEXT_NODE)
                    .map((n) => n.textContent ?? '')
                    .join('')
                    .trim();
                const s = getComputedStyle(el);
                const r = el.getBoundingClientRect();
                if (!text || s.visibility === 'hidden' || s.display === 'none' || r.width < 1 || r.height < 1) return;
                if (el.closest('[aria-hidden="true"], .screen-reader-text')) return;
                const bg = backdrop(el);
                if (!bg) return;
                judged++;
                const value = contrast(over(rgba(s.color), bg), bg);
                if (value < floor) {
                    const cls = String((host as HTMLElement).className || '').trim().split(/\s+/).slice(0, 2).join('.');
                    failures.push(`${Math.round(value * 100) / 100}:1 ${host.tagName.toLowerCase()}.${cls} "${text.slice(0, 30)}"`);
                }
            });
        });
        return { judged, failures };
    }, { selector: COMPONENTS, floor: FLOOR });
}

for (const mode of ['light', 'dark'] as const) {
    test(`J-986 badges, state labels and tone avatars read at 4.5:1 (${mode})`, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Desktop journey.');
        await page.addInitScript((m) => {
            try {
                window.localStorage.setItem('bx-color-mode', m);
            } catch {
                // Storage blocked: the mode check below skips the dark leg.
            }
        }, mode);
        await loginAs(page, MEMBER);
        const failures = new Set<string>();
        let judged = 0;
        for (const path of PAGES) {
            await page.goto(path);
            await page.waitForLoadState('load');
            if (mode === 'dark') {
                const active = await page.evaluate(() => document.documentElement.getAttribute('data-bx-mode'));
                test.skip(active !== 'dark', 'The active theme has no colour mode of its own to switch to dark.');
            }
            const r = await measure(page);
            judged += r.judged;
            r.failures.forEach((f) => failures.add(`${path} ${f}`));
        }
        expect(judged, 'badge, state-label and tone-avatar text was found and measured').toBeGreaterThan(0);
        expect(Array.from(failures), `component text under ${FLOOR}:1 (${mode})`).toEqual([]);
    });
}

test('J-986 the generated letter avatar served by the member routes reads at 4.5:1', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Desktop journey.');
    await loginAs(page, MEMBER);
    // EFFECT: the image the member routes actually serve, not a rendered copy of it.
    const seen = new Map<string, number>();
    for (const p of [1, 2, 3]) {
        const list = await restList(page, `/members?per_page=50&page=${p}`);
        expect(list.status, 'GET /members').toBe(200);
        for (const m of list.items) {
            const url = String(m.avatar_url ?? '');
            if (!url.startsWith('data:image/svg+xml;base64,')) continue;
            const svg = Buffer.from(url.slice(26), 'base64').toString('utf8');
            const fills = Array.from(svg.matchAll(/fill="(#[0-9a-fA-F]{3,6})"/g)).map((x) => x[1]);
            if (fills.length >= 2) seen.set(`${fills[0]} with ${fills[1]} initials`, ratio(hex(fills[0]), hex(fills[1])));
        }
    }
    expect(seen.size, 'members without a photo were served a generated letter avatar').toBeGreaterThan(0);
    const low = Array.from(seen).filter(([, v]) => v < FLOOR).map(([k, v]) => `${k}: ${v.toFixed(2)}:1`);
    expect(low, 'generated letter avatars under 4.5:1').toEqual([]);
});

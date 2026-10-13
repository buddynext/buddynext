import { test, expect, type Page } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';

/**
 * J-825 small text is readable.
 *
 * Roles: owner (admin), signed in
 *
 * On the feed, a profile, notifications and the spaces directory, every text
 * element drawn in the faint text colour (--bn-ink-3) or in the accent used as
 * text (--bn-accent-text) reads at 4.5 to 1 or better against the background it
 * sits on, in light and in dark. Dark is switched through the theme's own colour
 * mode (BuddyX keeps it in localStorage and stamps <html data-bx-mode>), never by
 * forcing a BuddyNext attribute, so the tokens are judged as the theme paints them.
 *
 * Colours are resolved to sRGB by painting them on a canvas, so oklch() and
 * color-mix() values are measured as drawn. Text under 4.5 in any other colour is
 * listed in the report (annotation `low-contrast-other`) and does not fail the
 * journey: it is the list to look at, not a verdict.
 *
 * Read-only: the journey loads four pages and measures them.
 *
 * Desktop project only.
 */

const MEMBER = process.env.BN_TEST_USER ?? 'varundubey';
const PAGES = ['/activity/', `/members/${MEMBER}/`, '/notifications/', '/spaces/'];
const FLOOR = 4.5;

type Finding = { token: string; ratio: number; where: string; text: string };

async function measure(page: Page): Promise<{ tokens: Record<string, string>; judged: number; failures: Finding[]; others: Finding[] }> {
    return page.evaluate((floor) => {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
        const cache = new Map<string, number[]>();
        const rgba = (css: string): number[] => {
            const hit = cache.get(css);
            if (hit) {
                return hit;
            }
            ctx.clearRect(0, 0, 1, 1);
            ctx.fillStyle = '#000';
            ctx.fillStyle = css;
            ctx.fillRect(0, 0, 1, 1);
            const d = ctx.getImageData(0, 0, 1, 1).data;
            const out = [d[0], d[1], d[2], d[3] / 255];
            cache.set(css, out);
            return out;
        };
        const over = (top: number[], under: number[]): number[] => {
            const a = top[3];
            return [0, 1, 2].map((i) => top[i] * a + under[i] * (1 - a)).concat(1);
        };
        const lum = (c: number[]): number => {
            const f = (v: number) => {
                const s = v / 255;
                return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
            };
            return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
        };
        const ratio = (a: number[], b: number[]): number => {
            const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
            return (hi + 0.05) / (lo + 0.05);
        };
        // Background the text sits on: composite every ancestor's colour over the
        // page. A background image or gradient cannot be judged from colour alone.
        const backdrop = (el: Element): number[] | null => {
            const layers: number[][] = [];
            for (let n: Element | null = el; n; n = n.parentElement) {
                const s = getComputedStyle(n);
                if (s.backgroundImage !== 'none') {
                    return null;
                }
                const c = rgba(s.backgroundColor);
                if (c[3] > 0) {
                    layers.push(c);
                    if (c[3] === 1) {
                        break;
                    }
                }
            }
            let base = [255, 255, 255, 1];
            for (const layer of layers.reverse()) {
                base = over(layer, base);
            }
            return base;
        };

        const scope = document.querySelector('.bn-app') ?? document.body;
        const probe = document.createElement('span');
        scope.appendChild(probe);
        const tokens: Record<string, number[]> = {};
        for (const name of ['--bn-ink-3', '--bn-accent-text']) {
            probe.style.color = `var(${name})`;
            tokens[name] = rgba(getComputedStyle(probe).color);
        }
        probe.remove();
        const same = (a: number[], b: number[]) => [0, 1, 2].every((i) => Math.abs(a[i] - b[i]) <= 1) && Math.abs(a[3] - b[3]) < 0.02;

        const failures: Finding[] = [];
        const others: Finding[] = [];
        let judged = 0;
        scope.querySelectorAll('*').forEach((el) => {
            const text = Array.from(el.childNodes)
                .filter((n) => n.nodeType === Node.TEXT_NODE)
                .map((n) => n.textContent ?? '')
                .join('')
                .trim();
            if (!text || el.closest('[disabled], [aria-disabled="true"], [aria-hidden="true"], .screen-reader-text')) {
                return;
            }
            const s = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            if (s.visibility === 'hidden' || s.display === 'none' || parseFloat(s.opacity) === 0 || r.width < 1 || r.height < 1) {
                return;
            }
            const bg = backdrop(el);
            if (!bg) {
                return;
            }
            const raw = rgba(s.color);
            const token = Object.keys(tokens).find((k) => same(raw, tokens[k])) ?? '';
            const value = ratio(over(raw, bg), bg);
            if (token) {
                judged++;
            }
            if (value >= floor) {
                return;
            }
            const cls = String((el as HTMLElement).className || '').trim().split(/\s+/).slice(0, 2).join('.');
            const finding = { token, ratio: Math.round(value * 100) / 100, where: `${el.tagName.toLowerCase()}${cls ? '.' + cls : ''}`, text: text.slice(0, 40) };
            (token ? failures : others).push(finding);
        });

        const css = (c: number[]) => `rgb(${c.slice(0, 3).map(Math.round).join(' ')} / ${c[3]})`;
        return { tokens: { '--bn-ink-3': css(tokens['--bn-ink-3']), '--bn-accent-text': css(tokens['--bn-accent-text']) }, judged, failures, others };
    }, FLOOR);
}

for (const mode of ['light', 'dark'] as const) {
    test(`J-825 small text is readable (${mode})`, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Desktop journey.');
        // The theme's own switch: BuddyX reads this key before first paint.
        await page.addInitScript((m) => {
            try {
                window.localStorage.setItem('bx-color-mode', m);
            } catch {
                // Storage blocked: the mode check below skips the dark leg.
            }
        }, mode);
        await loginAs(page, MEMBER);

        const failures: string[] = [];
        const others = new Set<string>();
        let judged = 0;
        for (const path of PAGES) {
            await page.goto(path);
            await page.waitForLoadState('load');
            if (mode === 'dark') {
                const active = await page.evaluate(() => document.documentElement.getAttribute('data-bx-mode'));
                test.skip(active !== 'dark', 'The active theme has no colour mode of its own to switch to dark.');
            }
            const result = await measure(page);
            judged += result.judged;
            for (const f of result.failures) {
                failures.push(`${path} ${f.token} ${f.ratio}:1 ${f.where} "${f.text}"`);
            }
            for (const f of result.others) {
                others.add(`${path} ${f.ratio}:1 ${f.where} "${f.text}"`);
            }
        }

        if (others.size) {
            testInfo.annotations.push({ type: 'low-contrast-other', description: Array.from(others).slice(0, 40).join('\n') });
        }
        // Positive control: a run that judged nothing proves nothing.
        expect(judged, 'text drawn in the faint or accent-text colour was found and measured').toBeGreaterThan(0);
        expect(failures, `faint or accent text under ${FLOOR}:1 (${mode})`).toEqual([]);
    });
}

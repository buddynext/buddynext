import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-126-oembed-fallback-containment.
 *
 * Regression: the oEmbed fallback layer must never escape its own embed box.
 *
 * `.bn-post-card__oembed-fallback` is `position: absolute; inset: 0`. It used to
 * get its containing block from a rule scoped with `:has()` to REAL provider
 * players only, so any other embed shape - WordPress' own internal-post embeds
 * (`.wp-embedded-content`), or the blockquote-plus-script embeds X, Instagram
 * and Reddit return - left it with no positioned ancestor. It then resolved
 * against `.bn-app` and painted a full-page backdrop over the entire feed:
 * measured at 1822x4256px on a 1822x834 viewport, with the whole feed blank for
 * logged-out and logged-in visitors alike.
 *
 * This asserts the structural guarantee rather than one provider's markup: given
 * the production DOM shape, the fallback is contained by its own wrapper. It is
 * written against injected markup on purpose - it must hold whether or not the
 * site under test happens to have a link post, and a spec that quietly passes
 * because it found nothing to check is not a gate.
 *
 * Mutation check: remove `position: relative` from `.bn-post-card__embed` in
 * assets/css/bn-feed.css and this fails by name.
 */
test.describe('feed / oembed fallback containment', () => {
    test('fallback layer stays inside its embed box', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const result = await page.evaluate(() => {
            const host = document.querySelector('.bn-app');
            if (!host) {
                return { error: 'no .bn-app host on the page' };
            }

            // Production markup shape from templates/parts/post-body.php.
            const probe = document.createElement('div');
            probe.innerHTML =
                '<div class="bn-post-card__embed bn-post-card__oembed" data-probe="1">' +
                '<a class="bn-post-card__oembed-fallback" href="#"><span>probe</span></a>' +
                '<iframe class="wp-embedded-content" title="probe"></iframe>' +
                '</div>';
            host.appendChild(probe);

            const wrapper = probe.querySelector('.bn-post-card__embed') as HTMLElement;
            const fallback = probe.querySelector('.bn-post-card__oembed-fallback') as HTMLElement;

            const wr = wrapper.getBoundingClientRect();
            const fr = fallback.getBoundingClientRect();

            const out = {
                wrapperPosition: getComputedStyle(wrapper).position,
                fallbackPosition: getComputedStyle(fallback).position,
                containedByWrapper: fallback.offsetParent === wrapper,
                // Allow a sub-pixel tolerance; the layer must not exceed its box.
                withinBounds:
                    fr.width <= wr.width + 1 &&
                    fr.height <= wr.height + 1,
                wrapper: { w: Math.round(wr.width), h: Math.round(wr.height) },
                fallback: { w: Math.round(fr.width), h: Math.round(fr.height) },
            };

            probe.remove();
            return out;
        });

        expect(result.error).toBeUndefined();

        // The layer is only dangerous while it is absolutely positioned - if the
        // stylesheet ever stops positioning it, there is nothing to contain and
        // the rest of the assertions would be meaningless rather than passing.
        expect(result.fallbackPosition).toBe('absolute');

        expect(
            result.wrapperPosition,
            'embed wrapper must establish a containing block for the fallback layer',
        ).not.toBe('static');

        expect(
            result.containedByWrapper,
            `fallback escaped its wrapper (offsetParent is not the embed box); ` +
                `wrapper=${JSON.stringify(result.wrapper)} fallback=${JSON.stringify(result.fallback)}`,
        ).toBe(true);

        expect(
            result.withinBounds,
            `fallback is larger than its embed box; ` +
                `wrapper=${JSON.stringify(result.wrapper)} fallback=${JSON.stringify(result.fallback)}`,
        ).toBe(true);
    });
});

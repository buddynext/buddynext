#!/usr/bin/env python3
"""Every browser journey should prove an EFFECT, not only a render.

A journey that fills a form and asserts the page says "Saved" proves the page said
"Saved". The functional suite (buddynext-pro/free-internal/qa-functional) proves the
same behaviours through the REST contract and the data; for the two layers to describe
one product, each browser journey confirms its effect through the same data path:
a REST read (`restGet`, `bnApi`, `page.request`), or a direct row check
(`dbCount`, `dbScalar`, `getUserMeta`, `getOption`, `wp(['eval', ...])`) when no route
exposes it.

This script lists the specs that confirm nothing beyond the DOM. It is a measure, not
yet a gate: run it with --strict once the queue is empty so it stays empty.

    python3 bin/check-journey-effects.py            # report
    python3 bin/check-journey-effects.py --strict   # exit 1 when any spec is UI-only
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / 'tests' / 'e2e'
EFFECT = re.compile(
    # A REST read, a row read, or a response the browser waited for: anything that
    # confirms the server state the UI claims to have changed. No trailing \b: half
    # of these end in "(" and the next character is usually "(" or a quote.
    r"\b(?:restGet|restPost|restList|restJson|sessionStatus|unreadCount|idsOf|bnApi|getSpace|dbCount|dbScalar|getUserMeta|getOption|userId\(|"
    r"page\.request\.|\.request\.(?:get|post|put|delete)\(|waitForResponse\(|createSpaceApi|deletePostRest|"
    # wp(['eval', ...]) is the same data path as dbScalar: a direct read of the row,
    # option or service the UI claims to have changed. The Pro membership specs read
    # back tiers, subscriptions and entitlements this way.
    r"wp\(\s*\[\s*'eval'|"
    # Composer drafts live in the browser by design; the store IS localStorage.
    r"localStorage\.)"
)
# Specs whose promise is presentation only (layout contracts, cookie notice, nav chrome):
# a DOM assertion IS the effect there. Keep this list short and reasoned.
PRESENTATION_ONLY = {
    'admin/table-layout-contract.spec.ts',
    'shell/cookie-notice.spec.ts',
    'shell/mobile-nav.spec.ts',
    # Layout contracts measured with getBoundingClientRect / computed style: the
    # geometry is the promise, there is no row behind it.
    'admin/setup-wizard-rail.spec.ts',      # step rail is one row, current step named
    'blocks/activity-feed-block.spec.ts',   # vertical gap between cards in the block
    'blocks/list-layout.spec.ts',           # list layout renders one stacked column
    'feed/card-budget.spec.ts',             # bytes / elements per card, sprite icons
    'feed/oembed-containment.spec.ts',      # fallback layer stays inside its box
    'shell/phone-width-fit.spec.ts',        # no sideways scroll, 16px fields
    'shell/responsive.spec.ts',             # rail collapses by breakpoint
    'shell/theme-chrome.spec.ts',           # theme header/footer outside .bn-app
    'shell/text-contrast.spec.ts',          # measured contrast of faint and accent text
    # Markup contracts: a shortcode or block renders exactly its own root and no
    # leaked attributes; the check is the HTML itself.
    'blocks/notification-bell-shortcode.spec.ts',
    'blocks/community-admin-shortcode.spec.ts',
}


def main() -> int:
    strict = '--strict' in sys.argv
    ui_only = []
    total = 0
    for spec in sorted(ROOT.rglob('*.spec.ts')):
        rel = spec.relative_to(ROOT).as_posix()
        total += 1
        if rel in PRESENTATION_ONLY:
            continue
        if not EFFECT.search(spec.read_text(encoding='utf-8')):
            ui_only.append(rel)
    print(f'journey effects: {total - len(ui_only)}/{total} specs confirm an effect through REST or data')
    if ui_only:
        print(f'{len(ui_only)} UI-only specs (upgrade queue, newest product areas first):')
        for rel in ui_only:
            print(f'  - {rel}')
    return 1 if (strict and ui_only) else 0


if __name__ == '__main__':
    sys.exit(main())

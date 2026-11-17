#!/usr/bin/env python3
"""Fail when PHP reads the client address anywhere but SafeguardService::client_ip().

WHY THIS EXISTS

Up to 1.2.9 four places read REMOTE_ADDR. Only one of them applied the
`buddynext_client_ip` filter, so behind a proxy or CDN the sign-up limit, the
login / lost-password limits and the OAuth limit all keyed on the proxy
address: the whole site shared one bucket, while the IP blocklist (the one
reader that used the filter) saw the real visitor. Same owner setting, two
answers. The fix routed every reader through SafeguardService::client_ip();
this check keeps a fifth copy from coming back.

It also refuses any read of a forwarded header (X-Forwarded-For, X-Real-IP,
CF-Connecting-IP, Client-IP). Those are client-supplied: a rate limit keyed on
them is no limit at all. The host restores the visitor address, or a site maps
it through the filter.

Exit 0 when clean, 1 on any violation.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Production PHP only. tests/ legitimately sets $_SERVER['REMOTE_ADDR'].
SCAN = ("includes", "templates", "blocks")
ROOT_FILES = ("buddynext.php", "bootstrap.php", "uninstall.php")

ALLOWED_REMOTE_ADDR = {Path("includes/Moderation/SafeguardService.php")}

REMOTE_ADDR = re.compile(r"""['"]REMOTE_ADDR['"]""")
FORWARDED = re.compile(
    r"""['"]HTTP_(?:X_FORWARDED_FOR|X_FORWARDED|FORWARDED_FOR|FORWARDED|X_REAL_IP|CF_CONNECTING_IP|CLIENT_IP|TRUE_CLIENT_IP)['"]"""
)


def files():
    for name in ROOT_FILES:
        path = ROOT / name
        if path.is_file():
            yield path
    for folder in SCAN:
        base = ROOT / folder
        if base.is_dir():
            yield from sorted(base.rglob("*.php"))


def main() -> int:
    problems = []
    for path in files():
        rel = path.relative_to(ROOT)
        for lineno, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
            if REMOTE_ADDR.search(line) and rel not in ALLOWED_REMOTE_ADDR:
                problems.append(f"{rel}:{lineno}: raw REMOTE_ADDR read - call SafeguardService::client_ip()")
            if FORWARDED.search(line):
                problems.append(f"{rel}:{lineno}: forwarded-header read - never trust it; use the buddynext_client_ip filter")
    if problems:
        print("Client IP must come from SafeguardService::client_ip() only:")
        for problem in problems:
            print("  " + problem)
        return 1
    print("client IP: one reader (SafeguardService::client_ip)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

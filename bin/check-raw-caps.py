#!/usr/bin/env python3
"""Raw manage_options checks outside the settings screens may only go down.

Every permission in BuddyNext should flow through buddynext_can(), so a site can
give a role (a moderator, a community admin) a power without making that person a
WordPress administrator. manage_options is still right where the job IS site
configuration: the wp-admin settings screens under includes/Admin/, which this gate
does not count. Everywhere else a raw check is either a power that should be an
ability, or a deliberate site-owner boundary (granting the Admin role, seeing
private content); the baseline records how many each file has today.

  * A file over its baseline, or a new file with a raw check, fails: use
    buddynext_can() with an ability from PermissionService::ROLE_MAP.
  * A file under its baseline also fails until the reduction is locked in with
    --update, so the count can never creep back up.

Usage (from a plugin root):
  python3 bin/check-raw-caps.py                       # Free, bin/raw-caps-baseline.json
  python3 ../buddynext/bin/check-raw-caps.py --root . # Pro, its own bin/raw-caps-baseline.json
  ... --update                                        # rewrite the baseline to today
"""

import json
import re
import sys
from pathlib import Path

ROOT = Path.cwd()
args = sys.argv[1:]
if "--root" in args:
    ROOT = Path(args[args.index("--root") + 1]).resolve()
BASELINE = ROOT / "bin" / "raw-caps-baseline.json"
SCAN = ("includes", "templates", "blocks")
SKIP = ("includes/Admin/",)
COMMENT = re.compile(r"^\s*(\*|//|#|/\*)")


def counts() -> dict:
    found = {}
    files = [p for d in SCAN for p in (ROOT / d).rglob("*.php")] + list(ROOT.glob("*.php"))
    for path in sorted(files):
        rel = path.relative_to(ROOT).as_posix()
        if rel.startswith(SKIP):
            continue
        n = sum(
            1
            for line in path.read_text(encoding="utf-8", errors="replace").splitlines()
            if "manage_options" in line and not COMMENT.match(line)
        )
        if n:
            found[rel] = n
    return found


now = counts()
if "--update" in args:
    BASELINE.write_text(json.dumps(now, indent=1, sort_keys=True) + "\n")
    print(f"raw-caps baseline: {sum(now.values())} checks in {len(now)} files")
    sys.exit(0)

base = json.loads(BASELINE.read_text()) if BASELINE.exists() else {}
grew = {f: (base.get(f, 0), n) for f, n in now.items() if n > base.get(f, 0)}
shrank = {f: (n, now.get(f, 0)) for f, n in base.items() if now.get(f, 0) < n}

for f, (was, n) in sorted(grew.items()):
    print(f"  + {f}: {was} -> {n} raw manage_options checks; use buddynext_can() with an ability")
for f, (was, n) in sorted(shrank.items()):
    print(f"  - {f}: {was} -> {n}; lock it in with --update")
if grew or shrank:
    sys.exit(1)
print(f"raw-caps: {sum(now.values())} checks outside settings screens, at baseline")

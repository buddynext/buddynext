#!/usr/bin/env python3
"""A hook you promised with @since must be findable in the developer guide.

WHY THIS EXISTS. Every extension point advertised as a `Dev` bullet in the 1.1.5
release notes — twelve of them, actions and filters — was missing from
`docs/website/developer-guide/`. Integrators were told in the changelog to use an
API, and then handed a guide that did not mention it. They were found by reading
the release notes against the guide by hand, months later.

Nothing catches that today. `check-hook-docs.py` gates the ARG COUNT of
`do_action()` hooks listed in the root CLAUDE.md; it does not look at filters at
all, and it never opens the customer-facing guide. So the surface most likely to
rot is the one with no gate on it.

WHAT COUNTS AS A PROMISE. A `@since` tag on a hook's docblock. Writing one is a
deliberate act: it says this is public API with a version attached. Hooks fired
without a documented `@since` are internal plumbing and are not checked here —
that keeps the gate on the ~64 hooks we actually committed to, rather than all
1,300 fired anywhere in the plugin.

BASELINE. 32 hooks already carried `@since` without a guide entry when this gate
was written. Documenting all of them is real work and is not a reason to leave
the gate off in the meantime, so they are listed in the baseline file beside this
script. The gate blocks anything NEW. The baseline only shrinks: document one,
remove its line.

Usage: python3 bin/check-public-hook-docs.py     (exit 1 on a new undocumented hook)
"""

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
GUIDE = ROOT / "docs" / "website" / "developer-guide"
BASELINE = pathlib.Path(__file__).resolve().parent / "public-hook-docs-baseline.json"

# A docblock carrying @since, immediately followed by the hook call. The optional
# assignment / cast between them is how apply_filters() is normally written.
HOOK = re.compile(
    r"/\*\*(?:(?!\*/).)*?@since\s+[0-9][^\n]*(?:(?!\*/).)*?\*/\s*"
    r"(?:\$[\w>\-\[\]']+\s*=\s*)?(?:\(\s*[a-z]+\s*\)\s*)?"
    r"(apply_filters|do_action)\(\s*'([a-z_]+)'",
    re.S,
)


def main() -> int:
    promised: dict[str, tuple[str, str]] = {}
    for path in sorted((ROOT / "includes").rglob("*.php")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        for m in HOOK.finditer(text):
            promised.setdefault(m.group(2), (m.group(1), str(path.relative_to(ROOT))))

    if not promised:
        print("public-hook-docs: found no @since hooks — the scanner is broken", file=sys.stderr)
        return 1

    guide_text = " ".join(
        p.read_text(encoding="utf-8", errors="ignore") for p in sorted(GUIDE.glob("*.md"))
    )

    baseline = set()
    if BASELINE.is_file():
        baseline = set(json.loads(BASELINE.read_text(encoding="utf-8")).get("undocumented", []))

    undocumented = {h for h in promised if h not in guide_text}
    new = sorted(undocumented - baseline)
    # A baselined hook that no longer exists is "stale", not "recovered" — without
    # this it is reported under both headings, which reads as two problems.
    stale = sorted(h for h in baseline if h not in promised)
    recovered = sorted((baseline - undocumented) & set(promised))

    if recovered:
        print(f"  {len(recovered)} baselined hook(s) are now documented — remove them from")
        print(f"  {BASELINE.relative_to(ROOT)} so the baseline keeps shrinking:")
        for h in recovered:
            print(f"    {h}")
    if stale:
        print(f"  {len(stale)} baselined hook(s) no longer exist — remove them too:")
        for h in stale:
            print(f"    {h}")

    if new:
        print(
            f"\n✗ {len(new)} hook(s) carry an @since docblock but appear nowhere in "
            f"docs/website/developer-guide/:",
            file=sys.stderr,
        )
        for h in new:
            kind, where = promised[h]
            print(f"    {h}  ({kind}, {where})", file=sys.stderr)
        print(
            "\n  An @since tag is a public promise. Either document the hook on the right\n"
            "  per-domain page (25-33), or drop the @since if it was not meant to be API.\n"
            "  Do not add it to the baseline — the baseline is for the pre-existing set only.",
            file=sys.stderr,
        )
        return 1

    print(
        f"✓ public hook docs conform — {len(promised)} @since hooks, "
        f"{len(promised) - len(undocumented)} documented, {len(undocumented)} baselined"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

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

SECOND CHECK (every hook). The @since gate cannot see a hook nobody tagged, which is how
four shipped unmentioned. So every literal `buddynext_*` hook fired in includes/ or
templates/ must also be named somewhere in the developer guide, @since or not. Template
part hooks (`buddynext_part_*`, hundreds of them) are covered by the pattern documented in
guide 26 instead of one line each; the gate checks that the pattern is still there.
Hooks fired through a variable name are not literal and are not seen.

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


FIRED = re.compile(r"(?:apply_filters|do_action)(?:_ref_array)?\(\s*'(buddynext_[a-z0-9_]+)'")


def every_hook_check(guide_text: str) -> int:
    """Every literal hook fired anywhere must be named in the guide (parts by pattern)."""
    fired: dict[str, str] = {}
    for folder in ("includes", "templates"):
        for path in sorted((ROOT / folder).rglob("*.php")):
            text = path.read_text(encoding="utf-8", errors="ignore")
            for m in FIRED.finditer(text):
                fired.setdefault(m.group(1), str(path.relative_to(ROOT)))

    guide26 = sorted(GUIDE.glob("26-*.md"))
    if not guide26 or "buddynext_part_" not in guide26[0].read_text(encoding="utf-8", errors="ignore"):
        print("✗ guide 26 no longer documents the buddynext_part_* hook pattern", file=sys.stderr)
        return 1

    missing = sorted(h for h in fired if not h.startswith("buddynext_part_") and h not in guide_text)
    if missing:
        print(f"\n✗ {len(missing)} hook(s) are fired but named nowhere in docs/website/developer-guide/:", file=sys.stderr)
        for h in missing:
            print(f"    {h}  ({fired[h]})", file=sys.stderr)
        print(
            "\n  Add each to the right per-domain page: signature, when it fires, an example.\n"
            "  A hook that is private plumbing should not be named buddynext_* — rename it or\n"
            "  document it. There is no baseline for this check.",
            file=sys.stderr,
        )
        return 1
    return 0


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

    status = every_hook_check(guide_text)
    if status:
        return status

    print(
        f"✓ public hook docs conform — {len(promised)} @since hooks, "
        f"{len(promised) - len(undocumented)} documented, {len(undocumented)} baselined"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

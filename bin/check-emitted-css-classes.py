#!/usr/bin/env python3
"""A2 — an emitted design-system class must resolve to a CSS rule.

Card 10123417821: Pro's AdvancedFieldRenderer emitted `class="bn-chip"`, but only
`.bn-field-chip` is styled — `.bn-chip` has no rule anywhere. The selected values
rendered as unstyled inline text. The tell was a STYLED SIBLING: a more-qualified
class (`bn-field-chip`) IS styled while the barer one the markup used is not — the
signature of a wrong/typo'd class, distinct from a class that is simply an unstyled
JS hook or block wrapper.

Two layers, matching the agreed design:

  BLOCKING — "styled-sibling near-miss". For every `class="..."` attribute in PHP /
  templates whose element is ENTIRELY unstyled by our design system (no `bn-*` token
  on it resolves to a rule in the COMBINED free+pro CSS), if one of its `bn-*` tokens
  has a styled qualified-sibling (`bn-<qualifier>-<remainder>` exists as a rule), the
  element is flagged. `bn-chip` alone next to a styled `bn-field-chip` fails; once the
  markup is `class="bn-chip bn-field-chip"` the element is styled and passes. A small
  frozen baseline (`.a2-emitted-class-baseline.json`) carries pre-existing near-misses
  so the gate is green today and fails only on NEW ones.

  ADVISORY (`--report`) — writes every emitted `bn-*` class with no rule to
  `audit/emitted-class-report.md` for gradual cleanup. Never blocks (audit/ is
  untracked). Run `bin/check-emitted-css-classes.py --report` to refresh it.

Exit 0 = no new styled-sibling near-miss beyond the baseline.
"""

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
# Pro sits beside Free in the plugins dir; override with BN_PRO_PATH.
import os
PRO = Path(os.environ.get("BN_PRO_PATH", ROOT.parent / "buddynext-pro"))
BASELINE = ROOT / ".a2-emitted-class-baseline.json"
REPORT = ROOT / "audit" / "emitted-class-report.md"

CLASS_ATTR = re.compile(r"""class=(["'])([^"']*bn-[^"']*)\1""")


def css_classes() -> set:
    found = set()
    for base in (ROOT, PRO):
        assets = base / "assets"
        if not assets.exists():
            continue
        for css in assets.rglob("*.css"):
            found |= set(re.findall(r"\.(bn-[a-z0-9_-]+)", css.read_text(errors="ignore")))
    return found


def styled_sibling(cls: str, css: set):
    """A more-qualified styled class bn-<q>-<remainder> for a barer emitted bn-<remainder>."""
    if "__" in cls or "--" in cls:
        return None
    rem = cls[3:]  # strip 'bn-'
    if len(rem) < 4:
        return None
    for y in css:
        if y != cls and y.endswith("-" + rem):
            return y
    return None


def scan(css: set):
    """Yield (relpath, line, attr, token, sibling) for every fully-unstyled near-miss element."""
    for base, label in ((ROOT, "free"), (PRO, "pro")):
        for sub in ("includes", "templates"):
            d = base / sub
            if not d.exists():
                continue
            for php in d.rglob("*.php"):
                txt = php.read_text(errors="ignore")
                for m in CLASS_ATTR.finditer(txt):
                    attr = m.group(2)
                    if "$" in attr or "<?" in attr:
                        continue
                    toks = [t for t in attr.split() if t.startswith("bn-")]
                    if not toks or any(t in css for t in toks):
                        continue  # element carries at least one styled class → fine
                    for t in toks:
                        sib = styled_sibling(t, css)
                        if sib:
                            line = txt.count("\n", 0, m.start()) + 1
                            yield (f"{label}:{php.relative_to(base)}", line, attr.strip(), t, sib)
                            break


def write_report(css: set):
    emitted = {}
    for base, label in ((ROOT, "free"), (PRO, "pro")):
        for sub in ("includes", "templates"):
            d = base / sub
            if not d.exists():
                continue
            for php in d.rglob("*.php"):
                for m in CLASS_ATTR.finditer(php.read_text(errors="ignore")):
                    attr = m.group(2)
                    if "$" in attr or "<?" in attr:
                        continue
                    for t in attr.split():
                        if t.startswith("bn-") and t not in css:
                            emitted.setdefault(t, f"{label}:{php.relative_to(base)}")
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    lines = ["# A2 advisory — emitted bn-* classes with no CSS rule", "",
             f"{len(emitted)} distinct classes. Most are legitimate (block wrappers, JS hooks,",
             "BEM modifiers styled via the base). Triage for real 'wrong class' bugs; the blocking",
             "gate only fires on styled-sibling near-misses.", ""]
    for t in sorted(emitted):
        lines.append(f"- `{t}` — e.g. {emitted[t]}")
    REPORT.write_text("\n".join(lines) + "\n")
    print(f"wrote {REPORT.relative_to(ROOT)} ({len(emitted)} classes)")


def main():
    css = css_classes()
    if "--report" in sys.argv:
        write_report(css)
        return 0
    baseline = set(json.loads(BASELINE.read_text())) if BASELINE.exists() else set()
    new = []
    for loc, line, attr, tok, sib in scan(css):
        if tok in baseline:
            continue
        new.append((loc, line, attr, tok, sib))
    if new:
        print("A2 emitted-class gate — NEW styled-sibling near-miss(es):\n")
        for loc, line, attr, tok, sib in new:
            print(f"  ✗ {loc}:{line}  class=\"{attr[:60]}\"  — `{tok}` has no rule but `{sib}` is styled")
        print("\nUse the styled sibling, or add the intended class's CSS rule. If genuinely an "
              "unstyled hook, add the class name to .a2-emitted-class-baseline.json with a note.")
        return 1
    print("A2 emitted-class gate: no new styled-sibling near-miss.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

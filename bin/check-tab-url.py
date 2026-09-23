#!/usr/bin/env python3
"""Every AdminHub::tab_url() call must resolve to a registered admin tab.

WHY THIS EXISTS. Card 10264294727 (and the adjacent 10264294456): an owner CTA
built with `AdminHub::tab_url( 'section', 'tab' )` silently misrouted when a subtab
was moved or renamed — the URL still resolved to a page, just the wrong one, so a
"Set up payments" button landed on a 403 and nothing in CI noticed. `tab_url()`
never fails loudly: an unknown tab just becomes `?page=<section>&tab=<slug>` that
renders the section's default. This gate makes a moved/typo'd tab a build failure
instead of a live misroute.

WHAT IT CHECKS. A `tab_url( 'section', 'tab' )` is CORRECT iff a tab with that slug
is registered (via `AdminHub::register_tab( 'origin', 'slug', ... )` in Free OR Pro)
and the section passed is either the tab's ORIGIN section or the FINAL section the
canonical placement map (`AdminHub::TAB_PLACEMENT`) relocates it to — because
`tab_url()` applies that same remap (AdminHub.php:889). A `hidden` placement drops
the tab, so a tab_url to it is dead and is flagged too.

LIMITATIONS (documented, conservative — the gate only ever flags a LITERAL mismatch):
  - Only literal `'section', 'tab'` argument pairs are checked; a variable arg is skipped.
  - Placement added at runtime via the `bn_admin_hub_tab_placement` filter is not
    visible statically. A tab a site MOVES with that filter, and a tab_url that
    targets the moved location, would read as a mismatch here — so such pairs go in
    the BASELINE beside this script (which only shrinks), not the code.

Usage: python3 bin/check-tab-url.py        (exit 1 on an unresolved tab_url)
"""

import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
FREE = os.path.dirname(HERE)
PRO = os.environ.get("BUDDYNEXT_PRO_PATH", os.path.join(os.path.dirname(FREE), "buddynext-pro"))
BASELINE = os.path.join(HERE, "check-tab-url.baseline")

SLUG = r"[a-z0-9_-]+"
REGISTER_TAB = re.compile(r"register_tab\(\s*'(" + SLUG + r")'\s*,\s*'(" + SLUG + r")'")
# The loop form: register_tab( 'section', $slug, ... ) inside `foreach ($tabs as $slug ...)`.
# When a file registers into a literal SECTION with a variable slug, its tab slugs
# are the keys of the local `'slug' => __( 'Label' … )` tab-list array.
REGISTER_TAB_LOOP = re.compile(r"register_tab\(\s*'(" + SLUG + r")'\s*,\s*\$")
TAB_LIST_KEY = re.compile(r"'(" + SLUG + r")'\s*=>\s*__\(")
# A tab may declare nested sub-tabs a hub router treats as valid links to it
# (register_tab arg `'subtabs' => self::CONST` or an inline array). Reached via
# tab_url( 'section', '<subtab>' ), so each subtab is a valid tab of that section.
SUBTABS_CONST = re.compile(r"'subtabs'\s*=>\s*self::(\w+)")
SUBTABS_INLINE = re.compile(r"'subtabs'\s*=>\s*array\((.*?)\)", re.DOTALL)
STRING_ITEM = re.compile(r"'(" + SLUG + r")'")
# A tab_url call with two literal string args (the case we can resolve). A call
# whose first or second arg is a variable is deliberately not matched → skipped.
TAB_URL_LITERAL = re.compile(r"tab_url\(\s*'(" + SLUG + r")'\s*,\s*'(" + SLUG + r")'")
TAB_URL_ANY = re.compile(r"\btab_url\(")


def read(path):
    with open(path, "r", encoding="utf-8") as fh:
        return fh.read()


def walk_php(root):
    for base, _dirs, files in os.walk(root):
        if any(seg in base for seg in ("/vendor/", "/node_modules/", "/tests/")):
            continue
        for name in files:
            if name.endswith(".php"):
                yield os.path.join(base, name)


def parse_placement(free_root):
    """origin 'section:slug' -> {'section': final, 'hidden': bool} from AdminHub::TAB_PLACEMENT."""
    hub = os.path.join(free_root, "includes", "Admin", "AdminHub.php")
    src = read(hub)
    start = src.find("const TAB_PLACEMENT = array(")
    if start < 0:
        return {}
    # Walk to the matching close paren of the array literal.
    i = src.find("array(", start) + len("array(")
    depth = 1
    while i < len(src) and depth:
        if src[i] == "(":
            depth += 1
        elif src[i] == ")":
            depth -= 1
        i += 1
    block = src[start:i]
    placement = {}
    for m in re.finditer(r"'(" + SLUG + r"):(" + SLUG + r")'\s*=>\s*array\((.*?)\)", block, re.DOTALL):
        origin_sec, slug, body = m.group(1), m.group(2), m.group(3)
        sec_m = re.search(r"'section'\s*=>\s*'(" + SLUG + r")'", body)
        hidden = re.search(r"'hidden'\s*=>\s*true", body) is not None
        placement[origin_sec + ":" + slug] = {
            "section": sec_m.group(1) if sec_m else origin_sec,
            "hidden": hidden,
        }
    return placement


def collect_registered(roots):
    """Set of (origin_section, slug) from every register_tab() call in the given repos.

    Covers both the literal form register_tab('sec','slug', …) and the loop form
    register_tab('sec', $slug, …) that iterates a local 'slug' => __('Label') tab
    map — the pattern Settings.php and the other multi-tab screens use.
    """
    pairs = set()
    for root in roots:
        if not os.path.isdir(root):
            continue
        for path in walk_php(root):
            src = read(path)
            for m in REGISTER_TAB.finditer(src):
                pairs.add((m.group(1), m.group(2)))
            loop_sections = {m.group(1) for m in REGISTER_TAB_LOOP.finditer(src)}
            if loop_sections:
                keys = {m.group(1) for m in TAB_LIST_KEY.finditer(src)}
                for sec in loop_sections:
                    for slug in keys:
                        pairs.add((sec, slug))
            # Sub-tabs: harvest their slugs and attach them to every section this
            # file registers a tab into (an admin screen registers into one section).
            subtabs = set()
            for m in SUBTABS_CONST.finditer(src):
                const_m = re.search(r"const\s+" + re.escape(m.group(1)) + r"\s*=\s*array\((.*?)\)", src, re.DOTALL)
                if const_m:
                    subtabs.update(STRING_ITEM.findall(const_m.group(1)))
            for m in SUBTABS_INLINE.finditer(src):
                subtabs.update(STRING_ITEM.findall(m.group(1)))
            if subtabs:
                file_sections = {m.group(1) for m in REGISTER_TAB.finditer(src)} | loop_sections
                for sec in file_sections:
                    for slug in subtabs:
                        pairs.add((sec, slug))
    return pairs


def collect_tab_url_calls(roots):
    """(section, tab, 'relpath:line') for each LITERAL tab_url call, plus a count of skipped dynamic ones."""
    calls = []
    skipped = 0
    for root in roots:
        if not os.path.isdir(root):
            continue
        for path in walk_php(root):
            src = read(path)
            for i, line in enumerate(src.splitlines(), 1):
                for _ in TAB_URL_ANY.finditer(line):
                    lit = TAB_URL_LITERAL.search(line)
                    if lit:
                        rel = os.path.relpath(path, os.path.dirname(root))
                        calls.append((lit.group(1), lit.group(2), f"{rel}:{i}"))
                    else:
                        skipped += 1
    return calls, skipped


def load_baseline():
    if not os.path.exists(BASELINE):
        return set()
    out = set()
    for line in read(BASELINE).splitlines():
        line = line.split("#", 1)[0].strip()
        if line:
            out.add(line)
    return out


def final_section(origin_sec, slug, placement):
    """The section a registered tab actually renders on, or None if hidden."""
    rule = placement.get(origin_sec + ":" + slug)
    if rule and rule["hidden"]:
        return None
    return rule["section"] if rule else origin_sec


def main():
    placement = parse_placement(FREE)
    registered = collect_registered([FREE, PRO])

    # slug -> the set of sections it actually renders on (after placement). Normally
    # one; a set covers the rare slug registered from two origin sections.
    placed = {}
    for origin_sec, slug in registered:
        sec = final_section(origin_sec, slug, placement)
        if sec is not None:
            placed.setdefault(slug, set()).add(sec)

    calls, skipped = collect_tab_url_calls([FREE, PRO])
    baseline = load_baseline()

    problems = []
    for section, tab, where in calls:
        if f"{section}:{tab}" in baseline:
            continue
        # tab_url() remaps the passed section through placement exactly as the real
        # helper does (AdminHub.php:889), then the tab must actually render there.
        rule = placement.get(section + ":" + tab)
        effective = rule["section"] if (rule and not rule["hidden"]) else section
        if tab in placed and effective in placed[tab]:
            continue
        problems.append((section, tab, where))

    if problems:
        print("tab_url() calls that resolve to no registered admin tab:\n")
        for section, tab, where in sorted(problems, key=lambda p: p[2]):
            print(f"  {where}: tab_url( '{section}', '{tab}' ) -> no tab '{tab}' placed in section '{section}'")
        print(
            "\nEither the tab was moved/renamed (fix the call to its current section/slug), "
            "or it is placed at runtime via bn_admin_hub_tab_placement (add 'section:tab' to "
            "bin/check-tab-url.baseline)."
        )
        return 1

    print(
        f"tab-url gate: OK — {len(calls)} literal tab_url() calls all resolve "
        f"({len(registered)} registered tabs; {skipped} dynamic calls skipped)."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""A hook or service the cookbook tells a developer to call must actually exist.

WHY THIS EXISTS. Card 10264294920: two documented extension recipes silently did
nothing. One hooked `buddynext_isolation_whitelist` — a filter that does not
exist (the real one is `buddynext_isolation_plugins`); the other called
`buddynext_service('posts')` — a container id that does not exist (it is
`post_service`). A developer following the cookbook installed code that threw or
no-oped, with no error to chase, and nothing in CI caught the drift.

`check-hook-docs.py` gates do_action() arg counts; `check-public-hook-docs.py`
gates that @since hooks are documented (code -> docs). NEITHER validates the
reverse for the cookbook: that a `buddynext_*` hook name or service id a RECIPE
tells you to call is real (docs -> code). This does.

WHAT IT CHECKS. Inside ```php fenced blocks in the developer-guide cookbook, every
  - add_action / add_filter / apply_filters / do_action( 'buddynext[pro]_...' )
  - buddynext_service( '<id>' )
must resolve against the actual code of Free AND Pro:
  - hook names -> a do_action()/apply_filters() with that name in either repo,
  - service ids -> a container bind('<id>') / singleton('<id>') in either repo.

Anything unresolved is drift. A short BASELINE beside this script carries
references that are legitimately external (a partner plugin's hook); it only
shrinks.

Usage: python3 bin/check-cookbook-hooks.py     (exit 1 on an unresolved reference)
"""

import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
FREE = os.path.dirname(HERE)
PRO = os.environ.get("BUDDYNEXT_PRO_PATH", os.path.join(os.path.dirname(FREE), "buddynext-pro"))
COOKBOOK = os.path.join(FREE, "docs", "website", "developer-guide", "41-extending-cookbook.md")
BASELINE = os.path.join(HERE, "check-cookbook-hooks.baseline")

HOOK_CALL = re.compile(
    r"\b(?:add_action|add_filter|apply_filters|apply_filters_ref_array|do_action|do_action_ref_array)\s*\(\s*'([a-z0-9_]+)'"
)
SERVICE_CALL = re.compile(r"\bbuddynext_service\s*\(\s*'([a-z0-9_]+)'")
FENCE = re.compile(r"```php(.*?)```", re.DOTALL)

# Hooks the recipes fire/consume that are defined by a partner or WP core, not us.
BUDDYNEXT_PREFIX = ("buddynext_", "buddynextpro_")


def read(path):
    with open(path, "r", encoding="utf-8") as fh:
        return fh.read()


def walk_php(root):
    for base, _dirs, files in os.walk(root):
        if "/vendor/" in base or "/node_modules/" in base or "/tests/" in base:
            continue
        for name in files:
            if name.endswith(".php"):
                yield os.path.join(base, name)


def collect_defined(roots):
    """Every hook name fired and every service id bound across the given repos."""
    hooks, services = set(), set()
    fire = re.compile(r"\b(?:do_action|do_action_ref_array|apply_filters|apply_filters_ref_array)\s*\(\s*'([a-z0-9_]+)'")
    bind = re.compile(r"->(?:bind|singleton|instance)\s*\(\s*'([a-z0-9_]+)'")
    for root in roots:
        if not os.path.isdir(root):
            continue
        for php in walk_php(root):
            src = read(php)
            hooks.update(fire.findall(src))
            services.update(bind.findall(src))
    return hooks, services


def load_baseline():
    if not os.path.isfile(BASELINE):
        return set()
    out = set()
    for line in read(BASELINE).splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            out.add(line)
    return out


def main():
    if not os.path.isfile(COOKBOOK):
        print(f"cookbook not found at {COOKBOOK}", file=sys.stderr)
        return 1

    defined_hooks, defined_services = collect_defined([FREE, PRO])
    baseline = load_baseline()

    problems = []
    for block in FENCE.findall(read(COOKBOOK)):
        for name in HOOK_CALL.findall(block):
            if not name.startswith(BUDDYNEXT_PREFIX):
                continue  # WP core / partner hook, out of scope.
            if name not in defined_hooks and name not in baseline:
                problems.append(f"hook '{name}' is used in a cookbook recipe but fired nowhere in Free or Pro")
        for sid in SERVICE_CALL.findall(block):
            if sid not in defined_services and sid not in baseline:
                problems.append(f"service id '{sid}' is used in a cookbook recipe but bound nowhere in Free or Pro")

    problems = sorted(set(problems))
    if problems:
        print("Cookbook references code that does not exist:\n", file=sys.stderr)
        for p in problems:
            print(f"  - {p}", file=sys.stderr)
        print(
            "\nFix the recipe to name the real hook/service, or add a truly external "
            "reference to bin/check-cookbook-hooks.baseline.",
            file=sys.stderr,
        )
        return 1

    hook_refs = sum(1 for b in FENCE.findall(read(COOKBOOK)) for _ in HOOK_CALL.findall(b))
    print(f"cookbook hooks clean — every buddynext_* hook/service a recipe calls resolves in Free or Pro ({hook_refs} hook refs, {len(defined_services)} services known)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

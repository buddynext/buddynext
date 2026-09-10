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
COOKBOOK_DIR = os.path.join(FREE, "docs", "website", "developer-guide")
BASELINE = os.path.join(HERE, "check-cookbook-hooks.baseline")


def cookbook_files():
    """Every developer-guide cookbook doc, not just the extending recipes.

    A wrong hook/service name can drift in ANY recipe doc — the drifts this gate
    was filed to catch were in 23-rest-webhooks.md and 28-hooks-*.md, which the
    single-file scan never looked at (card 10264294920). Sorted for stable output.
    """
    import glob
    return sorted(glob.glob(os.path.join(COOKBOOK_DIR, "*.md")))

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
    """Every hook name fired and every service id bound across the given repos.

    Beyond string-literal hook calls, this also resolves two real patterns the
    earlier version missed and false-flagged (card 10264294920):

      - Constant/variable hook names — apply_filters( self::FILTER_MANIFEST, … )
        where `FILTER_MANIFEST = 'buddynext_pwa_manifest'`. Any buddynext_* string
        literal ASSIGNED in the source is treated as a known name, so a recipe that
        names the real hook resolves; a typo that appears nowhere still fails.
      - Dynamic hooks — apply_filters( "buddynext_feature_{$slug}", … ). The static
        prefix (`buddynext_feature_`) is captured so a concrete example like
        buddynext_feature_sidebar resolves.

    Returns (hooks, services, dynamic_prefixes).
    """
    hooks, services, prefixes = set(), set(), set()
    fire = re.compile(r"\b(?:do_action|do_action_ref_array|apply_filters|apply_filters_ref_array)\s*\(\s*'([a-z0-9_]+)'")
    bind = re.compile(r"->(?:bind|singleton|instance)\s*\(\s*'([a-z0-9_]+)'")
    # A dynamic hook: "buddynext_..._{$var}" — capture the static prefix.
    dynamic = re.compile(r'"((?:buddynext_|buddynextpro_)[a-z0-9_]*?)\{\$')

    # Hooks held in a CONSTANT/PROPERTY, e.g. `const FILTER_MANIFEST =
    # 'buddynext_pwa_manifest';` then `apply_filters( self::FILTER_MANIFEST, … )`.
    # The old rule accepted ANY `= 'buddynext_*'` literal as a hook, so 17 option/
    # cache/transient KEYS (buddynext_isolation_keep, buddynext_mu_plugin_sig,
    # buddynext_schema_failure, …) registered as valid hook names — a recipe that
    # named one of them as a filter would wrongly pass, the exact drift class this
    # gate exists to catch (card 10264294920). Now a held literal counts only when
    # its holder is actually PASSED to a hook function (directly or via self:: /
    # static:: / $this->), which is what makes it a hook rather than an option key.
    #   name_to_literals: NAME -> {literal, …} for every buddynext_* held in a
    #                     const or property (set-valued so a name reused across
    #                     classes keeps both, erring toward accept as before).
    #   hook_ref_names:   identifiers passed as the hook name to a hook function.
    held_decl = re.compile(
        r"(?:const\s+|(?:private|protected|public|static|var|final|readonly)\s+(?:const\s+|(?:static\s+)?\$)|\$(?:this->)?)"
        r"([A-Za-z_][A-Za-z0-9_]*)\s*=\s*'((?:buddynext_|buddynextpro_)[a-z0-9_]+)'"
    )
    hook_by_ref = re.compile(
        r"\b(?:add_action|add_filter|apply_filters|apply_filters_ref_array|do_action|do_action_ref_array)"
        r"\s*\(\s*(?:self::|static::|\$this->)?\$?([A-Za-z_][A-Za-z0-9_]*)"
    )

    name_to_literals = {}
    hook_ref_names = set()
    for root in roots:
        if not os.path.isdir(root):
            continue
        for php in walk_php(root):
            src = read(php)
            hooks.update(fire.findall(src))
            services.update(bind.findall(src))
            prefixes.update(dynamic.findall(src))
            for name, literal in held_decl.findall(src):
                name_to_literals.setdefault(name, set()).add(literal)
            hook_ref_names.update(hook_by_ref.findall(src))

    for name, literals in name_to_literals.items():
        if name in hook_ref_names:
            hooks.update(literals)
    return hooks, services, prefixes


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
    files = cookbook_files()
    if not files:
        print(f"no cookbook docs found under {COOKBOOK_DIR}", file=sys.stderr)
        return 1

    defined_hooks, defined_services, dynamic_prefixes = collect_defined([FREE, PRO])
    baseline = load_baseline()

    def hook_known(name):
        if name in defined_hooks or name in baseline:
            return True
        return any(pref and name.startswith(pref) for pref in dynamic_prefixes)

    problems = []
    hook_refs = 0
    for path in files:
        rel = os.path.basename(path)
        for block in FENCE.findall(read(path)):
            for name in HOOK_CALL.findall(block):
                if not name.startswith(BUDDYNEXT_PREFIX):
                    continue  # WP core / partner hook, out of scope.
                hook_refs += 1
                if not hook_known(name):
                    problems.append(f"{rel}: hook '{name}' is used in a recipe but fired nowhere in Free or Pro")
            for sid in SERVICE_CALL.findall(block):
                if sid not in defined_services and sid not in baseline:
                    problems.append(f"{rel}: service id '{sid}' is used in a recipe but bound nowhere in Free or Pro")

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

    print(f"cookbook hooks clean — every buddynext_* hook/service a recipe calls resolves in Free or Pro ({hook_refs} hook refs across {len(files)} docs, {len(defined_services)} services known)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

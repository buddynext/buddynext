#!/usr/bin/env bash
# Every bn-* class a block template emits must be DEFINED in a stylesheet that
# block's style array can actually reach.
#
# This is the check Basecamp 10182506250 asked for, and it is a level below
# bin/check-block-styles.sh. That one asserts a block declares a NAMED handle so
# the token layer loads — a real regression guard, but handle-level. It passes
# green while a template emits a class that exists in no stylesheet at all,
# which is the same bug wearing different clothes: markup with nothing to style
# it once the block leaves a BuddyNext hub.
#
# Proof it was needed: post-composer shipped bn-composer-trigger and
# bn-composer-input-wrap, and search-bar shipped bn-search-form, all defined
# nowhere, with both existing gates passing.
#
# Reachability is resolved transitively: a block's `style` entries are followed
# through the dependency graph declared in AssetService (bn-* feature sheets
# depend on bn-base; bn-spaces also on bn-members; bn-media-upload on bn-media),
# so a class defined in bn-base counts for any block naming a feature handle.
set -u
cd "$(dirname "$0")/.." || exit 1

report=$(python3 - <<'PY'
import glob
import json
import os
import re

# Dependency graph, mirroring AssetService::register_assets(). Kept explicit
# rather than parsed out of PHP: it is five lines and a wrong parse would make
# this gate lie in the permissive direction.
DEPS = {
    'bn-base': ['bn-fonts'],
    'bn-spaces': ['bn-base', 'bn-members'],
    'bn-media-upload': ['bn-base', 'bn-media'],
}
DEFAULT_DEPS = ['bn-base']

# Classes applied at runtime by the Interactivity API never appear in markup as
# a literal, and state modifiers are legitimately styled without being emitted
# server-side. Both are documented false positives in the ux-audit skill.
RUNTIME_SUFFIXES = (
    'is-', 'has-', 'bn-is-', 'bn-has-',
)


def sheet_for(handle):
    """Resolve a style entry to a CSS file path, or None."""
    if handle.startswith('file:'):
        rel = handle[len('file:'):]
        return os.path.normpath(os.path.join('blocks', 'x', rel))
    candidate = os.path.join('assets', 'css', handle + '.css')
    return candidate if os.path.exists(candidate) else None


def reachable_sheets(style_entries):
    seen, queue, files = set(), list(style_entries), []
    while queue:
        entry = queue.pop()
        if entry in seen:
            continue
        seen.add(entry)
        path = sheet_for(entry)
        if path and os.path.exists(path):
            files.append(path)
        if not entry.startswith('file:'):
            queue.extend(DEPS.get(entry, DEFAULT_DEPS))
    return files


def defined_classes(paths):
    found = set()
    for path in paths:
        css = open(path, encoding='utf-8', errors='replace').read()
        css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
        # Selector position only — ignore class names inside comments/content.
        for block in re.finditer(r'([^{}]+)\{', css):
            for cls in re.findall(r'\.([A-Za-z0-9_-]+)', block.group(1)):
                found.add(cls)
    return found


problems = []

for block_json in sorted(glob.glob('blocks/*/block.json')):
    meta = json.load(open(block_json, encoding='utf-8'))
    name = meta.get('name', block_json)
    slug = name.split('/')[-1]

    template = os.path.join('templates', 'blocks', slug + '.php')
    if not os.path.exists(template):
        continue

    style = meta.get('style')
    style = style if isinstance(style, list) else ([style] if style else [])
    if not style:
        continue

    sheets = reachable_sheets(style)
    known = defined_classes(sheets)

    markup = open(template, encoding='utf-8', errors='replace').read()
    # Strip comments first. Templates document the markup they REPLACED — the
    # member-directory and space-directory headers both quote the old
    # `<ul class="bn-member-list">` they no longer emit — and scanning those
    # reports a class the block does not ship. A gate that cries wolf gets
    # ignored, so this is stripped rather than allow-listed.
    markup = re.sub(r'/\*.*?\*/', '', markup, flags=re.S)   # PHP block comments
    markup = re.sub(r'<!--.*?-->', '', markup, flags=re.S)   # HTML comments
    markup = re.sub(r'(?m)^\s*//.*$', '', markup)            # PHP line comments
    # Only static class attributes. A PHP-interpolated class is not something
    # this gate can resolve, so it is skipped rather than guessed at.
    emitted = set()
    for attr in re.findall(r'class="([^"<>]*)"', markup):
        if '<?php' in attr or '?>' in attr:
            continue
        for cls in attr.split():
            if cls.startswith('bn-') and not cls.startswith(RUNTIME_SUFFIXES):
                emitted.add(cls)

    missing = sorted(c for c in emitted if c not in known)
    if missing:
        problems.append(
            f'  x {name}\n'
            f'      template: {template}\n'
            f'      style:    {style}\n'
            f'      undefined: {", ".join(missing)}'
        )

print('\n'.join(problems))
PY
)

if [ -n "$report" ]; then
	echo "$report"
	echo "block-class-coverage: FAILED — a block emits a class no reachable stylesheet defines."
	echo "                      Either style the class in a sheet the block already names,"
	echo "                      or name the handle that owns it. Do NOT add a file: ref."
	exit 1
fi

echo "block-class-coverage: clean — every emitted bn-* class is defined in a reachable stylesheet"

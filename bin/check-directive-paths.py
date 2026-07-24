#!/usr/bin/env python3
"""Fail on Interactivity API directives whose value is an expression, not a path.

WHY THIS EXISTS

The Interactivity API resolves a directive value as a PROPERTY PATH into
state/context, optionally prefixed with a single "!". It does not evaluate
JavaScript. So a binding like

    data-wp-bind--hidden="!(context.isDirty && !context.isSaving)"

is not "false when dirty" — the API strips the "!", tries to resolve
"(context.isDirty && !context.isSaving)" as a path, gets undefined, and negates
it to true. The element is hidden permanently, in every state.

That failure is invisible in review and invisible in CI: the page renders, the
markup looks intentional, and nothing errors. It shipped on both save bars —
notification preferences (all three status pills dead, so the bar appeared with
no word about why) and profile edit ("Unsaved changes" never shown). Both were
found only by measuring the DOM in a browser, one viewport at a time.

The fix is always the same: move the comparison into a computed getter on the
store and bind to `state.something`. This check exists so the next one is caught
at commit time instead.

Exit 0 when clean, 1 when any expression-valued directive is found.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Directives whose value the API resolves as a path. data-wp-on--* takes an
# action name and data-wp-context takes JSON, so both are excluded.
DIRECTIVE = re.compile(
    r'data-wp-(?:bind|class|style|text|each|each-key)(?:--[a-zA-Z0-9_-]+)?\s*=\s*"([^"]*)"'
)

# Templates build these attributes in three ways, and all three yield a plain
# path at render time. Each is collapsed to a single identifier before the value
# is judged, or the check reports code that is already correct:
#
#   state.isStep<?php echo $n; ?>                     escaping to PHP mid-path
#   ' data-wp-text="context.errors.' . esc_attr( $k ) . '"'   concatenated attribute
#   context.errors.{$key}                             interpolated into a string
INTERPOLATION = (
    re.compile(r'<\?php.*?\?>', re.DOTALL),          # <?php ... ?>
    re.compile(r"'\s*\.\s*.*?\s*\.\s*'", re.DOTALL),  # ' . expr . '
    re.compile(r'\{\$[^}]*\}'),                       # {$var}
    re.compile(r'\$\{[^}]*\}'),                       # ${var}
)

# A legal value once interpolation is collapsed: an optional "!", then a dotted
# path of identifiers.
PATH_OK = re.compile(r'^\s*!?\s*[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*\s*$')

# PHPDoc and block comments document these directives by example — see
# parts/profile-edit-account-row.php, whose docblock literally shows
# `data-wp-bind--hidden="!<expr>"`. Documentation is not shipped markup.
BLOCK_COMMENT = re.compile(r'/\*.*?\*/', re.DOTALL)

# Operators that prove the value is an expression rather than a path. "&amp;&amp;"
# is how && survives in an HTML attribute, and is exactly how both shipped bugs
# were written.
OPERATORS = ('&amp;&amp;', '&&', '||', '===', '!==', '==', '!=', '?', '(', ')', '+', '>', '<')


def blank_comments(text: str) -> str:
    """Replace block comments with spaces, preserving every byte offset."""
    return BLOCK_COMMENT.sub(lambda m: re.sub(r'[^\n]', ' ', m.group(0)), text)


def normalise(value: str) -> str:
    """Collapse each interpolation construct to one identifier."""
    for pattern in INTERPOLATION:
        value = pattern.sub('X', value)
    return value


def scan(path: Path):
    findings = []
    try:
        text = path.read_text(encoding='utf-8')
    except (OSError, UnicodeDecodeError):
        return findings
    for match in DIRECTIVE.finditer(blank_comments(text)):
        value = match.group(1)
        if not value.strip():
            continue
        collapsed = normalise(value)
        if PATH_OK.match(collapsed):
            continue
        if not any(op in collapsed for op in OPERATORS):
            continue
        line = text.count('\n', 0, match.start()) + 1
        findings.append((line, match.group(0)[:110]))
    return findings


def main():
    targets = []
    for sub in ('templates', 'blocks', 'includes'):
        base = ROOT / sub
        if base.is_dir():
            targets.extend(base.rglob('*.php'))

    failures = []
    for path in sorted(targets):
        for line, snippet in scan(path):
            failures.append((path.relative_to(ROOT), line, snippet))

    if failures:
        print('Interactivity directives bound to an expression instead of a path:')
        print()
        for rel, line, snippet in failures:
            print(f'  {rel}:{line}')
            print(f'    {snippet}')
        print()
        print('The API resolves these as a path and silently gets undefined, so the')
        print('binding never reacts. Move the comparison into a computed getter on the')
        print('store and bind to state.<getter>.')
        return 1

    print(f'Interactivity directive paths OK ({len(targets)} templates scanned).')
    return 0


if __name__ == '__main__':
    sys.exit(main())

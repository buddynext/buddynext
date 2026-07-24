#!/usr/bin/env python3
"""A1 — profile field-type gates must read the registry, not a hardcoded list.

Pro registers profile field types at runtime through `buddynext_field_types`
(multi_select_advanced, date_extended, number_advanced, ...). Any consumer that
decides behaviour by field type — "does this type reveal the Options box / the
Date Format box / is it a choice / is it a date" — MUST derive that from the
localised registry, never from a static list that silently omits the add-on
types.

That drift shipped three ways in 1.0.x and became four RFT cards
(10123417821 / 10123417845 / 10123428351): the admin JS hardcoded
CHOICE_TYPES / DATE_TYPES, PHP hardcoded self::DATE_TYPES in three spots, so a
Pro field's Options/Date box stayed hidden and its display setting never
persisted. The bugs were invisible with Free-only fixtures because every CORE
type is in every hardcoded list — only a Pro-registered type exposes the gap.
A pixel/screenshot pass cannot see the persist half at all.

This gate is the cheap static backstop. It is deliberately CONSERVATIVE — it
only flags the known anti-pattern (a field-type reveal gate with no registry
read in reach), so it does not cry wolf on legitimate static enums
(ALLOWED_TYPES, TRANSACTIONAL_TYPES, GROUP_TYPES — those are not the
buddynext_field_types registry and are correctly static).

The sanctioned pattern is registry-FIRST, hardcoded array as fallback only:

    var FIELD_TYPES = window.bnProfileFieldTypes || {};
    var CHOICE_TYPES = [ 'select', 'multiselect', 'radio', 'checkbox' ]; // fallback
    function isChoiceType( type ) {
        if ( FIELD_TYPES[ type ] ) { return !! FIELD_TYPES[ type ].isChoice; }
        return CHOICE_TYPES.indexOf( type ) >= 0; // only if the registry failed to localise
    }

PHP: gate through a registry method (choice_types()/date_types() →
types_with_flag('is_choice'/'is_date')), never a bare
`in_array( $type, self::CHOICE_TYPES )` outside that method.

Exit 0 = every field-type reveal gate consults the registry.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Field-type slugs that indicate an array literal / const is a PROFILE-FIELD-TYPE
# list (as opposed to a notification-type or post-type enum). Two or more of these
# in one array is the signature of a field-type list.
FIELD_TYPE_SLUGS = {
    "select", "multiselect", "radio", "checkbox", "date", "daterange",
    "multi_select_advanced", "date_extended", "number_advanced", "category_multiselect",
}
REGISTRY_JS = re.compile(r"bnProfileFieldTypes")
# JS array literal with >=2 field-type slugs, e.g. [ 'select', 'radio', 'date' ]
JS_TYPE_ARRAY = re.compile(r"\[\s*((?:'[a-z_]+'\s*,?\s*){2,})\]")
JS_GATE = re.compile(r"\.(indexOf|includes)\s*\(")

failures = []


def js_slugs(literal: str) -> set:
    return set(re.findall(r"'([a-z_]+)'", literal)) & FIELD_TYPE_SLUGS


# ── JS: any admin/editor script that gates on a hardcoded field-type array must
#    also read the registry in the same file. ────────────────────────────────
for js in (ROOT / "assets" / "js").rglob("*.js"):
    text = js.read_text(encoding="utf-8", errors="ignore")
    # Does the file build a field-type array AND use it as a gate?
    type_arrays = [m for m in JS_TYPE_ARRAY.finditer(text) if len(js_slugs(m.group(0))) >= 2]
    if not type_arrays:
        continue
    if not JS_GATE.search(text):
        continue  # array exists but is not used as an .indexOf/.includes gate
    if not REGISTRY_JS.search(text):
        rel = js.relative_to(ROOT)
        sample = js_slugs(type_arrays[0].group(0))
        failures.append(
            f"{rel}: gates on a hardcoded field-type list {sorted(sample)} but never reads "
            f"window.bnProfileFieldTypes — Pro types registered via buddynext_field_types will "
            f"not match. Read the registry first, fall back to the list only if it failed to localise."
        )

# ── PHP: no bare `in_array( $x, self::CHOICE_TYPES|DATE_TYPES )` outside the
#    registry methods choice_types()/date_types(). ─────────────────────────────
PHP_BARE_GATE = re.compile(r"in_array\(\s*\$[a-z_]+\s*,\s*self::(CHOICE_TYPES|DATE_TYPES)\b")
for php in (ROOT / "includes").rglob("*.php"):
    text = php.read_text(encoding="utf-8", errors="ignore")
    if "CHOICE_TYPES" not in text and "DATE_TYPES" not in text:
        continue
    for m in PHP_BARE_GATE.finditer(text):
        # Allowed only inside the registry-union methods.
        start = text.rfind("function ", 0, m.start())
        header = text[start:m.start()]
        if re.search(r"function\s+(choice_types|date_types)\s*\(", header):
            continue
        line = text.count("\n", 0, m.start()) + 1
        rel = php.relative_to(ROOT)
        failures.append(
            f"{rel}:{line}: bare `in_array( $x, self::{m.group(1)} )` gates field type on a static "
            f"list — route it through choice_types()/date_types() (types_with_flag) so add-on "
            f"types from buddynext_field_types are recognised."
        )

if failures:
    print("A1 field-type registry gate — FAILURES:\n")
    for f in failures:
        print("  ✗ " + f)
    print(
        "\nFix: derive choice/date/reveal decisions from the buddynext_field_types registry "
        "(JS window.bnProfileFieldTypes; PHP types_with_flag()). A hardcoded list may remain "
        "only as a localise-failure fallback, consulted AFTER the registry."
    )
    sys.exit(1)

print("A1 field-type registry gate: all field-type gates consult the registry.")
sys.exit(0)

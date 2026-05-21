#!/usr/bin/env bash
# UX Audit — scans a Wbcom plugin against ux-foundation rules.
# Usage: ux-audit.sh [plugin_dir]   # defaults to $PWD
#        PREFIX=jt ux-audit.sh ...  # override the plugin CSS prefix

set -uo pipefail

PLUGIN_DIR="${1:-$PWD}"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
PREFIX="${PREFIX:-$(echo "$PLUGIN_NAME" | cut -c1-3)}"

# Skip vendored / built / tests
FIND_EXCLUDES=(
    -not -path "*/vendor/*"
    -not -path "*/node_modules/*"
    -not -path "*/dist/*"
    -not -path "*/build/*"
    -not -path "*/tests/*"
    -not -path "*/.git/*"
)

violation() {
    local severity="$1" rule="$2" file="$3" line="$4" snippet="$5"
    printf '| %s | %s | %s:%s | %s |\n' "$severity" "$rule" "${file#$PLUGIN_DIR/}" "$line" "$snippet"
}

echo "# UX Audit — $PLUGIN_NAME"
echo
echo "_Generated $(date -u +%Y-%m-%dT%H:%M:%SZ)_ — prefix: \`${PREFIX}\`"
echo
echo "| Severity | Rule | Location | Snippet |"
echo "|---|---|---|---|"

BLOCK_COUNT=0
ADVISORY_COUNT=0

# Helper: filter out grep hits that are inside PHP comments or string literals
# (lines starting with //, *, #, or where <style>/<script> appears inside quoted text).
# F2 was over-reporting because it matched comments + XSS-detection code that
# literally talks about <script> as data, not emits it.
real_inline_tag() {
    # stdin: grep -nE output. stdout: same, minus comment/string-literal lines.
    # Filters: leading // * # comments; quoted string literals containing <style/<script;
    # XSS-detection / sanitization code that mentions <script> as data.
    grep -vE '^[^:]+:[0-9]+:\s*(//|\*|#)' \
        | grep -vE "^[^:]+:[0-9]+:.*['\"][^'\"]*<(style|script)[^'\"]*['\"]" \
        | grep -vE '^[^:]+:[0-9]+:.*(strip|reject|filter|escape|kses|sanitize|XSS|xss).*<(style|script)' \
        || true
}

# ── F1: inline <style> in PHP (excluding email templates) ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F1 inline-style" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -not -path '*/email/*' -print0 2>/dev/null \
         | xargs -0 grep -nE '<style[ >]' 2>/dev/null \
         | real_inline_tag || true)

# ── F2: inline <script> in PHP (excludes email/OAuth + comments + string literals) ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F2 inline-script" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -not -path '*/email/*' -not -path '*/oauth/*' -print0 2>/dev/null \
         | xargs -0 grep -nE '<script[ >]' 2>/dev/null \
         | grep -vE 'application/(ld\+json|json)' \
         | real_inline_tag || true)

# ── F2b: inline onclick attribute ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F2 inline-onclick" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" \( -name '*.php' -o -name '*.html' \) "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'onclick=' 2>/dev/null || true)

# ── F8: native alert/confirm/prompt in JS (excludes .min.js + eslint-disable lines) ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F8 native-alert-confirm" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.js' -not -name '*.min.js' "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'window\.(alert|confirm|prompt)\s*\(|^\s*(alert|confirm|prompt)\s*\(' 2>/dev/null \
         | grep -v 'eslint-disable' || true)

# ── F10: display:none on theme sidebar/widgets ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F10 hidden-theme-html" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
         | xargs -0 grep -nE '(\.sidebar|#secondary|\.widget-area|\.site-sidebar)[^{]*\{[^}]*display:\s*none' 2>/dev/null || true)

# ── Advisory: raw -1 in number inputs ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "advisory" "Rule 7 raw-minus-one" "$file" "$line" "\`$snippet\`"
    ADVISORY_COUNT=$((ADVISORY_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'type=["'"'"']number["'"'"'][^>]*value=["'"'"']-1' 2>/dev/null || true)

# ── Advisory: Dashicons in new code ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "advisory" "Rule 5 use-lucide" "$file" "$line" "\`$snippet\`"
    ADVISORY_COUNT=$((ADVISORY_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'dashicons-[a-z-]+' 2>/dev/null \
         | head -10 || true)

# ── Advisory: outline:none without focus-visible nearby ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    ctx=$(sed -n "${line},$((line+5))p" "$file" 2>/dev/null | grep -c 'focus-visible' || true)
    if [ "${ctx:-0}" = "0" ]; then
        snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
        violation "advisory" "Rule 14 outline-none-no-replacement" "$file" "$line" "\`$snippet\`"
        ADVISORY_COUNT=$((ADVISORY_COUNT+1))
    fi
done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
         | xargs -0 grep -nE 'outline:\s*(none|0)' 2>/dev/null || true)

# ── Advisory: > 3 distinct breakpoints ──
BREAKPOINTS=$(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
              | xargs -0 grep -hE '@media[^{]*\(\s*(min|max)-width:\s*[0-9]+px' 2>/dev/null \
              | grep -oE '[0-9]+px' | sort -u | wc -l | tr -d ' ')
if [ "${BREAKPOINTS:-0}" -gt 3 ]; then
    violation "advisory" "Rule responsive #1 breakpoints>3" "(global)" "0" "\`$BREAKPOINTS distinct breakpoint values — should be ≤3\`"
    ADVISORY_COUNT=$((ADVISORY_COUNT+1))
fi

# ── Advisory: per-component dark-mode rules that style COMPONENTS with raw colors ──
# Three patterns to distinguish:
#   1. Token-define block:        --jt-bg: #1e1e1e;          ← CORRECT (token override)
#   2. Token-aware override:      color: var(--jt-text);     ← CORRECT (defensive re-apply)
#   3. Component drift:           background: #1e1e1e;       ← THIS is the bug we flag
# Only #3 is drift. We detect it by looking for a property declaration (line that
# does NOT start with `--`) with a raw color value, inside a .{prefix}-dark rule body.
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    # Check rule body (next 8 lines). Look for a NON-token property with raw color.
    body="$(sed -n "${line},$((line+8))p" "$file" 2>/dev/null)"
    if echo "$body" | grep -qE '^[[:space:]]+[a-z][a-z-]*:[[:space:]]*(#[0-9a-fA-F]{3,6}|rgba?\(|hsla?\()'; then
        snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
        violation "advisory" "Rule dark-mode-raw-color" "$file" "$line" "\`$snippet\`"
        ADVISORY_COUNT=$((ADVISORY_COUNT+1))
    fi
done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
         | xargs -0 grep -nE '\.(jt|jet|wbg|listora|lrn|mvs|bn|bx|bp)-dark[[:space:]]+\.[a-z]+-[a-z]' 2>/dev/null \
         | head -20 || true)

# ── Advisory: raw margin-left/right (RTL-fragile) ──
RTL_RAW=$(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -not -name '*-rtl.css' -print0 2>/dev/null \
          | xargs -0 grep -cE '(margin|padding)-(left|right):' 2>/dev/null \
          | grep -v ':0$' | wc -l | tr -d ' ')
if [ "${RTL_RAW:-0}" -gt 0 ]; then
    # Sample first 5
    while IFS=: read -r file line _; do
        [ -z "$file" ] && continue
        snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
        violation "advisory" "RTL raw-margin-left-right" "$file" "$line" "\`$snippet\`"
        ADVISORY_COUNT=$((ADVISORY_COUNT+1))
    done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -not -name '*-rtl.css' -print0 2>/dev/null \
             | xargs -0 grep -nE '(margin|padding)-(left|right):' 2>/dev/null \
             | grep -v ':[[:space:]]*0;' \
             | head -10 || true)
fi

echo
echo "---"
echo "**Block-severity violations: $BLOCK_COUNT** | **Advisory: $ADVISORY_COUNT**"
echo
if [ "$BLOCK_COUNT" -eq 0 ]; then
    echo "✅ No block-severity violations. Advisory issues should be cleaned in next PR."
    exit 0
else
    echo "❌ Fix every \`block\` row before merging. \`advisory\` rows can ship with next PR."
    exit 1
fi

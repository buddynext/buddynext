#!/usr/bin/env bash
# Negative controls for bin/check-unwrapped-strings.php and bin/check-i18n-readiness.php: each bad
# fixture must fail and name every class it contains, each good fixture must pass.
# Run: bash bin/test-i18n-gates.sh
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; FX="$HERE/../tests/fixtures"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail() { echo "FAIL: $1"; exit 1; }

php "$HERE/check-unwrapped-strings.php" "$FX/i18n-unwrapped/good" --all >/dev/null 2>&1 || fail "unwrapped scan rejected the wrapped fixture"
out="$(php "$HERE/check-unwrapped-strings.php" "$FX/i18n-unwrapped/bad" --all 2>&1)" && fail "unwrapped scan passed the unwrapped fixture"
for needle in "Your account is ready" "Connected people" "You cannot do that" "{{actor_name}} has asked to follow" "Welcome to the community" 'placeholder="Search for people"' 'data-label-on' "Something went wrong" "Saved your changes" "Close this dialog" "never injected"; do
	grep -qF -- "$needle" <<<"$out" || fail "unwrapped scan missed: $needle"
done

php "$HERE/check-i18n-readiness.php" "$FX/i18n-readiness/good" --all >/dev/null 2>&1 || fail "readiness gate rejected the good fixture"
out="$(php "$HERE/check-i18n-readiness.php" "$FX/i18n-readiness/bad" --all 2>&1)" && fail "readiness gate passed the bad fixture"
for c in domain dynamic fragments ordered translators plural number; do
	grep -q ":$c:" <<<"$out" || fail "readiness gate missed class: $c"
done
# Loading: a plugin without Domain Path and without load_plugin_textdomain() must fail.
cp -R "$FX/i18n-readiness/good" "$T/p"
grep -v "Domain Path\|load_plugin_textdomain" "$FX/i18n-readiness/good/buddynext.php" > "$T/p/buddynext.php"
out="$(php "$HERE/check-i18n-readiness.php" "$T/p" --all 2>&1)" && fail "readiness gate passed a plugin that never loads its textdomain"
grep -q ":loading:" <<<"$out" || fail "readiness gate missed class: loading"
echo "ok: unwrapped (good passes, bad flagged), readiness (good passes; domain, dynamic, fragments, ordered, translators, plural, number, loading flagged)"

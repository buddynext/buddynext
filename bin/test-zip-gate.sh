#!/usr/bin/env bash
# Negative control for bin/check-zip-deflated.py: a zip with a stored index.php must fail the gate,
# the same files deflated must pass. Run: bash bin/test-zip-gate.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/p"; printf '<?php\n// Silence is golden.\n' > "$T/p/index.php"
( cd "$T" && zip -0 -rqX stored.zip p )
( cd "$T" && python3 -I -c 'import zipfile
with zipfile.ZipFile("deflated.zip","w",zipfile.ZIP_DEFLATED) as z: z.write("p/index.php")' )
if python3 -I "$HERE/check-zip-deflated.py" "$T/stored.zip" 2>/dev/null; then echo "FAIL: gate passed a stored index.php"; exit 1; fi
python3 -I "$HERE/check-zip-deflated.py" "$T/deflated.zip" || { echo "FAIL: gate rejected a deflated zip"; exit 1; }
echo "ok: stored zip rejected, deflated zip accepted"

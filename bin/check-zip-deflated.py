#!/usr/bin/env python3
"""Fail if any .php member of a release zip is stored uncompressed.

The zip CLI stores tiny files (index.php "Silence is golden") as-is, so plain PHP is readable
in the upload body and the store's upload firewall blocks the zip. Usage: check-zip-deflated.py <zip>
"""
import sys
import zipfile

with zipfile.ZipFile(sys.argv[1]) as z:
    bad = [i.filename for i in z.infolist() if i.filename.endswith(".php") and i.compress_type == zipfile.ZIP_STORED]
for name in bad:
    print("stored (not deflated): " + name, file=sys.stderr)
sys.exit(1 if bad else 0)

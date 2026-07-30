# Vendored third-party JavaScript

Runtime third-party code, committed and shipped in the plugin zip - the same
policy `libs/` follows for PHP. Nothing here is fetched from a CDN at runtime:
a member enrolling in two-factor must not depend on a third-party host being
reachable, and the plugin must work on a site with no outbound access.

| File | Source | Version | License |
|---|---|---|---|
| `qrcode.js` | [qrcode-generator](https://www.npmjs.com/package/qrcode-generator) by Kazuhiko Arase | 2.0.4 | MIT |

`qrcode.js` is the package's unmodified ESM build (`dist/qrcode.mjs`), renamed
so WordPress serves it with the `.js` extension. Do not edit it - to update,
re-copy that file from a newer release and re-run the enrolment verification in
`docs/qa` (render the QR, decode it back with the browser's BarcodeDetector, and
confirm it returns the exact otpauth:// URI).

# Vendored third-party JavaScript

Runtime third-party code, committed and shipped in the plugin zip - the same
policy `libs/` follows for PHP. Nothing here is fetched from a CDN at runtime:
a member enrolling in two-factor must not depend on a third-party host being
reachable, and the plugin must work on a site with no outbound access.

| File | Source | Version | License |
|---|---|---|---|
| `qrcode.js` | [qrcode-generator](https://www.npmjs.com/package/qrcode-generator) by Kazuhiko Arase | 2.0.4 | MIT |
| `pdfjs/pdf.min.mjs` + `pdfjs/pdf.worker.min.mjs` | [pdfjs-dist](https://mozilla.github.io/pdf.js/) by Mozilla | 6.2.108 | Apache-2.0 |

`pdfjs/` is the unmodified `pdfjs-dist` ESM build (library + worker), used by the
space Files single-document reader (`assets/js/space-files/store.js`) to render
PDF documents to canvas — a clean single-column read, the same everywhere and on
mobile, instead of the browser's embedded PDF chrome. Loaded by dynamic `import()`
only when a PDF is actually opened, so no other page pays its weight. It falls back
to the browser's own `<iframe>` viewer if the library fails to load or a document
fails to open, so the read never breaks outright. Do not edit these files — to
update, re-copy both from a newer release, keep `VERSION.txt`/`LICENSE.txt` in sync,
and re-run the Files preview verification (open a PDF, an office doc, and a text
doc; confirm each renders and a broken PDF falls back to the iframe).

`qrcode.js` is the package's unmodified ESM build (`dist/qrcode.mjs`), renamed
so WordPress serves it with the `.js` extension. Do not edit it - to update,
re-copy that file from a newer release and re-run the enrolment verification in
`docs/qa` (render the QR, decode it back with the browser's BarcodeDetector, and
confirm it returns the exact otpauth:// URI).

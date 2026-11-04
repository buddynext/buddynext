# Package Signatures

Checks the signature of plugin and theme updates from your own store before WordPress installs them. Built for
stores that sell WordPress products with Easy Digital Downloads Software Licensing, but it works with any updater:
it hooks WordPress core's `upgrader_pre_download`, which runs for dashboard updates, auto-updates and WP-CLI alike.

WordPress core does not check signatures on plugin updates (it calls `download_package( $package, false )`), so a
tampered zip on a store would be installed on every site that updates. With this library, each release is signed
at release time with an Ed25519 key that never leaves the release step, and sites refuse a package whose signature,
product, version or file hash does not match.

## Use it in a plugin or theme

Bundle this folder (for example `libs/package-signatures/`), then:

```php
require_once __DIR__ . '/libs/package-signatures/package-signatures.php';

package_signatures_register(
	array(
		'product'  => 'my-plugin',                          // as written in the signed manifest
		'plugin'   => 'my-plugin/my-plugin.php',            // or 'theme' => 'my-theme'
		'hosts'    => array( 'store.example.com' ),         // where update packages come from
		'keys'     => array( 'store-2026-1' => 'BASE64 PUBLIC KEY' ),
		'mode'     => 'warn',                               // 'enforce' once every release is signed
		'fallback' => 'https://store.example.com/package-signatures/{product}/{version}',
	)
);
```

Products that are not registered, and packages from other hosts, are never touched. When several products bundle
the library, the newest copy runs and only that copy loads its classes.

- `warn`: the result is logged (`package_signatures_log` option, action `package_signatures_checked`) and the
  update installs.
- `enforce`: a missing or invalid signature stops the update; the site stays on its current version.

## Signing

```bash
php bin/keygen.php ~/secure/store-2026-1.key      # prints the public key; secret key written mode 600
PACKAGE_SIGNATURES_SECRET_KEY="$(cat ~/secure/store-2026-1.key)" \
  php bin/sign.php --product=my-plugin --version=1.2.3 --key-id=store-2026-1 --file=my-plugin-1.2.3.zip > bundle.json
```

Serve the bundle base64-encoded in an `X-Package-Signature` header on the package download, or at the fallback URL.

### Bundle format

```json
{ "manifest": "<base64 of the manifest bytes>", "signature": "<base64 Ed25519 detached signature of those bytes>" }
```

Manifest (version 1): `v`, `product`, `version`, `file`, `size`, `sha256`, `key_id`, `signed_at`. The site checks
the signature against its embedded keys, that `product` is the product being updated, that `version` is the version
WordPress was offered and newer than the installed one, and that `size` and `sha256` match the downloaded zip.

## Tests

```bash
php tests/verifier-test.php
```

## Licence

GPL-2.0-or-later.

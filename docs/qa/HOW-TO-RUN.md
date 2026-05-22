# How to run the BuddyNext Playwright suite

## Prerequisites

- Local by Flywheel running and `forums.local` resolves on this machine.
- BuddyNext Free (and optionally Pro) plugins active on `forums.local`.
- Node 20+ on `$PATH`.
- A test user with login `varundubey` and password `password` (or override via env vars below).

## One-time setup

```bash
npm ci
npm run test:e2e:install
```

`test:e2e:install` downloads Playwright's Chromium + WebKit binaries. WebKit is what powers the iPad and iPhone device profiles.

## Run the suite

```bash
# Full suite, all three viewports
npm run test:e2e

# Just one project
npm run test:e2e:desktop
npm run test:e2e:ipad
npm run test:e2e:mobile

# Point at a different local site
BN_BASE_URL=http://other.local npm run test:e2e

# Unmask Pro-only journeys (otherwise marked test.fixme())
BN_PRO=1 npm run test:e2e

# Override the test user
BN_TEST_USER=admin BN_TEST_PASS=admin npm run test:e2e
```

## Reading the report

After any run the HTML report lives under `tests/e2e/_report/`:

```bash
npm run test:e2e:report
```

The CLI also prints the list reporter inline as tests progress.

## Environment variables

| Variable | Purpose | Default |
|---|---|---|
| `BN_BASE_URL` | WP site to test | `http://forums.local` |
| `BN_PRO` | Set to `1` to run Pro-only journeys | unset |
| `BN_TEST_USER` | WP user login | `varundubey` |
| `BN_TEST_PASS` | WP user password | `password` |
| `BN_TEST_USER_2FA` | Login for the 2FA journey | `varundubey_2fa` |
| `BN_TEST_PASS_2FA` | Password for the 2FA user | `password` |
| `BN_TEST_SPACE` | Slug of an open space the test user is a member of | `general` |
| `BN_TEST_OWNED_SPACE` | Slug of a space the test user owns | `general` |
| `BN_WPMEDIAVERSE` | Set to `1` when the DM bridge is active to unmask DM-thread journeys | unset |
| `BN_WP_PATH` | Used by `db.fixture.ts` for WP-CLI seeding | `/Users/varundubey/Local Sites/forums/app/public` |

## Triage tips

- A failure with `wordpress_logged_in_*` mentioned in the assertion message means the auth fixture couldn't sign in  -  check `BN_TEST_USER` / `BN_TEST_PASS`.
- A failure right after `await page.goto(urls.X)` typically means `forums.local` is down or the route slug is misconfigured in PageRouter.
- `test.fixme()` results show as `expected to fail` in the report  -  they do NOT count as failures.

## What's gated behind this suite

User has gated all tag operations on this suite passing green. No release ships without it.

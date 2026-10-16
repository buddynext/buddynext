/**
 * WP-CLI helpers for effect-based e2e specs.
 *
 * These shell out to `wp` against the local site so a spec can assert the REAL
 * server consequence of an action (a relationship row created / removed, a meta
 * key set / cleared) rather than an optimistic button flip. Reading the DB is a
 * stronger truth-check than a REST GET: it cannot be satisfied by a silent REST
 * failure that the UI optimistically papered over — the exact false-positive
 * the MEMBER-ACTION-COVERAGE-MATRIX flags across the Follow/Connect suite.
 *
 * Used ONLY for setup / teardown / verification. The action under test is always
 * driven through the real browser UI (never seeded via SQL), so the write path
 * is exercised end-to-end. SQL here only creates deterministic preconditions and
 * tears down leftover rows so every rerun is idempotent.
 *
 * WP root defaults to the buddynext-dev.local site; override with BN_WP_PATH.
 */
import type { Page } from '@playwright/test';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { writeFileSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const execFileAsync = promisify(execFile);

// No default. A wrong path here does not fail loudly - wp writes its complaint
// to a stream cleanStdout() strips, so the shim hands back an empty string, an
// empty string parses as user id 0, and the run reports `actor "…" must exist`
// in whichever specs happened to need an actor. A hard-coded personal path that
// exists on one machine is therefore worse than none: everywhere else it turns
// a setup problem into a scatter of unrelated product-looking failures.
//
// bin/check-journey-run.sh resolves BN_WP_PATH and refuses to start the suite
// without it, so by the time a spec runs this is set.
const WP_PATH = process.env.BN_WP_PATH ?? '';

if (WP_PATH === '') {
    throw new Error(
        'BN_WP_PATH is not set. The journey specs shell out to wp-cli for actors, ' +
            'fixtures and queue draining; without a WordPress root those calls return ' +
            'nothing and surface as unrelated failures. Run via bin/check-journey-run.sh, ' +
            'which resolves it, or export BN_WP_PATH yourself.'
    );
}

/**
 * Strip PHP startup warnings that Local's mismatched CLI PHP prints. Under a
 * non-TTY (Node) some of these land on STDOUT ahead of the real value, so a bare
 * parseInt sees "Warning: ...\n1" and yields NaN. Drop any warning/notice lines
 * and keep the actual output.
 */
function cleanStdout(raw: string): string {
    return raw
        .split(/\r?\n/)
        .filter(
            (line) =>
                !/^(PHP\s+)?(Warning|Notice|Deprecated|Strict Standards|Fatal error|Parse error|Xdebug)\b/i.test(
                    line.trim()
                )
        )
        .join('\n')
        .trim();
}

/**
 * Run a wp-cli command and return cleaned, trimmed stdout.
 *
 * error_reporting/display_startup_errors are forced off for the CLI PHP so the
 * extension-load warnings never print; cleanStdout() is a belt-and-suspenders
 * strip for any that still leak through on this box.
 */
export async function wp(args: string[]): Promise<string> {
    const { stdout } = await execFileAsync('wp', [`--path=${WP_PATH}`, ...args], {
        maxBuffer: 1024 * 1024 * 8,
        env: {
            ...process.env,
            WP_CLI_PHP_ARGS:
                '-d error_reporting=0 -d display_errors=0 -d display_startup_errors=0',
        },
    });
    return cleanStdout(stdout);
}

let _prefix: string | null = null;
export async function tablePrefix(): Promise<string> {
    if (_prefix === null) {
        _prefix = (await wp(['config', 'get', 'table_prefix'])).trim() || 'wp_';
    }
    return _prefix;
}

/** SQL scalar (single value) via --skip-column-names. */
export async function dbScalar(sql: string): Promise<string> {
    return (await wp(['db', 'query', sql, '--skip-column-names'])).trim();
}

export async function dbCount(sql: string): Promise<number> {
    const out = await dbScalar(sql);
    const n = parseInt(out.replace(/[^\d-]/g, ''), 10);
    return Number.isFinite(n) ? n : 0;
}

/** Resolve a user id by login. Returns 0 when the user does not exist. */
export async function userId(login: string): Promise<number> {
    try {
        const out = await wp(['user', 'get', login, '--field=ID']);
        const n = parseInt(out.trim(), 10);
        return Number.isFinite(n) ? n : 0;
    } catch {
        return 0;
    }
}

/** Ensure a member exists (idempotent). Returns the user id. */
export async function ensureUser(
    login: string,
    email: string,
    displayName: string
): Promise<number> {
    let id = await userId(login);
    if (id <= 0) {
        const out = await wp([
            'user',
            'create',
            login,
            email,
            `--display_name=${displayName}`,
            '--role=subscriber',
            '--user_pass=password',
            '--porcelain',
        ]);
        id = parseInt(out.trim(), 10);
    }
    // A journey member stands in for a real, settled member, who has confirmed
    // their email. On a site that requires verification an unverified fixture is
    // refused every write (403 email_unverified) and the journey fails for the
    // wrong reason. Journeys that need an unverified member use seedVerifyToken().
    await wp([
        'eval',
        `if ( ! get_user_meta( ${id}, 'buddynext_email_verified', true ) ) { buddynext_service( 'verification' )->mark_verified( ${id} ); }`,
    ]);
    return id;
}

export async function getUserMeta(id: number, key: string): Promise<string> {
    try {
        return (await wp(['user', 'meta', 'get', String(id), key])).trim();
    } catch {
        return '';
    }
}

export async function setUserMeta(id: number, key: string, value: string): Promise<void> {
    await wp(['user', 'meta', 'update', String(id), key, value]);
}

export async function deleteUserMeta(id: number, key: string): Promise<void> {
    // Never throw on an already-absent key — teardown must stay idempotent.
    try {
        await wp(['user', 'meta', 'delete', String(id), key]);
    } catch {
        /* meta already gone */
    }
}

/** wp_users.display_name (the canonical member name). */
export async function displayName(id: number): Promise<string> {
    return (await wp(['user', 'get', String(id), '--field=display_name'])).trim();
}

/**
 * Delete every social-graph row between two members, both directions, plus any
 * user-report either filed against the other. Makes a spec rerun from a clean
 * slate no matter how the previous run exited.
 */
export async function resetPair(aId: number, bId: number): Promise<void> {
    const p = await tablePrefix();
    const stmts = [
        `DELETE FROM ${p}bn_follows WHERE (follower_id=${aId} AND following_id=${bId}) OR (follower_id=${bId} AND following_id=${aId});`,
        `DELETE FROM ${p}bn_connections WHERE (requester_id=${aId} AND recipient_id=${bId}) OR (requester_id=${bId} AND recipient_id=${aId});`,
        `DELETE FROM ${p}bn_blocks WHERE (blocker_id=${aId} AND blocked_id=${bId}) OR (blocker_id=${bId} AND blocked_id=${aId});`,
        `DELETE FROM ${p}bn_reports WHERE object_type='user' AND ((reporter_id=${aId} AND object_id=${bId}) OR (reporter_id=${bId} AND object_id=${aId}));`,
    ];
    await wp(['db', 'query', stmts.join(' ')]);
}

/**
 * Create a published page whose content is raw block markup / shortcodes, and
 * return its id + front-end URL. Block-embed specs need a real page to render a
 * block on an ORDINARY page (not its routed hub) — the exact context where the
 * block-interactivity and shortcode defects only appeared.
 *
 * Idempotent by slug: any prior page at that slug is deleted first so a rerun
 * starts clean. Content goes through a temp file, not a CLI arg, because block
 * comment markup (`<!-- wp:... -->`) does not survive shell quoting.
 */
export async function createPage(
    content: string,
    opts: { title: string; slug: string }
): Promise<{ id: number; url: string }> {
    const prior = await wp([
        'post', 'list', '--post_type=page', `--name=${opts.slug}`,
        '--post_status=any', '--field=ID',
    ]).catch(() => '');
    for (const id of prior.split(/\s+/).filter(Boolean)) {
        await wp(['post', 'delete', id, '--force']).catch(() => undefined);
    }

    const tmp = join(tmpdir(), `bn-e2e-page-${Date.now()}-${process.pid}.html`);
    writeFileSync(tmp, content, 'utf8');
    try {
        const id = parseInt(
            (
                await wp([
                    'post', 'create', tmp,
                    '--post_type=page', '--post_status=publish',
                    `--post_title=${opts.title}`, `--post_name=${opts.slug}`,
                    '--porcelain',
                ])
            ).trim(),
            10
        );
        const url = (await wp(['post', 'get', String(id), '--field=url'])).trim();
        return { id, url };
    } finally {
        try {
            unlinkSync(tmp);
        } catch {
            /* temp file already gone */
        }
    }
}

/** Delete a page created by createPage(). Never throws — teardown stays idempotent. */
export async function deletePage(id: number): Promise<void> {
    if (id > 0) {
        await wp(['post', 'delete', String(id), '--force']).catch(() => undefined);
    }
}

/**
 * Keep fixed-bottom overlays off the save bars and composers.
 *
 * Two things sit over the bottom of every page on a fresh profile: BuddyNext's own
 * cookie notice (dismissed by the `bn_cookie_consent` cookie, which J-810 covers on
 * its own) and whatever consent plugin the local site runs (WPConsent on the model
 * site: `#wpconsent-container` intercepted every click on the sticky save bar in
 * the 2026-10-12 gate, 15 specs). Third-party banners are hidden by CSS because they
 * are not the surface under test; ours is accepted through its real cookie.
 */
let _consentVersion: string | null = null;
async function consentVersion(): Promise<string> {
    if (_consentVersion === null) {
        // The accepted value is the notice's policy version (a hash of the policy text).
        _consentVersion = (
            await wp([
                'eval',
                '$s = new \\BuddyNext\\Privacy\\CookieConsentService(); $m = new ReflectionMethod( $s, "version" ); $m->setAccessible( true ); echo $m->invoke( $s );',
            ]).catch(() => '1')
        ).trim() || '1';
    }
    return _consentVersion;
}

export async function settleOverlays(page: Page): Promise<void> {
    const origin = new URL(page.url() || process.env.BN_BASE_URL || 'http://localhost').origin;
    await page.context().addCookies([
        { name: 'bn_cookie_consent', value: await consentVersion(), url: origin },
    ]);
    await page.addInitScript(() => {
        const hide = () => {
            if (document.getElementById('bn-e2e-overlay-css')) {
                return;
            }
            const style = document.createElement('style');
            style.id = 'bn-e2e-overlay-css';
            style.textContent = '#wpconsent-container, .wpconsent-banner, .cmplz-cookiebanner { display: none !important; }';
            (document.head || document.documentElement).appendChild(style);
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', hide, { once: true });
        } else {
            hide();
        }
    });
}

/**
 * Run the Action Scheduler jobs the product queued for a hook, synchronously.
 * Hashtag extraction, search indexing, notification fan-out and webhook delivery are
 * deferred to Action Scheduler; on a local site the async runner is slow or absent,
 * so a spec that asserts their effect drains the queue itself instead of sleeping.
 */
export async function drainQueue(hooks: string[], batches = 3): Promise<void> {
    // Two specs draining at once race for the same claims; a pass that finds nothing
    // because a sibling holds the claim must not end the drain. Three passes, a beat apart.
    for (let pass = 0; pass < 3; pass++) {
        await wp(['action-scheduler', 'run', `--hooks=${hooks.join(',')}`, `--batches=${batches}`, '--batch-size=50']).catch(() => undefined);
        const pending = await wp([
            'eval',
            `global $wpdb; $h = array_map( 'esc_sql', explode( ',', '${hooks.join(',')}' ) ); echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN ('pending','in-progress') AND hook IN ('" . implode( "','", $h ) . "')" );`,
        ]).catch(() => '0');
        if (parseInt(pending.trim(), 10) === 0) {
            return;
        }
        await new Promise((r) => setTimeout(r, 1000));
    }
}

/**
 * End every active site announcement so a spec that creates one is the only one.
 * Home shows a single active announcement; the model site keeps one from its seed.
 */
export async function endActiveAnnouncements(): Promise<void> {
    await wp([
        'eval',
        'foreach ( (array) buddynext_service( "feed" )->list_all_announcements( 100 ) as $a ) { buddynext_service( "feed" )->end_announcement_now( (int) ( (array) $a )["id"] ); } echo "ok";',
    ]);
}

/**
 * Give a member a value for every REQUIRED profile field so a full profile save can
 * succeed. The model site marks Gender and Interested In required (dating fixture);
 * since 1.2.5 a full-form save is validated before anything is written, so a member
 * who never answered them cannot save a bio, a headline or a repeater at all. That is
 * the product's rule for members; a journey about SOMETHING ELSE must start from a
 * member who satisfies it. Values are the first option of each select, written through
 * ProfileService::save_profile() (a partial write, enforced present-only).
 */
export async function satisfyRequiredFields(login: string | number): Promise<void> {
    await wp([
        'eval',
        `$id = '${String(login).replace(/'/g, '')}'; $u = ctype_digit( $id ) ? get_userdata( (int) $id ) : get_user_by( 'login', $id ); if ( ! $u ) { echo 'no-user'; exit; }` +
            ` $svc = buddynext_service( 'profiles' ); $data = array();` +
            ` foreach ( (array) $svc->get_flat_fields() as $f ) { if ( empty( $f['is_required'] ) ) { continue; }` +
            `   $key = (string) $f['field_key']; global $wpdb; $cur = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d LIMIT 1", $u->ID, (int) $f['id'] ) ); if ( '' !== (string) $cur && null !== $cur && '[]' !== $cur ) { continue; }` +
            `   $opts = is_string( $f['options'] ?? '' ) ? json_decode( (string) $f['options'], true ) : (array) ( $f['options'] ?? array() );` +
            `   $first = ''; foreach ( (array) $opts as $k => $o ) { $first = is_array( $o ) ? (string) ( $o['value'] ?? $o['key'] ?? $k ) : ( is_string( $k ) ? $k : (string) $o ); if ( '' !== $first ) { break; } }` +
            `   $type = (string) ( $f['type'] ?? 'text' );` +
            `   $data[ $key ] = str_contains( $type, 'multi' ) ? array( $first ) : ( in_array( $type, array( 'select', 'radio' ), true ) ? $first : 'e2e' ); }` +
            ` if ( $data ) { $r = $svc->save_profile( $u->ID, $data ); echo is_wp_error( $r ) ? 'err:' . $r->get_error_message() : 'ok:' . implode( ',', array_keys( $data ) ); } else { echo 'none'; }`,
    ]).catch(() => undefined);
}

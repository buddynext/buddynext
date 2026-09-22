/**
 * DB seeding helpers via WP-CLI.
 *
 * These are placeholder shapes for the seeding API. They will shell out to
 * `wp --path=<wp_root>` once we wire buddynext-dev.local-aware paths. For now the
 * functions exist so specs can import them and the type system stays happy.
 *
 * The WP root for buddynext-dev.local is:
 *   /Users/varundubey/Local Sites/forums/app/public
 * (per CLAUDE.md). Override with BN_WP_PATH env var.
 *
 * Once implemented, these should use `execFile('wp', ...)` not `exec`, and
 * always pass `--allow-root`/`--skip-themes`/`--skip-plugins=...` where
 * appropriate so seeding never triggers our own listeners.
 */
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

const WP_PATH = process.env.BN_WP_PATH ?? '/Users/varundubey/Local Sites/forums/app/public';

async function wp(args: string[]): Promise<string> {
    const { stdout } = await execFileAsync('wp', [`--path=${WP_PATH}`, ...args]);
    return stdout.trim();
}

/**
 * Reset the test user's onboarding state so the wizard is exercisable again.
 */
export async function resetOnboarding(userLogin: string): Promise<void> {
    if (!process.env.BN_WP_PATH && !process.env.BN_DB_HELPERS) {
        // No-op when seeding is opted-out; specs degrade to whatever the DB
        // currently has.
        return;
    }
    await wp(['user', 'meta', 'delete', userLogin, 'bn_onboarded']);
}

/**
 * Make sure a hashtag with the given slug exists so the hashtag-feed spec
 * has a non-empty target. Returns the slug.
 */
export async function ensureHashtag(slug: string): Promise<string> {
    if (!process.env.BN_WP_PATH && !process.env.BN_DB_HELPERS) {
        return slug;
    }
    // The hashtag is created lazily on first post; we just sanitize input.
    return slug.replace(/[^a-z0-9-]/gi, '').toLowerCase();
}

/**
 * Make sure a space with the given slug exists. Returns the slug.
 */
export async function ensureSpace(slug: string): Promise<string> {
    if (!process.env.BN_WP_PATH && !process.env.BN_DB_HELPERS) {
        return slug;
    }
    // Seeded by the BN sample-data fixture in real WP-CLI flow.
    return slug;
}

/**
 * Whether DB seeding through WP-CLI is available (BN_WP_PATH points at a real WP).
 * A spec that needs a seeded fixture should `test.skip(!dbSeedingAvailable(), ...)`
 * so it runs in CI (where BN_WP_PATH is set) and is an honest environment skip
 * locally - not a silent test.fixme.
 */
export function dbSeedingAvailable(): boolean {
    return Boolean(process.env.BN_WP_PATH || process.env.BN_DB_HELPERS);
}

/**
 * Seed a real email-verification token for a member and return it.
 *
 * Creates the member if absent, clears any prior verified/pending state and any
 * old tokens, then issues a fresh token through Auth\VerificationService - the
 * SAME service the plugin uses on a real signup - so the token is genuine, never
 * a hand-built string that would not survive a hashing/format change. Returns the
 * 64-char hex token to drop into `?bn_verify=`.
 */
/** Known password set on a seeded verify member, so a spec can also prove login. */
export const VERIFY_PASSWORD = 'bn-e2e-verify-pass-9271';

/** Known credentials for a seeded, already-verified member used by the real-login spec. */
export const LOGIN_MEMBER = 'bn_e2e_login';
export const LOGIN_PASSWORD = 'bn-e2e-login-pass-5501';

/**
 * Seed a real, email-verified member with known credentials and return its login.
 *
 * The login spec exercises the REAL wp-login flow, so it needs a user that
 * actually exists with a password the test knows. Hardcoding a canonical name
 * (varundubey) works only on sites where that account happens to exist; this
 * creates/repairs a dedicated one so the spec is deterministic on any site with
 * WP-CLI seeding. Marks the member verified so a verification-enforcing site
 * does not block the login.
 */
export async function seedLoginUser(login: string = LOGIN_MEMBER): Promise<string> {
    const php = [
        `$login = ${JSON.stringify(login)};`,
        `$u = get_user_by('login', $login);`,
        `$uid = $u ? (int) $u->ID : (int) wp_create_user($login, ${JSON.stringify(LOGIN_PASSWORD)}, $login . '@bn-e2e.test');`,
        `wp_set_password(${JSON.stringify(LOGIN_PASSWORD)}, $uid);`,
        `update_user_meta($uid, 'buddynext_email_verified', '1');`,
        `delete_user_meta($uid, 'buddynext_verify_pending');`,
        `echo $uid;`,
    ].join(' ');
    await wp(['eval', php]);
    return login;
}

export async function seedVerifyToken(userLogin: string): Promise<string> {
    const php = [
        `$login = ${JSON.stringify(userLogin)};`,
        `$u = get_user_by('login', $login);`,
        `$uid = $u ? (int) $u->ID : (int) wp_create_user($login, ${JSON.stringify(VERIFY_PASSWORD)}, $login . '@bn-e2e.test');`,
        `wp_set_password(${JSON.stringify(VERIFY_PASSWORD)}, $uid);`,
        `delete_user_meta($uid, 'buddynext_email_verified');`,
        `delete_user_meta($uid, 'buddynext_verify_pending');`,
        `global $wpdb; $wpdb->delete($wpdb->prefix . 'bn_verify_tokens', array('user_id' => $uid));`,
        `echo (new \\BuddyNext\\Auth\\VerificationService())->create_token($uid);`,
    ].join(' ');

    // Extract the 64-char hex token from the output. Some PHP setups print a
    // startup warning (a missing extension) to stdout before the script output, so
    // match the token rather than assuming it is the whole string.
    const out = await wp(['eval', php]);
    const match = out.match(/[0-9a-f]{64}/);
    if (!match) {
        throw new Error(`seedVerifyToken: no 64-char hex token in output: ${out.slice(0, 200)}`);
    }
    return match[0];
}

/** Known credentials for a seeded, 2FA-enabled member used by the TOTP-challenge spec. */
export const TWO_FACTOR_MEMBER = 'bn_e2e_2fa';
export const TWO_FACTOR_PASSWORD = 'bn-e2e-2fa-pass-3387';

/**
 * Seed a real member with two-factor authentication turned on and return its
 * login. Writes the same user meta TwoFactorService::confirm_enrollment() would
 * (bn_2fa_enabled + bn_2fa_secret) rather than driving the real TOTP enrolment
 * REST flow — this spec only needs is_enabled() to be true so
 * TwoFactorLoginGuard::interpose_challenge() fires; it never needs a working
 * secret to compute a real code.
 */
export async function seedTwoFactorUser(login: string = TWO_FACTOR_MEMBER): Promise<string> {
    const php = [
        `$login = ${JSON.stringify(login)};`,
        `$u = get_user_by('login', $login);`,
        `$uid = $u ? (int) $u->ID : (int) wp_create_user($login, ${JSON.stringify(TWO_FACTOR_PASSWORD)}, $login . '@bn-e2e.test');`,
        `wp_set_password(${JSON.stringify(TWO_FACTOR_PASSWORD)}, $uid);`,
        `update_user_meta($uid, 'buddynext_email_verified', '1');`,
        `update_user_meta($uid, 'bn_2fa_enabled', '1');`,
        `update_user_meta($uid, 'bn_2fa_secret', 'JBSWY3DPEHPK3PXP');`,
        `echo $uid;`,
    ].join(' ');
    await wp(['eval', php]);
    return login;
}

/**
 * Set BuddyNext's registration MODE (buddynext_reg_mode: open | invite | closed)
 * and return the PREVIOUS mode so a spec can restore it in afterAll. This is the
 * source of truth - it reconciles WP's users_can_register - so an invite-only or
 * closed site shows no public signup form until the mode is 'open'. This is how
 * the signup specs stop being an environment skip: open registration for the run,
 * exercise the real submit path, then put the mode back exactly as it was.
 */
export async function setRegistrationMode(mode: 'open' | 'invite' | 'closed'): Promise<string> {
    let previous = 'open';
    try {
        const out = (await wp(['option', 'get', 'buddynext_reg_mode'])).trim();
        const match = out.match(/\b(open|invite|closed)\b/);
        previous = match ? match[1] : 'open';
    } catch {
        previous = 'open';
    }
    await wp(['option', 'update', 'buddynext_reg_mode', mode]);
    return previous;
}

/** Set a wp_options value through WP-CLI (for seeding a spec's starting state). */
export async function setOption(name: string, value: string): Promise<void> {
    await wp(['option', 'update', name, value]);
}

/**
 * Clear RegistrationGuard's per-IP sign-up rate-limit counter (bn_reg_rl_*
 * rows in wp_bn_rate_limits — see includes/Core/RateLimiter.php).
 *
 * RATE_MAX is 5 registrations/hour/IP (RegistrationGuard::RATE_PREFIX). A
 * spec that registers a real account every run trips this after a handful of
 * repeated local runs against the same dev site — the same IP that just
 * exercised the endpoint five times gets "Too many sign-up attempts", which
 * looks exactly like the guard's real spam-block response and is easy to
 * mistake for a broken journey. Clearing it before the run keeps the spec
 * deterministic regardless of how many times it (or another spec hitting the
 * same endpoint) ran in the last hour on this box.
 */
export async function resetRegistrationRateLimit(): Promise<void> {
    await wp(['eval', 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}bn_rate_limits WHERE rl_key LIKE \'bn_reg_rl_%\'");']);
}

/** Delete a wp_options value through WP-CLI, so it falls back to its declared default. */
export async function deleteOption(name: string): Promise<void> {
    try {
        await wp(['option', 'delete', name]);
    } catch {
        // Already absent — nothing to delete.
    }
}

/** Read a wp_options value; '' when the option does not exist. */
export async function getOption(name: string): Promise<string> {
    let out: string;
    try {
        out = await wp(['option', 'get', name]);
    } catch {
        return '';
    }
    const lines = out.split('\n').map((l) => l.trim()).filter((l) => l !== '' && !/^(Warning|Deprecated|Notice):/.test(l));
    return lines.length ? lines[lines.length - 1] : '';
}

/**
 * Read a user meta value through WP-CLI, so a spec can assert an EFFECT (the
 * verified flag flipping) rather than a screen string. Returns the last non-empty
 * line so a stray startup warning above the value does not leak into the compare.
 */
export async function getUserMeta(userLogin: string, key: string): Promise<string> {
    // `wp user meta get` exits non-zero when the key does not exist. For our
    // purposes an absent key IS the value: an unverified member has no
    // buddynext_email_verified meta, which must read as '' not throw.
    let out: string;
    try {
        out = await wp(['user', 'meta', 'get', userLogin, key]);
    } catch {
        return '';
    }
    const lines = out.split('\n').map((l) => l.trim()).filter((l) => l !== '' && !/^(Warning|Deprecated|Notice):/.test(l));
    return lines.length ? lines[lines.length - 1] : '';
}

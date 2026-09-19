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

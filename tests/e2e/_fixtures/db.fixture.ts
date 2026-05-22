/**
 * DB seeding helpers via WP-CLI.
 *
 * These are placeholder shapes for the seeding API. They will shell out to
 * `wp --path=<wp_root>` once we wire forums.local-aware paths. For now the
 * functions exist so specs can import them and the type system stays happy.
 *
 * The WP root for forums.local is:
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

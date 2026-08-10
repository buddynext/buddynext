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
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);

const WP_PATH =
    process.env.BN_WP_PATH ?? '/Users/vapvarun/Local Sites/buddynext-dev/app/public';

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
    const existing = await userId(login);
    if (existing > 0) {
        return existing;
    }
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
    return parseInt(out.trim(), 10);
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

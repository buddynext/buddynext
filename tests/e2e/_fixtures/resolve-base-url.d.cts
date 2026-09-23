/**
 * Type declarations for resolve-base-url.cjs (CommonJS fixture consumed by
 * playwright.config.ts). Keeps the config strictly typed without an `any` import.
 */

/** Ranked list of candidate site base URLs discovered on this machine. */
export function siteCandidates(): string[];

/**
 * The base URL for the Playwright config default, or undefined when it cannot
 * be determined from this machine (callers then require BN_BASE_URL).
 */
export function resolveBaseUrl(): string | undefined;

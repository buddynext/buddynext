/**
 * Viewport name constants  -  used by specs to scope `.skip(project, ...)` logic.
 */
export const VIEWPORT = {
    DESKTOP: 'desktop',
    IPAD: 'ipad',
    MOBILE: 'mobile',
} as const;

export type ViewportName = (typeof VIEWPORT)[keyof typeof VIEWPORT];

/**
 * Width breakpoints used by the shell (mirror of `assets/css/bn-shell.css`).
 */
export const BREAKPOINT = {
    RAIL_COLLAPSE: 1024,
    MOBILE_NAV: 768,
} as const;

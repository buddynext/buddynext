/**
 * Central selector dictionary for BuddyNext E2E specs.
 *
 * Every spec imports from here so a markup rename only touches one file.
 * Selectors follow the `.bn-*` prefix contract defined in `/ux-audit` skill.
 *
 * Selector values reflect the LIVE markup on buddynext-dev.local as of
 * the v0.3-beta build (Interactivity API directives, .bn-pf-* for profile,
 * .bn-sh-* for space hero, .bn-ob-* for onboarding).
 */
export const sel = {
    // Shell
    app: '.bn-app',
    appShell: '.bn-app__shell',
    appMain: '.bn-app__main',
    appRight: '.bn-app__right',
    rail: '.bn-app__rail',
    railItem: '.bn-rail__item',
    railLabel: '.bn-rail__label',
    railBadge: '.bn-rail__badge',
    mobileNav: '.bn-mobile-nav',
    mobileNavItem: '.bn-mobile-nav__item',
    mobileNavBadge: '.bn-mobile-nav__badge',
    contextNav: '.bn-context-nav',

    // Buttons / forms
    btn: '.bn-btn',
    btnPrimary: '.bn-btn[data-variant="primary"]',
    btnGhost: '.bn-btn[data-variant="ghost"]',

    // Composer (Interactivity API: actions.submit, textarea .bn-composer__prompt)
    composer: '.bn-composer',
    composerTextarea: '.bn-composer__prompt, .bn-composer textarea',
    composerSubmit: 'button.bn-composer__submit',
    composerModePoll: '[data-composer-mode="poll"], [data-wp-on--click="actions.openPoll"]',
    composerModeEvent: '[data-composer-mode="event"], [data-wp-on--click="actions.openEvent"]',

    // Posts (post card actions use Interactivity directives).
    // Child selectors below are SCOPED — use them via parent.locator(...)
    // so they compose correctly with `.bn-post-card`.
    postCard: '.bn-post-card',
    postContent: '.bn-post-card__content, .bn-post-card__body',
    postReact: '[data-wp-on--click="actions.toggleReactionPicker"]',
    postReactPicker: '.bn-post-card__emoji-picker',
    postReactEmoji: '.bn-post-card__emoji-btn',
    postReactCount: '.bn-post-card__react-count, .bn-post-card__reaction-summary',
    postComment: '[data-wp-on--click="actions.openComments"]',
    postBookmark: '[data-wp-on--click="actions.toggleBookmark"]',
    postShare: '[data-wp-on--click="actions.openShare"]',
    postMore: '[data-wp-on--click="actions.toggleOptionsMenu"]',

    // Comments
    commentList: '.bn-comment-list, .bn-comments, .bn-post-card__comments',
    commentForm: '.bn-comment-form',
    commentInput: '.bn-comment-form__input, .bn-comment-form textarea, .bn-comment-form input[type="text"]',

    // Feed list
    feedList: '.bn-feed-list, .bn-feed, [data-feed-list]',
    feedEmpty: '.bn-feed-empty',

    // Auth (live build uses .bn-auth-* on /login/ and /signup/)
    authPage: '.bn-auth, .bn-auth-page, body.login',
    loginUser: '#bn-login-user, #user_login',
    loginPass: '#bn-login-password, #user_pass',
    loginSubmit: '.bn-auth-form button[type="submit"], #wp-submit, .bn-auth__submit',
    signupUser: '#bn-signup-username',
    signupEmail: '#bn-signup-email, #user_email, [name="user_email"]',
    signupPass: '#bn-signup-password',
    signupForm: '.bn-auth-card[data-variant="register"], form[data-wp-on--submit="actions.submitSignup"]',
    registerEmail: '#bn-signup-email, #user_email, [name="user_email"]',
    lostPasswordForm: '#lostpasswordform, form[action*="lostpassword"]',

    // Directory
    memberCard: '.bn-member-card, [data-member-card]',
    memberFilter: '.bn-directory__filter [data-filter]',
    directorySearch: '.bn-directory__search input, [data-directory-search]',

    // Profile (live markup uses .bn-pf-* prefix)
    profileHero: '.bn-pf-hero, .bn-profile__hero, .bn-profile-hero',
    profileStats: '.bn-pf-stats, .bn-profile__stats',
    profileTab: '.bn-pf-tabs .bn-tab, .bn-tabs.bn-pf-tabs .bn-tab, .bn-profile__tabs [role="tab"]',
    profileViewsWidget: '[data-widget="profile-views"]',

    // Spaces (live markup: .bn-sh-hero, .bn-sh-members)
    spaceCard: '.bn-space-card',
    spaceFilter: '.bn-spaces__filter [data-filter]',
    spaceJoin: '.bn-space-card [data-action="join"]',
    spaceHero: '.bn-sh-hero, .bn-space__hero, .bn-space-hero',
    spaceTab: '.bn-sh-hero__tabs .bn-tab, .bn-tabs.bn-sh-hero__tabs a.bn-tab',
    spaceMemberCard: '.bn-sh-members__card, .bn-sh-side-member, .bn-member-card',

    // Onboarding
    onboardingShell: '.bn-ob-shell, .bn-ob-wrap',
    onboardingStep: '.bn-ob-step',
    onboardingProgress: '.bn-progress, .bn-ob-progress',

    // Hashtags
    hashtagChip: '.bn-hashtag-chip, [data-hashtag]',
    hashtagFollow: '.bn-hashtag-chip [data-action="follow"]',

    // Notifications
    notifList: '.bn-notif-list, [data-notif-list]',
    notifItem: '.bn-notif-item, [data-notif-item]',
    notifMarkAll: '[data-action="mark-all-read"]',

    // Messages
    dmList: '.bn-dm-list, [data-dm-list]',
    dmThread: '.bn-dm-thread, [data-dm-thread]',
    dmInput: '.bn-dm-input textarea, [data-dm-input]',

    // Admin
    adminFeatures: '#buddynext-features, [data-admin-features]',
    adminModQueue: '.bn-mod-queue, [data-mod-queue]',

    // Theme chrome (WordPress theme, not BN). Live theme is Astra.
    themeHeader: 'header#masthead, header.site-header, .ast-primary-header',
    themeFooter: 'footer#colophon, footer.site-footer',
    wpAdminBar: '#wpadminbar',
    wpAdminBarLogout: '#wp-admin-bar-logout a',
} as const;

/**
 * Page URLs (relative to baseURL).
 */
export const urls = {
    home: '/',
    auth: '/login/',
    login: '/login/',
    signup: '/signup/',
    lostPassword: '/wp-login.php?action=lostpassword',
    feed: '/activity/',
    explore: '/activity/explore/',
    hashtag: (slug: string) => `/activity/hashtag/${slug}/`,
    members: '/members/',
    member: (login: string) => `/members/${login}/`,
    memberEdit: (login: string) => `/members/${login}/edit/`,
    memberFollowers: (login: string) => `/members/${login}/followers/`,
    memberFollowing: (login: string) => `/members/${login}/following/`,
    memberConnections: (login: string) => `/members/${login}/connections/`,
    spaces: '/spaces/',
    space: (slug: string) => `/spaces/${slug}/`,
    messages: '/messages/',
    notifications: '/notifications/',
    onboarding: '/onboarding/',
    adminSettings: '/wp-admin/admin.php?page=buddynext-settings',
    adminModeration: '/wp-admin/admin.php?page=buddynext-moderation',
    adminCustomDomains: '/wp-admin/admin.php?page=buddynext-domains',
    adminEmailEditor: '/wp-admin/admin.php?page=buddynext-emails',
} as const;

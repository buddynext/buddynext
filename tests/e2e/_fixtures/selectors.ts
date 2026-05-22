/**
 * Central selector dictionary for BuddyNext E2E specs.
 *
 * Every spec imports from here so a markup rename only touches one file.
 * Selectors follow the `.bn-*` prefix contract defined in `/ux-audit` skill.
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

    // Composer
    composer: '.bn-composer',
    composerTextarea: '.bn-composer textarea, .bn-composer [contenteditable="true"]',
    composerSubmit: '.bn-composer [data-action="submit"], .bn-composer button[type="submit"]',
    composerModePoll: '[data-composer-mode="poll"]',
    composerModeEvent: '[data-composer-mode="event"]',

    // Posts
    postCard: '.bn-post-card',
    postContent: '.bn-post-card__content, .bn-post-card__body',
    postReact: '.bn-post-card [data-action="react"], .bn-post-card [data-action="like"]',
    postReactCount: '.bn-post-card__react-count, .bn-post-card [data-react-count]',
    postComment: '.bn-post-card [data-action="comment"]',
    postBookmark: '.bn-post-card [data-action="bookmark"]',
    postShare: '.bn-post-card [data-action="share"]',
    postMore: '.bn-post-card [data-action="more"]',

    // Comments
    commentList: '.bn-comments, .bn-post-card__comments',
    commentForm: '.bn-comment-form, [data-comment-form]',
    commentInput: '.bn-comment-form textarea, [data-comment-form] textarea',

    // Feed list
    feedList: '.bn-feed, [data-feed-list]',
    feedEmpty: '.bn-feed-empty',

    // Auth
    authPage: '.bn-auth, body.login',
    loginUser: '#user_login',
    loginPass: '#user_pass',
    loginSubmit: '#wp-submit, .bn-auth__submit',
    registerEmail: '#user_email, [name="user_email"]',
    lostPasswordForm: '#lostpasswordform, form[action*="lostpassword"]',

    // Directory
    memberCard: '.bn-member-card, [data-member-card]',
    memberFilter: '.bn-directory__filter [data-filter]',
    directorySearch: '.bn-directory__search input, [data-directory-search]',

    // Profile
    profileHero: '.bn-profile__hero, .bn-profile-hero',
    profileStats: '.bn-profile__stats',
    profileTab: '.bn-profile__tabs [role="tab"], .bn-profile-tabs__item',
    profileViewsWidget: '[data-widget="profile-views"]',

    // Spaces
    spaceCard: '.bn-space-card',
    spaceFilter: '.bn-spaces__filter [data-filter]',
    spaceJoin: '.bn-space-card [data-action="join"]',
    spaceHero: '.bn-space__hero, .bn-space-hero',

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
} as const;

/**
 * Page URLs (relative to baseURL).
 */
export const urls = {
    home: '/',
    auth: '/auth/',
    lostPassword: '/wp-login.php?action=lostpassword',
    feed: '/activity/',
    explore: '/activity/explore/',
    hashtag: (slug: string) => `/activity/hashtag/${slug}/`,
    members: '/members/',
    member: (login: string) => `/members/${login}/`,
    memberEdit: (login: string) => `/members/${login}/edit/`,
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

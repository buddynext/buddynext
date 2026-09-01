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
    // Declared once. It was declared twice — the second, narrower '.bn-feed-list'
    // silently won and dropped the two fallbacks, so a spec that needed them
    // failed looking like a missing feed rather than a shadowed selector.
    feedList: '.bn-feed-list, .bn-feed, [data-feed-list]',
    feedEmpty: '.bn-feed-empty',
    // The Load-more control is a real <a href>, not a JS sentinel — it has to
    // keep working with JS off. #bn-load-more is the region-swap anchor.
    feedLoadMore: '#bn-load-more .bn-load-more__btn',

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
    //
    // The member directory renders client-side into .bn-md-* markup. These read
    // `.bn-member-card` / `.bn-directory__*`, which no template or store emits —
    // `bn-member-card` survives only as a BLOCK NAME in BlockRegistrar.php, and
    // `.bn-directory__*` not at all. A count() on a selector matching nothing is
    // 0, and every spec here either asserted >= 0 or soft-skipped on "no cards",
    // so J-24/J-27/J-28 reported green for a page they never looked at.
    memberCard: '.bn-md-card, [data-member-card]',
    memberDirectoryEmpty: '.bn-md-empty',
    memberCardFollow: '.bn-md-card__follow',
    memberCardMenu: '.bn-md-card__menu',
    memberCardMute: '.bn-md-card__mute',
    // Two filter controls exist. The member-type pills (.bn-md-pill) render ONLY
    // when the site has member types configured — on a site without them the row
    // is genuinely absent, which is a real precondition rather than a broken
    // selector. The relation tabs always render, so match either: the spec should
    // assert against a control that is actually on the page.
    memberFilter: '.bn-md-pill, .bn-md-strip .bn-tab[data-relation]',
    // The member directory does NOT use parts/filter-strip.php — it has its own
    // strip. Only the SPACES directory includes filter-strip (input[name=bn_search]),
    // so these are two controls, not one shared seam. Verified on the rendered
    // page: /members/ has 1 .bn-md-strip__search-input and 0 input[name=bn_search].
    directorySearch: '.bn-md-strip__search-input',

    // Profile (live markup uses .bn-pf-* prefix)
    profileHero: '.bn-pf-hero, .bn-profile__hero, .bn-profile-hero',
    // Stat counts are the metric row inside the hero: .bn-nav-metrics.bn-pf-metricrow
    // wrapping one .bn-nav-metric anchor per count. The old .bn-pf-stats never
    // rendered, so every assertion on it failed regardless of the feature working.
    profileStats: '.bn-pf-metricrow, .bn-nav-metrics, .bn-pf-stats',
    // Profile tabs render as .bn-navgroup > .bn-tabs[role="tablist"] > a.bn-tab.
    // There is no .bn-pf-tabs wrapper; scoping by role keeps this honest without
    // matching the feed's own .bn-tabs on a different surface.
    profileTab: '.bn-navgroup .bn-tabs [role="tab"], .bn-pf-tabs .bn-tab, .bn-profile__tabs [role="tab"]',
    // Pro's who-viewed widget renders .bn-pf-views (it never emitted
    // data-widget="profile-views"). It deliberately returns early when the member
    // has zero views (ProfileViewsWidget.php:116), so a spec must seed a view.
    // PRO markup (buddynext-pro Analytics\ProfileViewsWidget). Correct as-is —
    // it reads as "dead" to any sweep that only greps the free plugin.
    profileViewsWidget: '.bn-pf-views',

    // Spaces (live markup: .bn-sh-hero, .bn-sh-members; directory is .bn-sd-*)
    // Live markup is .bn-sd-card. `.bn-space-card` exists only as a BLOCK NAME in
    // BlockRegistrar.php — assets/js/spaces/store.js:583 already carried a comment
    // saying these "exist nowhere in the markup", and the specs kept using them
    // anyway because a zero count read as "nothing seeded" rather than "wrong
    // selector".
    spaceCard: '.bn-sd-card',
    spaceDirectoryEmpty: '.bn-sd-empty',
    // Category chips are BUTTONS. The same .bn-sd-chip class is also on the
    // "All Spaces" / "My Spaces" ANCHORS, which are pretty-URL links — a bare
    // .bn-sd-chip therefore made `.first()` a link, and clicking it navigated
    // away mid-assertion. Scope to the buttons, which are the reactive filter.
    spaceFilter: 'button.bn-sd-chip',
    // Spaces search comes from templates/parts/filter-strip.php, which the
    // spaces directory includes and the member directory does not. J-39 had its
    // own inline '.bn-spaces__search input' copy, which matched nothing.
    spaceSearch: 'input[name="bn_search"]',
    // The card carries no [data-action]; the join control is keyed on
    // data-current-state (templates/parts/space-directory-card.php:195).
    spaceJoin: '.bn-sd-card [data-current-state="join"]',
    spaceHero: '.bn-sh-hero, .bn-space__hero, .bn-space-hero',
    // Live markup is .bn-sh-tab inside .bn-sh-tabs — not the generic .bn-tab.
    spaceTab: '.bn-sh-tabs .bn-sh-tab, .bn-sh-hero__tabs .bn-sh-tab',
    spaceMemberCard: '.bn-sh-members__card, .bn-sh-side-member, .bn-member-card',

    // Onboarding
    onboardingShell: '.bn-ob-shell, .bn-ob-wrap',
    onboardingStep: '.bn-ob-step',
    // The wizard shows its position via step heads (.bn-ob-form-head__step and
    // .bn-ob-step), not a progress bar. .bn-progress has never existed, so this
    // assertion could only ever fail.
    onboardingProgress: '.bn-ob-form-head__step, .bn-ob-step, .bn-ob-progress',

    // Hashtags
    hashtagChip: '.bn-hashtag-chip, [data-hashtag]',
    hashtagFollow: '.bn-hashtag-related__follow',

    // Notifications
    notifList: '.bn-notif-list, [data-notif-list]',
    // Live markup is .bn-notif-row (templates/parts/notification-row.php:71).
    notifItem: '.bn-notif-row, [data-notif-item]',
    notifMarkAll: '[data-action="mark-all-read"]',

    // Messages
    // Live markup is .bn-dm-rail__list (templates/parts/dm-rail.php:194). The old
    // value matched nothing, so messages/list.spec.ts's bridge-ACTIVE branch had
    // never run green — it only ever passed via the bridge-absent path.
    dmList: '.bn-dm-rail__list, [data-dm-list]',
    dmEmpty: '.bn-dm-rail__empty',
    dmThread: '.bn-dm-thread, [data-dm-thread]',
    dmInput: '.bn-dm-input textarea, [data-dm-input]',

    // Admin
    adminFeatures: '.bn-admin-hub, [data-admin-features]',
    adminModQueue: '.bn-mod-queue, [data-mod-queue]',

    // Theme chrome (WordPress theme, not BN). Release testing runs on Reign,
    // which is what customers run; Astra's .ast-* classes are kept so the suite
    // still works on an Astra dev site.
    themeHeader: 'header#masthead, header.site-header, .ast-primary-header, .reign-header, #reign-header',
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
    // Privacy, Account and Appearance moved OFF the profile-edit screen and onto
    // the settings hub. The specs that still asserted them on /members/{u}/edit/
    // were looking in the old place and failing on a feature that works.
    settings: '/settings/',
    settingsPrivacy: '/settings/privacy/',
    settingsAccount: '/settings/account/',
    settingsAppearance: '/settings/appearance/',
    settingsNotifications: '/settings/notifications/',
    notifPrefs: '/notifications/preferences/',
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

# Phase 1: Core Foundation — Implementation Plan

> ✅ **COMPLETED** — 2026-03-21. All deliverables implemented and verified. Remaining items (5 new tables, 2 new columns) tracked in MASTER_DEVELOPMENT_PLAN.md BLOCK 1.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bootable WordPress plugin with all 28 `bn_*` tables created, `buddynext_can()` permission function, Abilities API registration, and the webhook access endpoint — the foundation every other phase builds on.

**Architecture:** Plugin boots at `plugins_loaded:15` (after addons at :10, before Pro at :20). A `Container` holds service singletons. `buddynext_can()` is a global function backed by `PermissionService` — every gate in every later phase calls only this one function. All `bn_*` tables are created in a single `Installer::run()` via `dbDelta()`.

**Tech Stack:** PHP 8.1+, Composer PSR-4 autoload, WordPress unit tests (PHPUnit 9 + WP test suite), WordPress Abilities API (WP 6.9+), Action Scheduler (bundled in Phase 6)

**Frontend Stack (Phase 3+):** PHP templates (theme-overridable via `theme/buddynext/`) + WP Interactivity API (`store('buddynext', {...})` — no build step, same pattern as Jetonomy and WPMediaVerse. SSE endpoint (`buddynext/v1/sse`) for real-time notification/DM badge counts. CSS custom properties for dark/light mode theming. Fonts: Inter (body/UI) + Plus Jakarta Sans (display). Base type size: 15px.

---

## Phase Map

| # | Phase | Depends on |
|---|-------|-----------|
| **1** | **Core Foundation** ← you are here | — |
| 2 | Social Graph | 1 |
| 3 | Activity Feed | 1, 2 |
| 4 | Profiles + Member Directory + Search | 1 |
| 5 | Spaces | 1, 2, 3 |
| 6 | Notifications + Email | 1, 2 |
| 7 | Reactions, Comments + Hashtags | 1, 3 |
| 8 | Moderation | 1, 3, 7 |
| 9 | Direct Messaging | 1, 2, 6 |
| 10 | Bridges (WPMediaVerse, Jetonomy, WBGam, Career Board) | 1–6 |
| 11 | Gutenberg Blocks + Onboarding Wizard | 1–5 |

---

## File Structure

```
buddynext/
├── buddynext.php                                    # Plugin header + constants + bootstrap
├── composer.json                                    # PSR-4 autoload
├── phpunit.xml.dist                                 # PHPUnit config
├── bin/
│   └── install-wp-tests.sh                         # WP test suite installer
├── includes/
│   ├── Core/
│   │   ├── Plugin.php                               # Singleton, boots at plugins_loaded:15
│   │   ├── Container.php                            # Simple service container
│   │   ├── Installer.php                            # All 28 bn_* tables via dbDelta()
│   │   ├── Abilities.php                            # Registers all buddynext-* abilities
│   │   └── PermissionService.php                    # buddynext_can() 4-layer implementation
│   ├── REST/
│   │   ├── Router.php                               # Registers buddynext/v1 namespace
│   │   └── Controllers/
│   │       └── AccessWebhookController.php          # POST buddynext/v1/webhook/access
│   └── Admin/
│       └── Settings.php                             # Admin menu skeleton + webhook secret field
└── tests/
    ├── bootstrap.php                                # WP test suite bootstrap
    ├── Core/
    │   ├── PluginBootTest.php                       # Constants + hooks
    │   ├── InstallerTest.php                        # All 28 tables + indexes
    │   ├── AbilitiesTest.php                        # All abilities registered
    │   └── PermissionServiceTest.php                # 4-layer model
    └── REST/
        └── AccessWebhookTest.php                    # All 6 webhook actions
```

---

> **Before running any test in Tasks 2–6:** complete Task 8 Steps 1–3 first (create `tests/bootstrap.php` and run `bin/install-wp-tests.sh`). Without the WP test suite installed, every `vendor/bin/phpunit` call will fail with "WP_TESTS_DIR not found".

---

## Task 1: Composer + Autoloader

**Files:**
- Create: `buddynext/composer.json`
- Create: `buddynext/phpunit.xml.dist`

- [ ] **Step 1: Create `composer.json`**

```json
{
  "name": "wbcom-designs/buddynext",
  "description": "BuddyNext — Social layer for WordPress",
  "type": "wordpress-plugin",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0"
  },
  "autoload": {
    "psr-4": {
      "BuddyNext\\": "includes/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "BuddyNext\\Tests\\": "tests/"
    }
  },
  "config": {
    "optimize-autoloader": true
  }
}
```

- [ ] **Step 2: Install dependencies**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext"
composer install
```

Expected: `vendor/` created with autoloader at `vendor/autoload.php`.

- [ ] **Step 3: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
  bootstrap="tests/bootstrap.php"
  backupGlobals="false"
  colors="true"
  convertDeprecationsToExceptions="false"
>
  <testsuites>
    <testsuite name="buddynext">
      <directory>tests/</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 4: Commit**

```bash
git init
git add composer.json composer.lock vendor/ phpunit.xml.dist
git commit -m "chore: add composer autoloader and phpunit config"
```

---

## Task 2: Plugin Header + Bootstrap

**Files:**
- Create: `buddynext/buddynext.php`
- Create: `buddynext/includes/Core/Container.php`
- Create: `buddynext/includes/Core/Plugin.php`
- Create: `buddynext/tests/Core/PluginBootTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Core/PluginBootTest.php

class PluginBootTest extends WP_UnitTestCase {

    public function test_version_constant_defined(): void {
        $this->assertTrue( defined( 'BUDDYNEXT_VERSION' ) );
    }

    public function test_dir_constant_defined(): void {
        $this->assertTrue( defined( 'BUDDYNEXT_DIR' ) );
        $this->assertDirectoryExists( BUDDYNEXT_DIR );
    }

    public function test_buddynext_loaded_hook_fires(): void {
        $fired = false;
        add_action( 'buddynext_loaded', function () use ( &$fired ) {
            $fired = true;
        } );
        do_action( 'buddynext_loaded' );
        $this->assertTrue( $fired );
    }

    public function test_container_returns_singleton(): void {
        $a = \BuddyNext\Core\Container::instance();
        $b = \BuddyNext\Core\Container::instance();
        $this->assertSame( $a, $b );
    }

    public function test_container_bind_and_get(): void {
        $c = \BuddyNext\Core\Container::instance();
        $c->bind( 'test_service', fn() => new stdClass() );
        $obj1 = $c->get( 'test_service' );
        $obj2 = $c->get( 'test_service' );
        $this->assertSame( $obj1, $obj2 ); // same resolved instance
    }
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext"
vendor/bin/phpunit tests/Core/PluginBootTest.php --testdox
```

Expected: FAIL — class not found / constants not defined.

- [ ] **Step 3: Create `includes/Core/Container.php`**

```php
<?php
namespace BuddyNext\Core;

class Container {

    private static ?self $instance = null;
    private array $bindings = [];
    private array $resolved  = [];

    private function __construct() {}

    public static function instance(): static {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public function bind( string $key, callable $factory ): void {
        $this->bindings[ $key ] = $factory;
        unset( $this->resolved[ $key ] ); // reset cached instance if rebound
    }

    public function get( string $key ): mixed {
        if ( ! isset( $this->resolved[ $key ] ) ) {
            if ( ! isset( $this->bindings[ $key ] ) ) {
                throw new \RuntimeException( "BuddyNext Container: no binding for '{$key}'." );
            }
            $this->resolved[ $key ] = ( $this->bindings[ $key ] )( $this );
        }
        return $this->resolved[ $key ];
    }
}
```

- [ ] **Step 4: Create `includes/Core/Plugin.php`**

```php
<?php
namespace BuddyNext\Core;

class Plugin {

    private static bool $booted = false;

    public static function init(): void {
        if ( static::$booted ) {
            return;
        }
        static::$booted = true;

        $container = Container::instance();
        static::register_services( $container );

        if ( is_admin() ) {
            $container->get( 'admin_settings' )->register();
        }

        $container->get( 'rest_router' )->register();

        // Bridges loaded here in Phase 10.
        do_action( 'buddynext_load_bridges' );

        do_action( 'buddynext_loaded' );
    }

    private static function register_services( Container $c ): void {
        $c->bind( 'permissions',    fn() => new PermissionService() );
        $c->bind( 'abilities',      fn() => new Abilities() );
        $c->bind( 'rest_router',    fn() => new \BuddyNext\REST\Router() );
        $c->bind( 'admin_settings', fn() => new \BuddyNext\Admin\Settings() );

        // Register abilities immediately (must happen at plugins_loaded:15).
        $c->get( 'abilities' )->register();
    }
}
```

- [ ] **Step 5: Create `buddynext.php`**

```php
<?php
/**
 * Plugin Name: BuddyNext
 * Plugin URI:  https://wbcomdesigns.com
 * Description: The social layer for WordPress.
 * Version:     0.1.0
 * Author:      Wbcom Designs
 * Text Domain: buddynext
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.1
 */
defined( 'ABSPATH' ) || exit;

define( 'BUDDYNEXT_VERSION', '0.1.0' );
define( 'BUDDYNEXT_FILE',    __FILE__ );
define( 'BUDDYNEXT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BUDDYNEXT_URL',     plugin_dir_url( __FILE__ ) );

require_once BUDDYNEXT_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, [ \BuddyNext\Core\Installer::class, 'run' ] );

add_action( 'plugins_loaded', [ \BuddyNext\Core\Plugin::class, 'init' ], 15 );

/**
 * Check if a user has a BuddyNext capability.
 *
 * @param int    $user_id
 * @param string $capability  e.g. 'buddynext-feed/create-post'
 * @param array  $context     Optional. e.g. ['space_id' => 42]
 */
function buddynext_can( int $user_id, string $capability, array $context = [] ): bool {
    return \BuddyNext\Core\Container::instance()
        ->get( 'permissions' )
        ->can( $user_id, $capability, $context );
}

/**
 * Deduct credits from a user balance (floors at 0).
 */
function buddynext_spend_credits( int $user_id, int $amount, string $reason = '' ): void {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}bn_user_credits (user_id, balance) VALUES (%d, 0)
         ON DUPLICATE KEY UPDATE balance = GREATEST(0, balance - %d)",
        $user_id,
        abs( $amount )
    ) );
    do_action( 'buddynext_credits_spent', $user_id, $amount, $reason );
}
```

- [ ] **Step 6: Run — confirm PASS**

```bash
vendor/bin/phpunit tests/Core/PluginBootTest.php --testdox
```

Expected: 5 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add buddynext.php includes/Core/Plugin.php includes/Core/Container.php tests/Core/PluginBootTest.php
git commit -m "feat: add plugin bootstrap, constants, and service container"
```

---

## Task 3: Database Installer — All 28 Tables

**Files:**
- Create: `includes/Core/Installer.php`
- Create: `tests/Core/InstallerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Core/InstallerTest.php

class InstallerTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        \BuddyNext\Core\Installer::run();
    }

    /** @dataProvider table_provider */
    public function test_table_exists( string $table ): void {
        global $wpdb;
        $full  = $wpdb->prefix . $table;
        $found = $wpdb->get_var( "SHOW TABLES LIKE '{$full}'" );
        $this->assertSame( $full, $found, "Table {$table} should exist after installation." );
    }

    public static function table_provider(): array {
        return array_map( fn( $t ) => [ $t ], [
            // Social graph
            'bn_follows', 'bn_connections', 'bn_blocks',
            // Feed
            'bn_posts', 'bn_bookmarks', 'bn_shares',
            // Spaces
            'bn_spaces', 'bn_space_members', 'bn_space_categories',
            // Notifications + email
            'bn_notifications', 'bn_notification_prefs',
            'bn_email_templates', 'bn_email_log', 'bn_verify_tokens',
            // Reactions + comments
            'bn_reactions', 'bn_comments',
            // Hashtags
            'bn_hashtags', 'bn_post_hashtags', 'bn_hashtag_follows',
            // Search
            'bn_search_index',
            // Roles + permissions
            'bn_user_abilities', 'bn_user_credits', 'bn_webhook_log',
            // DM
            'bn_conversations', 'bn_conversation_participants',
            'bn_messages', 'bn_message_reactions',
            // Activity log
            'bn_activity_log',
        ] );
    }
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
vendor/bin/phpunit tests/Core/InstallerTest.php --testdox
```

Expected: FAIL — Installer class not found.

- [ ] **Step 3: Create `includes/Core/Installer.php`**

```php
<?php
namespace BuddyNext\Core;

class Installer {

    public static function run(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( static::get_schema( $wpdb->prefix, $charset ) as $sql ) {
            dbDelta( $sql );
        }

        update_option( 'buddynext_db_version', BUDDYNEXT_VERSION );
    }

    private static function get_schema( string $p, string $cs ): array {
        return [

            // ── Social Graph ───────────────────────────────────────────────────

            "CREATE TABLE {$p}bn_follows (
                follower_id  BIGINT(20) UNSIGNED NOT NULL,
                following_id BIGINT(20) UNSIGNED NOT NULL,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (follower_id, following_id),
                KEY          following (following_id)
            ) {$cs};",

            "CREATE TABLE {$p}bn_connections (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                requester_id BIGINT(20) UNSIGNED NOT NULL,
                recipient_id BIGINT(20) UNSIGNED NOT NULL,
                status       ENUM('pending','accepted','declined','withdrawn') NOT NULL DEFAULT 'pending',
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   pair (requester_id, recipient_id),
                KEY          recipient_status (recipient_id, status),
                KEY          requester_status (requester_id, status)
            ) {$cs};",

            "CREATE TABLE {$p}bn_blocks (
                blocker_id BIGINT(20) UNSIGNED NOT NULL,
                blocked_id BIGINT(20) UNSIGNED NOT NULL,
                type       ENUM('block','mute') NOT NULL DEFAULT 'block',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (blocker_id, blocked_id),
                KEY         blocked (blocked_id)
            ) {$cs};",

            // ── Activity Feed ──────────────────────────────────────────────────

            "CREATE TABLE {$p}bn_posts (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id         BIGINT(20) UNSIGNED NOT NULL,
                space_id        BIGINT(20) UNSIGNED DEFAULT NULL,
                type            VARCHAR(32) NOT NULL DEFAULT 'text',
                content         LONGTEXT DEFAULT NULL,
                privacy         ENUM('public','followers','connections','space_members','private') NOT NULL DEFAULT 'public',
                reaction_count  INT UNSIGNED NOT NULL DEFAULT 0,
                comment_count   INT UNSIGNED NOT NULL DEFAULT 0,
                is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
                is_announcement TINYINT(1) NOT NULL DEFAULT 0,
                scheduled_at    DATETIME DEFAULT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY     (id),
                KEY             user_feed (user_id, created_at),
                KEY             space_feed (space_id, created_at),
                KEY             explore (privacy, created_at),
                KEY             scheduled (scheduled_at)
            ) {$cs};",

            "CREATE TABLE {$p}bn_bookmarks (
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                post_id    BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, post_id)
            ) {$cs};",

            "CREATE TABLE {$p}bn_shares (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                post_id    BIGINT(20) UNSIGNED NOT NULL,
                content    TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         user_shares (user_id),
                KEY         post_shares (post_id)
            ) {$cs};",

            // ── Spaces ─────────────────────────────────────────────────────────

            "CREATE TABLE {$p}bn_spaces (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(255) NOT NULL,
                slug         VARCHAR(200) NOT NULL,
                description  TEXT DEFAULT NULL,
                category_id  BIGINT(20) UNSIGNED DEFAULT NULL,
                parent_id    BIGINT(20) UNSIGNED DEFAULT NULL,
                type         ENUM('open','private','secret') NOT NULL DEFAULT 'open',
                owner_id     BIGINT(20) UNSIGNED NOT NULL,
                member_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY   slug (slug),
                KEY          owner (owner_id),
                KEY          category (category_id)
            ) {$cs};",

            "CREATE TABLE {$p}bn_space_members (
                space_id          BIGINT(20) UNSIGNED NOT NULL,
                user_id           BIGINT(20) UNSIGNED NOT NULL,
                role              ENUM('owner','moderator','member') NOT NULL DEFAULT 'member',
                notification_pref ENUM('all','mentions','none') NOT NULL DEFAULT 'all',
                joined_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY       (space_id, user_id),
                KEY               user_role (user_id, role)
            ) {$cs};",

            "CREATE TABLE {$p}bn_space_categories (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(100) NOT NULL,
                slug        VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                sort_order  INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY  slug (slug)
            ) {$cs};",

            // ── Notifications + Email ──────────────────────────────────────────

            "CREATE TABLE {$p}bn_notifications (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                recipient_id BIGINT(20) UNSIGNED NOT NULL,
                sender_id    BIGINT(20) UNSIGNED DEFAULT NULL,
                type         VARCHAR(64) NOT NULL,
                object_type  VARCHAR(32) DEFAULT NULL,
                object_id    BIGINT(20) UNSIGNED DEFAULT NULL,
                group_key    VARCHAR(128) DEFAULT NULL,
                group_count  INT UNSIGNED NOT NULL DEFAULT 1,
                data         JSON DEFAULT NULL,
                is_read      TINYINT(1) NOT NULL DEFAULT 0,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY          bell (recipient_id, is_read, created_at),
                KEY          grouping (recipient_id, group_key)
            ) {$cs};",

            "CREATE TABLE {$p}bn_notification_prefs (
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                type       VARCHAR(64) NOT NULL,
                on_site    TINYINT(1) NOT NULL DEFAULT 1,
                email_freq ENUM('immediate','daily','weekly','off') NOT NULL DEFAULT 'immediate',
                PRIMARY KEY (user_id, type)
            ) {$cs};",

            "CREATE TABLE {$p}bn_email_templates (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                type       VARCHAR(64) NOT NULL,
                subject    VARCHAR(255) NOT NULL,
                preheader  VARCHAR(255) DEFAULT NULL,
                body       LONGTEXT NOT NULL,
                is_active  TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY  type (type)
            ) {$cs};",

            "CREATE TABLE {$p}bn_email_log (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT(20) UNSIGNED NOT NULL,
                type        VARCHAR(64) NOT NULL,
                digest_date DATE DEFAULT NULL,
                sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         user_type (user_id, type, digest_date)
            ) {$cs};",

            "CREATE TABLE {$p}bn_verify_tokens (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                token      VARCHAR(64) NOT NULL,
                type       VARCHAR(32) NOT NULL DEFAULT 'email_verify',
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY  token (token),
                KEY         user_type (user_id, type)
            ) {$cs};",

            // ── Reactions + Comments ───────────────────────────────────────────

            "CREATE TABLE {$p}bn_reactions (
                user_id     BIGINT(20) UNSIGNED NOT NULL,
                object_type VARCHAR(32) NOT NULL,
                object_id   BIGINT(20) UNSIGNED NOT NULL,
                emoji       VARCHAR(32) NOT NULL DEFAULT 'like',
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY  one_per_user (user_id, object_type, object_id),
                KEY         object_reactions (object_type, object_id)
            ) {$cs};",

            "CREATE TABLE {$p}bn_comments (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT(20) UNSIGNED NOT NULL,
                object_type VARCHAR(32) NOT NULL,
                object_id   BIGINT(20) UNSIGNED NOT NULL,
                parent_id   BIGINT(20) UNSIGNED DEFAULT NULL,
                content     TEXT NOT NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         thread (object_type, object_id, parent_id, created_at),
                KEY         user (user_id)
            ) {$cs};",

            // ── Hashtags ───────────────────────────────────────────────────────

            "CREATE TABLE {$p}bn_hashtags (
                id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name           VARCHAR(100) NOT NULL,
                slug           VARCHAR(100) NOT NULL,
                post_count     INT UNSIGNED NOT NULL DEFAULT 0,
                follower_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY    (id),
                UNIQUE KEY     slug (slug)
            ) {$cs};",

            "CREATE TABLE {$p}bn_post_hashtags (
                post_id     BIGINT(20) UNSIGNED NOT NULL,
                object_type VARCHAR(32) NOT NULL DEFAULT 'post',
                hashtag_id  BIGINT(20) UNSIGNED NOT NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id, object_type, hashtag_id),
                KEY         hashtag_feed (hashtag_id, created_at)
            ) {$cs};",

            "CREATE TABLE {$p}bn_hashtag_follows (
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                hashtag_id BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, hashtag_id),
                KEY         hashtag (hashtag_id)
            ) {$cs};",

            // ── Search ─────────────────────────────────────────────────────────

            "CREATE TABLE {$p}bn_search_index (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                object_type VARCHAR(32) NOT NULL,
                object_id   BIGINT(20) UNSIGNED NOT NULL,
                title       VARCHAR(500) NOT NULL DEFAULT '',
                content     LONGTEXT DEFAULT NULL,
                author_id   BIGINT(20) UNSIGNED DEFAULT NULL,
                visibility  ENUM('public','private') NOT NULL DEFAULT 'public',
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY  object (object_type, object_id),
                FULLTEXT KEY ft_search (title, content),
                KEY         visibility_type (visibility, object_type),
                KEY         author (author_id)
            ) {$cs};",

            // ── Roles + Permissions ────────────────────────────────────────────

            "CREATE TABLE {$p}bn_user_abilities (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                ability    VARCHAR(128) NOT NULL,
                source     VARCHAR(64) DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         user_ability (user_id, ability, expires_at)
            ) {$cs};",

            "CREATE TABLE {$p}bn_user_credits (
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                balance    INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id)
            ) {$cs};",

            "CREATE TABLE {$p}bn_webhook_log (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                source     VARCHAR(64) DEFAULT NULL,
                action     VARCHAR(64) NOT NULL,
                user_id    BIGINT(20) UNSIGNED DEFAULT NULL,
                payload    JSON DEFAULT NULL,
                status     ENUM('success','error') NOT NULL DEFAULT 'success',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         user_action (user_id, action),
                KEY         created (created_at)
            ) {$cs};",

            // ── Direct Messaging ───────────────────────────────────────────────
            // Tables ported from WPMediaVerse Pro (mvs_ → bn_ prefix).
            // Schema unchanged; group chat fields added in Phase 9 / DM Phase 3.

            "CREATE TABLE {$p}bn_conversations (
                id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                type             ENUM('direct','group') NOT NULL DEFAULT 'direct',
                title            VARCHAR(255) DEFAULT NULL,
                created_by       BIGINT(20) UNSIGNED NOT NULL,
                last_message_id  BIGINT(20) UNSIGNED DEFAULT NULL,
                last_activity_at DATETIME DEFAULT NULL,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY      (id),
                KEY              created_by (created_by),
                KEY              last_activity (last_activity_at)
            ) {$cs};",

            "CREATE TABLE {$p}bn_conversation_participants (
                conversation_id BIGINT(20) UNSIGNED NOT NULL,
                user_id         BIGINT(20) UNSIGNED NOT NULL,
                role            ENUM('admin','member') NOT NULL DEFAULT 'member',
                last_read_at    DATETIME DEFAULT NULL,
                is_muted        TINYINT(1) NOT NULL DEFAULT 0,
                muted_until     DATETIME DEFAULT NULL,
                is_pinned       TINYINT(1) NOT NULL DEFAULT 0,
                is_archived     TINYINT(1) NOT NULL DEFAULT 0,
                status          ENUM('active','left','removed') NOT NULL DEFAULT 'active',
                joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY     (conversation_id, user_id),
                KEY             user_conversations (user_id, status, last_read_at)
            ) {$cs};",

            "CREATE TABLE {$p}bn_messages (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT(20) UNSIGNED NOT NULL,
                sender_id       BIGINT(20) UNSIGNED NOT NULL,
                content         TEXT DEFAULT NULL,
                message_type    ENUM('text','media','system') NOT NULL DEFAULT 'text',
                attachment_id   BIGINT(20) UNSIGNED DEFAULT NULL,
                media_id        BIGINT(20) UNSIGNED DEFAULT NULL,
                parent_id       BIGINT(20) UNSIGNED DEFAULT NULL,
                metadata        JSON DEFAULT NULL,
                is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
                deleted_for_all TINYINT(1) NOT NULL DEFAULT 0,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY     (id),
                KEY             conversation (conversation_id, created_at),
                KEY             sender (sender_id)
            ) {$cs};",

            "CREATE TABLE {$p}bn_message_reactions (
                message_id BIGINT(20) UNSIGNED NOT NULL,
                user_id    BIGINT(20) UNSIGNED NOT NULL,
                emoji      VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (message_id, user_id)
            ) {$cs};",

            // ── Activity Log ───────────────────────────────────────────────────

            "CREATE TABLE {$p}bn_activity_log (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT(20) UNSIGNED NOT NULL,
                action      VARCHAR(64) NOT NULL,
                object_type VARCHAR(32) DEFAULT NULL,
                object_id   BIGINT(20) UNSIGNED DEFAULT NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY         user_action (user_id, action, created_at)
            ) {$cs};",

        ];
    }
}
```

- [ ] **Step 4: Run — confirm 28 PASS**

```bash
vendor/bin/phpunit tests/Core/InstallerTest.php --testdox
```

Expected: 28 data-driven table assertions pass.

- [ ] **Step 5: Commit**

```bash
git add includes/Core/Installer.php tests/Core/InstallerTest.php
git commit -m "feat: add installer with all 28 bn_* tables and indexes"
```

---

## Task 4: Abilities API Registration

**Files:**
- Create: `includes/Core/Abilities.php`
- Create: `tests/Core/AbilitiesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Core/AbilitiesTest.php

class AbilitiesTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new \BuddyNext\Core\Abilities() )->register();
    }

    /** @dataProvider ability_provider */
    public function test_ability_is_registered( string $ability ): void {
        if ( ! function_exists( 'wp_is_registered_ability' ) ) {
            $this->markTestSkipped( 'WordPress Abilities API requires WP 6.9+' );
        }
        $this->assertTrue( wp_is_registered_ability( $ability ) );
    }

    public static function ability_provider(): array {
        return array_map( fn( $a ) => [ $a ], [
            'buddynext-profile/edit-own',
            'buddynext-profile/edit-any',
            'buddynext-profile/view',
            'buddynext-feed/create-post',
            'buddynext-feed/delete-own-post',
            'buddynext-feed/delete-any-post',
            'buddynext-feed/pin-post',
            'buddynext-feed/schedule-post',
            'buddynext-spaces/create',
            'buddynext-spaces/join',
            'buddynext-spaces/join-gated',
            'buddynext-spaces/post',
            'buddynext-spaces/moderate',
            'buddynext-spaces/manage-settings',
            'buddynext-spaces/delete',
            'buddynext-connections/follow',
            'buddynext-connections/connect',
            'buddynext-moderation/report',
            'buddynext-moderation/review-queue',
            'buddynext-moderation/issue-strike',
            'buddynext-moderation/suspend-user',
        ] );
    }
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
vendor/bin/phpunit tests/Core/AbilitiesTest.php --testdox
```

- [ ] **Step 3: Create `includes/Core/Abilities.php`**

```php
<?php
namespace BuddyNext\Core;

class Abilities {

    private const CATALOG = [
        'buddynext-profile/edit-own',
        'buddynext-profile/edit-any',
        'buddynext-profile/view',
        'buddynext-feed/create-post',
        'buddynext-feed/delete-own-post',
        'buddynext-feed/delete-any-post',
        'buddynext-feed/pin-post',
        'buddynext-feed/schedule-post',
        'buddynext-spaces/create',
        'buddynext-spaces/join',
        'buddynext-spaces/join-gated',
        'buddynext-spaces/post',
        'buddynext-spaces/moderate',
        'buddynext-spaces/manage-settings',
        'buddynext-spaces/delete',
        'buddynext-connections/follow',
        'buddynext-connections/connect',
        'buddynext-moderation/report',
        'buddynext-moderation/review-queue',
        'buddynext-moderation/issue-strike',
        'buddynext-moderation/suspend-user',
    ];

    public function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return; // WP 6.9+ only — graceful degradation on older installs.
        }
        foreach ( self::CATALOG as $ability ) {
            wp_register_ability( $ability, [ 'plugin' => 'buddynext' ] );
        }
    }
}
```

- [ ] **Step 4: Run — confirm PASS (or skipped on WP < 6.9)**

```bash
vendor/bin/phpunit tests/Core/AbilitiesTest.php --testdox
```

- [ ] **Step 5: Commit**

```bash
git add includes/Core/Abilities.php tests/Core/AbilitiesTest.php
git commit -m "feat: register all buddynext-* abilities via WordPress Abilities API"
```

---

## Task 5: Permission Service — 4-Layer Model

**Files:**
- Create: `includes/Core/PermissionService.php`
- Create: `tests/Core/PermissionServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Core/PermissionServiceTest.php

class PermissionServiceTest extends WP_UnitTestCase {

    private \BuddyNext\Core\PermissionService $service;
    private int $admin_id;
    private int $member_id;

    public function set_up(): void {
        parent::set_up();
        \BuddyNext\Core\Installer::run();
        $this->service   = new \BuddyNext\Core\PermissionService();
        $this->admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->member_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        update_user_meta( $this->admin_id,  'bn_community_role', 'admin' );
        update_user_meta( $this->member_id, 'bn_community_role', 'member' );
    }

    // Layer 1: WP admin bypass
    public function test_wp_admin_passes_any_check(): void {
        $this->assertTrue( $this->service->can( $this->admin_id, 'buddynext-moderation/suspend-user' ) );
    }

    // Layer 2: community role
    public function test_member_can_create_post(): void {
        $this->assertTrue( $this->service->can( $this->member_id, 'buddynext-feed/create-post' ) );
    }

    public function test_member_cannot_suspend_user(): void {
        $this->assertFalse( $this->service->can( $this->member_id, 'buddynext-moderation/suspend-user' ) );
    }

    public function test_member_cannot_review_moderation_queue(): void {
        $this->assertFalse( $this->service->can( $this->member_id, 'buddynext-moderation/review-queue' ) );
    }

    // Layer 3: granted ability (additive, with expiry)
    public function test_granted_ability_allows_gated_space_join(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}bn_user_abilities", [
            'user_id'    => $this->member_id,
            'ability'    => 'buddynext-spaces/join-gated',
            'source'     => 'phpunit',
            'expires_at' => null,
        ] );
        $this->assertTrue( $this->service->can( $this->member_id, 'buddynext-spaces/join-gated' ) );
    }

    public function test_expired_ability_is_denied(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}bn_user_abilities", [
            'user_id'    => $this->member_id,
            'ability'    => 'buddynext-spaces/join-gated',
            'source'     => 'phpunit',
            'expires_at' => '2020-01-01 00:00:00',
        ] );
        $this->assertFalse( $this->service->can( $this->member_id, 'buddynext-spaces/join-gated' ) );
    }

    // Layer 4: developer filter override
    public function test_filter_can_deny_any_capability(): void {
        add_filter( 'buddynext_user_can', '__return_false', 10, 4 );
        $this->assertFalse( $this->service->can( $this->member_id, 'buddynext-feed/create-post' ) );
        remove_all_filters( 'buddynext_user_can' );
    }

    public function test_filter_can_grant_any_capability(): void {
        add_filter( 'buddynext_user_can', '__return_true', 10, 4 );
        $this->assertTrue( $this->service->can( $this->member_id, 'buddynext-moderation/suspend-user' ) );
        remove_all_filters( 'buddynext_user_can' );
    }
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
vendor/bin/phpunit tests/Core/PermissionServiceTest.php --testdox
```

- [ ] **Step 3: Create `includes/Core/PermissionService.php`**

```php
<?php
namespace BuddyNext\Core;

class PermissionService {

    // Minimum community role required per capability.
    // null = no role default (requires an explicit ability grant).
    private const ROLE_MAP = [
        'buddynext-profile/edit-own'        => 'member',
        'buddynext-profile/edit-any'        => 'admin',
        'buddynext-profile/view'            => null,
        'buddynext-feed/create-post'        => 'member',
        'buddynext-feed/delete-own-post'    => 'member',
        'buddynext-feed/delete-any-post'    => 'moderator',
        'buddynext-feed/pin-post'           => 'moderator',
        'buddynext-feed/schedule-post'      => 'member',
        'buddynext-spaces/create'           => 'member',
        'buddynext-spaces/join'             => 'member',
        'buddynext-spaces/join-gated'       => null,
        'buddynext-spaces/post'             => 'member',
        'buddynext-spaces/moderate'         => 'moderator',
        'buddynext-spaces/manage-settings'  => 'moderator',
        'buddynext-spaces/delete'           => 'moderator',
        'buddynext-connections/follow'      => 'member',
        'buddynext-connections/connect'     => 'member',
        'buddynext-moderation/report'       => 'member',
        'buddynext-moderation/review-queue' => 'moderator',
        'buddynext-moderation/issue-strike' => 'moderator',
        'buddynext-moderation/suspend-user' => 'admin',
    ];

    private const ROLE_HIERARCHY = [ 'admin' => 3, 'moderator' => 2, 'member' => 1 ];

    public function can( int $user_id, string $capability, array $context = [] ): bool {
        // Layer 1: WP site admin bypass.
        $user = get_userdata( $user_id );
        if ( $user && $user->has_cap( 'manage_options' ) ) {
            $result = true;
        } else {
            // Layer 2: community role.
            $result = $this->check_role( $user_id, $capability );

            // Layer 3: explicit ability grant (additive — unlocks null-default caps).
            if ( ! $result ) {
                $result = $this->has_granted_ability( $user_id, $capability );
            }
        }

        // Layer 4: developer filter — full override in both directions.
        return (bool) apply_filters( 'buddynext_user_can', $result, $user_id, $capability, $context );
    }

    private function check_role( int $user_id, string $capability ): bool {
        $required = self::ROLE_MAP[ $capability ] ?? null;
        if ( null === $required ) {
            return false; // must be granted explicitly
        }
        $user_role  = get_user_meta( $user_id, 'bn_community_role', true ) ?: 'member';
        $user_level = self::ROLE_HIERARCHY[ $user_role ]    ?? 1;
        $req_level  = self::ROLE_HIERARCHY[ $required ]     ?? 1;
        return $user_level >= $req_level;
    }

    private function has_granted_ability( int $user_id, string $ability ): bool {
        global $wpdb;
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_abilities
             WHERE user_id = %d AND ability = %s
             AND ( expires_at IS NULL OR expires_at > NOW() )",
            $user_id,
            $ability
        ) );
        return (int) $count > 0;
    }
}
```

- [ ] **Step 4: Run — confirm 7 PASS**

```bash
vendor/bin/phpunit tests/Core/PermissionServiceTest.php --testdox
```

- [ ] **Step 5: Commit**

```bash
git add includes/Core/PermissionService.php tests/Core/PermissionServiceTest.php
git commit -m "feat: add 4-layer buddynext_can() permission service"
```

---

## Task 6: REST Router + Webhook Access Endpoint

**Files:**
- Create: `includes/REST/Router.php`
- Create: `includes/REST/Controllers/AccessWebhookController.php`
- Create: `tests/REST/AccessWebhookTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/REST/AccessWebhookTest.php

class AccessWebhookTest extends WP_Test_REST_TestCase {

    private int    $user_id;
    private string $secret = 'phpunit-webhook-secret';

    public function set_up(): void {
        parent::set_up();
        \BuddyNext\Core\Installer::run();
        update_option( 'buddynext_webhook_secret', $this->secret );
        $this->user_id = self::factory()->user->create();
        update_user_meta( $this->user_id, 'bn_community_role', 'member' );
        ( new \BuddyNext\REST\Router() )->register();
    }

    private function call( array $body, bool $valid_sig = true ): \WP_REST_Response {
        $payload = wp_json_encode( $body );
        $sig     = $valid_sig
            ? 'sha256=' . hash_hmac( 'sha256', $payload, $this->secret )
            : 'sha256=bad';
        $request = new \WP_REST_Request( 'POST', '/buddynext/v1/webhook/access' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_header( 'X-BuddyNext-Signature', $sig );
        $request->set_body( $payload );
        return rest_do_request( $request );
    }

    public function test_invalid_signature_returns_401(): void {
        $r = $this->call( [ 'user_id' => $this->user_id, 'action' => 'set_role', 'role' => 'admin' ], false );
        $this->assertSame( 401, $r->get_status() );
    }

    public function test_set_role(): void {
        $r = $this->call( [ 'user_id' => $this->user_id, 'action' => 'set_role', 'role' => 'moderator', 'source' => 'test' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 'moderator', get_user_meta( $this->user_id, 'bn_community_role', true ) );
    }

    public function test_grant_and_revoke_ability(): void {
        $this->call( [ 'user_id' => $this->user_id, 'action' => 'grant_ability', 'ability' => 'buddynext-spaces/join-gated', 'source' => 'test' ] );
        $s = new \BuddyNext\Core\PermissionService();
        $this->assertTrue( $s->can( $this->user_id, 'buddynext-spaces/join-gated' ) );

        $this->call( [ 'user_id' => $this->user_id, 'action' => 'revoke_ability', 'ability' => 'buddynext-spaces/join-gated', 'source' => 'test' ] );
        $this->assertFalse( $s->can( $this->user_id, 'buddynext-spaces/join-gated' ) );
    }

    public function test_add_credits(): void {
        $this->call( [ 'user_id' => $this->user_id, 'action' => 'add_credits', 'amount' => 50, 'source' => 'test' ] );
        global $wpdb;
        $balance = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT balance FROM {$wpdb->prefix}bn_user_credits WHERE user_id = %d",
            $this->user_id
        ) );
        $this->assertSame( 50, $balance );
    }

    public function test_set_credits(): void {
        $this->call( [ 'user_id' => $this->user_id, 'action' => 'add_credits', 'amount' => 100, 'source' => 'test' ] );
        $this->call( [ 'user_id' => $this->user_id, 'action' => 'set_credits', 'amount' => 25,  'source' => 'test' ] );
        global $wpdb;
        $balance = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT balance FROM {$wpdb->prefix}bn_user_credits WHERE user_id = %d",
            $this->user_id
        ) );
        $this->assertSame( 25, $balance );
    }

    public function test_every_call_is_logged(): void {
        $this->call( [ 'user_id' => $this->user_id, 'action' => 'set_role', 'role' => 'member', 'source' => 'test' ] );
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_webhook_log WHERE action = 'set_role'" );
        $this->assertGreaterThan( 0, $count );
    }
}
```

- [ ] **Step 2: Run — confirm FAIL**

```bash
vendor/bin/phpunit tests/REST/AccessWebhookTest.php --testdox
```

- [ ] **Step 3: Create `includes/REST/Router.php`**

```php
<?php
namespace BuddyNext\REST;

class Router {
    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }
    public function register_routes(): void {
        ( new Controllers\AccessWebhookController() )->register_routes();
    }
}
```

- [ ] **Step 4: Create `includes/REST/Controllers/AccessWebhookController.php`**

```php
<?php
namespace BuddyNext\REST\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class AccessWebhookController {

    public function register_routes(): void {
        register_rest_route( 'buddynext/v1', '/webhook/access', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true', // signature verified inside handle()
        ] );
    }

    public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        if ( ! $this->verify_signature( $request ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid webhook signature.', [ 'status' => 401 ] );
        }

        $body = json_decode( $request->get_body(), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'invalid_payload', 'Payload must be valid JSON.', [ 'status' => 400 ] );
        }

        $user = $this->resolve_user( $body );
        if ( ! $user ) {
            return new WP_Error( 'user_not_found', 'User not found.', [ 'status' => 404 ] );
        }

        $action = sanitize_key( $body['action'] ?? '' );
        $result = $this->dispatch( $action, $user->ID, $body );
        $status = $result instanceof WP_Error ? 'error' : 'success';

        $this->log( $action, $user->ID, $body, $status );

        return $result instanceof WP_Error
            ? $result
            : new WP_REST_Response( [ 'success' => true ], 200 );
    }

    private function dispatch( string $action, int $user_id, array $body ): true|WP_Error {
        return match ( $action ) {
            'set_role'       => $this->action_set_role( $user_id, $body ),
            'grant_ability'  => $this->action_grant_ability( $user_id, $body ),
            'revoke_ability' => $this->action_revoke_ability( $user_id, $body ),
            'add_credits'    => $this->action_add_credits( $user_id, $body ),
            'set_credits'    => $this->action_set_credits( $user_id, $body ),
            'deduct_credits' => $this->action_deduct_credits( $user_id, $body ),
            default          => new WP_Error( 'unknown_action', "Unknown action: '{$action}'.", [ 'status' => 400 ] ),
        };
    }

    private function action_set_role( int $user_id, array $body ): true|WP_Error {
        $role = sanitize_key( $body['role'] ?? '' );
        if ( ! in_array( $role, [ 'admin', 'moderator', 'member' ], true ) ) {
            return new WP_Error( 'invalid_role', 'Role must be admin, moderator, or member.', [ 'status' => 400 ] );
        }
        update_user_meta( $user_id, 'bn_community_role', $role );
        do_action( 'buddynext_role_changed', $user_id, $role );
        return true;
    }

    private function action_grant_ability( int $user_id, array $body ): true|WP_Error {
        global $wpdb;
        $ability    = sanitize_text_field( $body['ability'] ?? '' );
        $expires_at = ! empty( $body['expires_at'] ) ? sanitize_text_field( $body['expires_at'] ) : null;
        $source     = sanitize_key( $body['source'] ?? '' );
        if ( ! $ability ) {
            return new WP_Error( 'missing_ability', "'ability' is required.", [ 'status' => 400 ] );
        }
        $wpdb->insert( "{$wpdb->prefix}bn_user_abilities", compact( 'user_id', 'ability', 'source', 'expires_at' ) );
        wp_cache_delete( "bn_abilities_{$user_id}" );
        do_action( 'buddynext_ability_granted', $user_id, $ability );
        return true;
    }

    private function action_revoke_ability( int $user_id, array $body ): true|WP_Error {
        global $wpdb;
        $ability = sanitize_text_field( $body['ability'] ?? '' );
        if ( ! $ability ) {
            return new WP_Error( 'missing_ability', "'ability' is required.", [ 'status' => 400 ] );
        }
        $wpdb->delete( "{$wpdb->prefix}bn_user_abilities", compact( 'user_id', 'ability' ) );
        wp_cache_delete( "bn_abilities_{$user_id}" );
        do_action( 'buddynext_ability_revoked', $user_id, $ability );
        return true;
    }

    private function action_add_credits( int $user_id, array $body ): true|WP_Error {
        global $wpdb;
        $amount = abs( (int) ( $body['amount'] ?? 0 ) );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}bn_user_credits (user_id, balance) VALUES (%d, %d)
             ON DUPLICATE KEY UPDATE balance = balance + %d",
            $user_id, $amount, $amount
        ) );
        return true;
    }

    private function action_set_credits( int $user_id, array $body ): true|WP_Error {
        global $wpdb;
        $amount = max( 0, (int) ( $body['amount'] ?? 0 ) );
        $wpdb->replace( "{$wpdb->prefix}bn_user_credits", [ 'user_id' => $user_id, 'balance' => $amount ] );
        return true;
    }

    private function action_deduct_credits( int $user_id, array $body ): true|WP_Error {
        global $wpdb;
        $amount = abs( (int) ( $body['amount'] ?? 0 ) );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}bn_user_credits (user_id, balance) VALUES (%d, 0)
             ON DUPLICATE KEY UPDATE balance = GREATEST(0, balance - %d)",
            $user_id, $amount
        ) );
        return true;
    }

    private function resolve_user( array $body ): \WP_User|false {
        if ( ! empty( $body['user_id'] ) ) {
            return get_userdata( (int) $body['user_id'] );
        }
        if ( ! empty( $body['user_email'] ) ) {
            return get_user_by( 'email', sanitize_email( $body['user_email'] ) );
        }
        return false;
    }

    private function verify_signature( WP_REST_Request $request ): bool {
        $secret  = get_option( 'buddynext_webhook_secret', '' );
        $header  = $request->get_header( 'X-BuddyNext-Signature' ) ?? '';
        $expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
        return hash_equals( $expected, $header );
    }

    private function log( string $action, int $user_id, array $body, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}bn_webhook_log", [
            'source'   => sanitize_key( $body['source'] ?? '' ),
            'action'   => $action,
            'user_id'  => $user_id,
            'payload'  => wp_json_encode( $body ),
            'status'   => $status,
        ] );
    }
}
```

- [ ] **Step 5: Run — confirm 6 PASS**

```bash
vendor/bin/phpunit tests/REST/AccessWebhookTest.php --testdox
```

- [ ] **Step 6: Commit**

```bash
git add includes/REST/ tests/REST/
git commit -m "feat: add REST router and POST buddynext/v1/webhook/access endpoint"
```

---

## Task 7: Admin Settings Skeleton

**Files:**
- Create: `includes/Admin/Settings.php`

No unit tests — UI only. Manual browser verification.

- [ ] **Step 1: Create `includes/Admin/Settings.php`**

```php
<?php
namespace BuddyNext\Admin;

class Settings {

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_fields' ] );
    }

    public function add_menu(): void {
        add_menu_page(
            __( 'BuddyNext', 'buddynext' ),
            __( 'BuddyNext', 'buddynext' ),
            'manage_options',
            'buddynext',
            [ $this, 'render_page' ],
            'dashicons-groups',
            30
        );
    }

    public function register_fields(): void {
        register_setting( 'buddynext_options', 'buddynext_webhook_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        add_settings_section( 'buddynext_webhooks', __( 'Webhooks', 'buddynext' ), '__return_false', 'buddynext' );
        add_settings_field(
            'buddynext_webhook_secret',
            __( 'Webhook Secret Key', 'buddynext' ),
            function () {
                $val = esc_attr( get_option( 'buddynext_webhook_secret', '' ) );
                printf( '<input type="password" name="buddynext_webhook_secret" value="%s" class="regular-text">', $val );
                echo '<p class="description">'
                    . esc_html__( 'Used to verify incoming POST requests to buddynext/v1/webhook/access. Must match the secret configured in your external system.', 'buddynext' )
                    . '</p>';
            },
            'buddynext',
            'buddynext_webhooks'
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__( 'BuddyNext Settings', 'buddynext' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'buddynext_options' );
        do_settings_sections( 'buddynext' );
        submit_button();
        echo '</form></div>';
    }
}
```

- [ ] **Step 2: Activate plugin and verify in browser**

```bash
wp --path="/Users/varundubey/Local Sites/forums/app/public" plugin activate buddynext
```

Then visit: `http://forums.local/wp-admin/?autologin=1`

Expected: **BuddyNext** menu item in sidebar → Settings page shows Webhooks section with secret key field.

- [ ] **Step 3: Verify all 28 tables created**

```bash
wp --path="/Users/varundubey/Local Sites/forums/app/public" db tables --all-tables | grep bn_
```

Expected: 28 `wp_bn_*` table names printed.

- [ ] **Step 4: Commit**

```bash
git add includes/Admin/Settings.php
git commit -m "feat: add admin settings skeleton with webhook secret key field"
```

---

## Task 8: Test Bootstrap + Full Suite Green

**Files:**
- Create: `tests/bootstrap.php`
- Create: `bin/install-wp-tests.sh`

- [ ] **Step 1: Create `tests/bootstrap.php`**

```php
<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "WP_TESTS_DIR not found: {$_tests_dir}\n";
    echo "Run: bash bin/install-wp-tests.sh buddynext_test root root localhost\n";
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__ ) . '/buddynext.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 2: Create `bin/install-wp-tests.sh`**

```bash
#!/usr/bin/env bash
set -e

DB_NAME=${1:-buddynext_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

mkdir -p "$WP_TESTS_DIR" "$WP_CORE_DIR"

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    svn co --quiet "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn co --quiet "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/data/"     "$WP_TESTS_DIR/data"
fi

if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    svn cat "https://develop.svn.wordpress.org/tags/$WP_VERSION/wp-tests-config-sample.php" \
        | sed "s|dirname( __FILE__ ) . '/src/'|'$WP_CORE_DIR/'|" \
        | sed "s|youremptytestdbnamehere|$DB_NAME|" \
        | sed "s|yourusernamehere|$DB_USER|"         \
        | sed "s|yourpasswordhere|$DB_PASS|"         \
        | sed "s|localhost|$DB_HOST|"                \
        > "$WP_TESTS_DIR/wp-tests-config.php"
fi

mysql -u"$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME;"
echo "Test database '$DB_NAME' created."
```

- [ ] **Step 3: Install WP test suite**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext"
chmod +x bin/install-wp-tests.sh
bash bin/install-wp-tests.sh buddynext_test root root localhost latest
```

- [ ] **Step 4: Run full test suite**

```bash
vendor/bin/phpunit --testdox
```

Expected: All tests pass. Zero failures. Zero errors.

- [ ] **Step 5: Commit**

```bash
git add tests/bootstrap.php bin/install-wp-tests.sh
git commit -m "chore: add PHPUnit bootstrap and WP test suite installer"
```

---

## Phase 1 Acceptance Checklist

Run all of these before marking Phase 1 complete and starting Phase 2:

```bash
# 1. Plugin activates cleanly (no PHP errors, no fatal)
wp --path="/Users/varundubey/Local Sites/forums/app/public" plugin activate buddynext

# 2. All 28 bn_* tables exist
wp --path="/Users/varundubey/Local Sites/forums/app/public" db tables --all-tables | grep bn_
# Expected: 28 lines

# 3. All tests pass
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext"
vendor/bin/phpunit --testdox
# Expected: 0 failures, 0 errors

# 4. Version constant defined
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo BUDDYNEXT_VERSION . PHP_EOL;'
# Expected: 0.1.0

# 5. REST namespace registered
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'print_r(rest_get_server()->get_namespaces());'
# Expected: buddynext/v1 in the output

# 6. Webhook endpoint accessible
curl -s -o /dev/null -w "%{http_code}" \
  -X POST http://forums.local/wp-json/buddynext/v1/webhook/access \
  -H "Content-Type: application/json" \
  -H "X-BuddyNext-Signature: sha256=invalid" \
  -d '{"user_id":1,"action":"set_role","role":"member"}'
# Expected: 401

# 7. Admin settings page visible
# Visit http://forums.local/wp-admin/?autologin=1 → BuddyNext in sidebar → Webhooks section
```

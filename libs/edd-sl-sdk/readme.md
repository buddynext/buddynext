# EDD Software Licensing SDK

A drop-in solution for WordPress plugin and theme developers to quickly integrate Easy Digital Downloads Software Licensing into their products without complex setup or custom admin interfaces.

## Overview

The EDD Software Licensing SDK streamlines the process of adding licensing functionality to your WordPress plugins and themes. Instead of building custom settings pages and handling license validation manually, this SDK provides a complete licensing solution that seamlessly integrates with existing WordPress admin interfaces.

### Key Features

- **Zero-configuration licensing** - Add licensing support with just a few lines of code
- **Native WordPress integration** - License fields appear directly in plugin action links and theme admin menus
- **Automatic updates** - Handles secure update delivery for licensed products
- **No custom admin pages** - Uses WordPress's existing interface patterns
- **Flexible deployment** - Works as a standalone plugin or Composer package
- **Developer-friendly** - Minimal code required, maximum functionality provided

### How It Works

For **plugins**, the SDK adds a "Manage License" link directly in the plugin list on the Plugins admin screen. Clicking this link opens a modal where users can enter and activate their license key.

For **themes**, a "Theme License" menu item is automatically added to the Appearance menu, providing easy access to license management via the modal.

The SDK handles all the complex licensing logic behind the scenes:
- License key validation and activation
- Automatic update notifications and delivery
- License status tracking and renewal reminders
- Secure communication with your EDD store

### Perfect For

- Plugin developers who want to focus on features, not licensing infrastructure
- Theme authors looking for a professional licensing solution
- Developers transitioning from other licensing systems
- Anyone who wants licensing integration without reinventing the wheel

## Installation

You can run the SDK as a standalone plugin on your site, or install it as a Composer package in your theme or plugin:

```json
{
  "name": "edd/edd-sample-plugin",
  "license": "GPL-2.0-or-later",
  "repositories": {
    "edd-sl-sdk": {
      "type": "vcs",
      "url": "https://github.com/awesomemotive/edd-sl-sdk"
    }
  },
  "require": {
    "easy-digital-downloads/edd-sl-sdk": "^1.0.2"
  }
}
```

### Example Usage

Plugin:
```php
add_action(
	'edd_sl_sdk_registry',
	function ( $init ) {
		$init->register(
			array(
				'id'      => 'edd-sample-plugin', // The plugin slug.
				'url'     => 'https://edd.test', // The URL of the site with EDD installed.
				'item_id' => 83, // The download ID of the product in Easy Digital Downloads.
				'version' => '1.0.0', // The version of the product.
				'file'    => __FILE__, // The path to the main plugin file.
			)
		);
	}
);

// Load the SDK from the vendor directory. The SDK handles autoloader setup automatically.
if ( file_exists( __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' ) ) {
	require_once __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}
```

Theme:
```php
add_action(
	'edd_sl_sdk_registry',
	function ( $init ) {
		$init->register(
			array(
				'id'      => 'edd-sample-theme',
				'url'     => 'https://easydigitaldownloads.com',
				'item_id' => 123,
				'version' => '1.0.0',
				'type'    => 'theme',
			)
		);
	}
);

// Load the SDK from the vendor directory. The SDK handles autoloader setup automatically.
if ( file_exists( __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' ) ) {
	require_once __DIR__ . '/vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}
```

### Arguments

- `id` - Plugin/theme slug.
- `url` - The store URL.
- `item_id` - The item ID (on your store).
- `version` - The current version number.
- `file` - The main plugin file. Not needed for themes.
- `type` - `plugin` or `theme`. Not needed for plugins.
- `weekly_check` - Optional: whether to make a weekly request to confirm the license status. Defaults to true.
- `messenger_class` - Optional: custom messenger class for translations. Must extend `EasyDigitalDownloads\Updater\Messenger`.

## Admin Notices

The SDK includes a `Notices` class for displaying admin notices. The registry automatically handles instantiation, so you can use the static `add()` method directly.

### Adding Notices

You can add notices statically from anywhere in your code before the `admin_notices` hook fires at priority 100:

```php
use EasyDigitalDownloads\Updater\Admin\Notices;

// Add notice using admin_notices hook with translation-ready strings
add_action( 'admin_notices', function() {
    Notices::add( array(
        'id'      => 'my-plugin-license-activated',
        'type'    => 'success', // 'success', 'error', 'warning', 'info'
        'message' => __( 'Your license has been activated successfully!', 'my-plugin-textdomain' ),
        'classes' => array( 'my-custom-class' ) // Optional additional CSS classes
    ) );
}, 10 ); // Priority 10 runs before our render at priority 100
```

The notices will be automatically displayed on admin pages. The registry takes care of instantiating the `Notices` class, and the `Notices` class handles rendering and styling according to WordPress admin notice standards.

## Customizing Translations

The SDK provides flexible translation options to allow plugin and theme developers to use their own text domains and customize user-facing messages.

### Using a Custom Messenger Class

Create a custom messenger class that extends the base `Messenger` class to override any translatable strings:

```php
<?php
namespace MyPlugin;

use EasyDigitalDownloads\Updater\Messenger;

class MyPluginMessenger extends Messenger {
	/**
	 * The text domain for your plugin.
	 *
	 * @var string
	 */
	protected $text_domain = 'my-plugin';

	/**
	 * Override the activate button label.
	 *
	 * @return string
	 */
	public function get_activate_button_label() {
		$label = __( 'Activate License', $this->text_domain );
		return $this->filter_string( $label, 'activate_button' );
	}

	/**
	 * Override the license expired message.
	 *
	 * @param string $date The expiration date.
	 * @return string
	 */
	public function get_license_expired_message( $date ) {
		$message = sprintf(
			__( 'Your license expired on %s. Renew now to continue receiving updates!', $this->text_domain ),
			$date
		);
		return $this->filter_string( $message, 'license_expired' );
	}
}
```

Then register your plugin with the custom messenger:

```php
add_action(
	'edd_sl_sdk_registry',
	function ( $registry ) {
		$registry->register(
			array(
				'id'              => 'my-plugin',
				'url'             => 'https://example.com',
				'item_id'         => 123,
				'version'         => '1.0.0',
				'file'            => __FILE__,
				'messenger_class' => MyPlugin\MyPluginMessenger::class,
			)
		);
	}
);
```

### Available Messenger Methods

You can override any of these methods in your custom messenger class:

**License Status Messages:**
- `get_license_expired_message( $date )`
- `get_license_disabled_message()`
- `get_license_missing_message()`
- `get_license_site_inactive_message()`
- `get_license_invalid_for_item_message( $item_name )`
- `get_license_invalid_message()`
- `get_license_no_activations_message()`
- `get_license_bundle_message()`
- `get_license_deactivated_message()`
- `get_license_unlicensed_message()`
- `get_license_lifetime_message()`
- `get_license_expires_soon_message( $date )`
- `get_license_expires_message( $date )`
- `get_unknown_date_message()`

**Button Labels:**
- `get_activate_button_label()`
- `get_deactivate_button_label()`
- `get_delete_button_label()`

**AJAX Messages:**
- `get_permission_denied_message()`
- `get_permission_denied_setting_message()`
- `get_activation_error_message()`
- `get_activation_success_message()`
- `get_deactivation_error_message()`
- `get_deactivation_success_message()`
- `get_deletion_success_message()`
- `get_tracking_enabled_message()`
- `get_tracking_disabled_message()`

**JavaScript Localization:**
- `get_activating_text()`
- `get_deactivating_text()`
- `get_unknown_error_text()`
- `get_dismiss_notice_text()`

**UI Labels:**
- `get_license_key_label( $name )`
- `get_data_tracking_label()`
- `get_theme_license_menu_label()`
- `get_manage_license_link_label()`

**Update Notifications:**
- `get_new_version_available_message( $plugin_name )`
- `get_contact_admin_message()`
- `get_view_details_link( $version )`
- `get_view_details_or_update_link( $version, $changelog_link, $update_link, $file )`
- `get_update_now_text()`

### Using the Translation Filter Hook

For fine-grained control without creating a custom class, use the `edd_sl_sdk_translate_string` filter:

```php
add_filter( 'edd_sl_sdk_translate_string', function( $string, $key, $text_domain ) {
	// Don't translate others' strings.
	if ( 'my-text-domain' !== $text_domain ) {
		return $string;
	}

	// Customize specific strings by their key
	if ( 'activate_button' === $key ) {
		return __( 'Enable License', 'my-plugin' );
	}

	if ( 'license_expired' === $key ) {
		return __( 'Your license has expired. Please renew!', 'my-plugin' );
	}

	return $string;
}, 10, 3 );
```

The filter receives:
- `$string` - The translated string
- `$key` - The unique identifier for the string
- `$text_domain` - The text domain being used (default: 'edd-sl-sdk')

### Translation Best Practices

1. **Always call `filter_string()`** - When overriding messenger methods, always call `$this->filter_string( $message, $key )` before returning to allow filter-based customization.

2. **Maintain consistent keys** - Use the same key names as the base class when calling `filter_string()` to ensure compatibility.

3. **Set your text domain** - Override the `$text_domain` property in your custom messenger class.

4. **Test all states** - Make sure to test all license states (expired, invalid, active, etc.) to ensure your custom messages display correctly.

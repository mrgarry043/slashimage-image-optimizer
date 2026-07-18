<?php
/**
 * Plugin Name:       SlashImage - Image Optimizer
 * Description:       Compress images and serve modern formats (WebP, AVIF) automatically.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            SlashImage
 * Author URI:        https://slashimage.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slashimage-image-optimizer
 * Domain Path:       /languages
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SLASH_IMAGE_VERSION', '1.1.0' );
define( 'SLASH_IMAGE_FILE', __FILE__ );
define( 'SLASH_IMAGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SLASH_IMAGE_URL', plugin_dir_url( __FILE__ ) );
define( 'SLASH_IMAGE_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'SLASH_IMAGE_API_BASE_URL' ) ) {
	define( 'SLASH_IMAGE_API_BASE_URL', 'https://api.slashimage.com' );
}

// Public website + account dashboard URLs for the plugin's user-facing links
// (single source of truth — never hardcode these in views/notices). Distinct
// from SLASH_IMAGE_API_BASE_URL, which is the API host the plugin *requests*.
if ( ! defined( 'SLASH_IMAGE_SITE_URL' ) ) {
	define( 'SLASH_IMAGE_SITE_URL', 'https://slashimage.com' );
}
if ( ! defined( 'SLASH_IMAGE_DASHBOARD_URL' ) ) {
	// Account / API-key actions land here and funnel through sign-in.
	define( 'SLASH_IMAGE_DASHBOARD_URL', SLASH_IMAGE_SITE_URL . '/dashboard' );
}

require_once SLASH_IMAGE_PATH . 'includes/class-slash-image-autoloader.php';
Slash_Image_Autoloader::register();

register_activation_hook( __FILE__, array( 'Slash_Image_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Slash_Image_Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Slash_Image::instance();
	}
);

/**
 * Plugins-list-page row links: Settings | Bulk Optimize | Upgrade.
 * The core "Deactivate" link is added by WordPress and we don't touch it.
 */
add_filter(
	'plugin_action_links_' . SLASH_IMAGE_BASENAME,
	static function ( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$ours = array(
			'settings' => '<a href="' . esc_url( admin_url( 'upload.php?page=slash-image-settings#dashboard' ) ) . '">'
				. esc_html__( 'Settings', 'slashimage-image-optimizer' )
				. '</a>',
			'bulk'     => '<a href="' . esc_url( admin_url( 'upload.php?page=slash-image-bulk' ) ) . '">'
				. esc_html__( 'Bulk Optimize', 'slashimage-image-optimizer' )
				. '</a>',
		);

		// Upgrade link: shown for free / capped / unknown plans, hidden for
		// unlimited (Pro) accounts. See Slash_Image_Connection::should_show_upgrade().
		$show_upgrade = class_exists( 'Slash_Image_Connection' )
			? Slash_Image_Connection::should_show_upgrade( Slash_Image_Connection::get_plan_cache() )
			: true;
		if ( $show_upgrade ) {
			$ours['upgrade'] = '<a href="' . esc_url( SLASH_IMAGE_DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer" style="color:#4438ca;font-weight:600;">'
				. esc_html__( 'Upgrade', 'slashimage-image-optimizer' )
				. '</a>';
		}

		return array_merge( $ours, $links );
	}
);

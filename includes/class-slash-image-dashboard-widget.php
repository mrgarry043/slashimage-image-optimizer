<?php
/**
 * WordPress dashboard widget. Single compact card showing local
 * optimization stats and a connection pill. Read-only for everyone
 * with `read` capability; action buttons only for `manage_options`.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Dashboard_Widget {

	const WIDGET_ID = 'slash_image_dashboard_widget';

	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register() {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			esc_html__( 'SlashImage', 'slashimage-image-optimizer' ),
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}
		if ( ! current_user_can( 'read' ) ) {
			return;
		}
		wp_enqueue_style(
			'slash-image-dashboard-widget',
			SLASH_IMAGE_URL . 'admin/css/dashboard-widget.css',
			array(),
			SLASH_IMAGE_VERSION
		);
	}

	public function render() {
		require SLASH_IMAGE_PATH . 'admin/views/dashboard-widget.php';
	}
}

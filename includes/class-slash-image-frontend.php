<?php
/**
 * Frontend filter wiring for <picture> rewriting. Only instantiated on
 * non-admin requests when frontend_serving_mode === 'picture' and a
 * verified API key is present.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Frontend {

	/** @var Slash_Image_Rewriter */
	private $rewriter;

	public function __construct() {
		$resolver       = new Slash_Image_Variant_Resolver();
		$this->rewriter = new Slash_Image_Rewriter(
			$resolver,
			array(
				'emit_avif' => (bool) Slash_Image_Settings::get( 'generate_avif', true ),
				'emit_webp' => (bool) Slash_Image_Settings::get( 'generate_webp', true ),
			)
		);

		// Hooks are deliberately limited to filters that fire AFTER WordPress
		// has injected srcset/sizes onto <img> tags:
		//
		//   - the_content runs at priority 100 (we sit after
		//     wp_filter_content_tags at priority 12).
		//   - wp_get_attachment_image and post_thumbnail_html receive HTML
		//     that already had attributes assembled inside
		//     wp_get_attachment_image() before the filter fires.
		//
		// We do NOT hook render_block. That filter fires at the_content
		// priority 6 (inside do_blocks), BEFORE srcset injection at
		// priority 12 — rewriting there yields single-size <source>
		// tags.
		add_filter( 'the_content', array( $this, 'filter_content' ), 100 );
		add_filter( 'post_thumbnail_html', array( $this, 'filter_content' ), 100 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_content' ), 100 );
	}

	public function filter_content( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( $this->should_skip() ) {
			return $html;
		}
		return $this->rewriter->rewrite( $html );
	}

	private function should_skip() {
		if ( is_admin() ) {
			return true; }
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true; }
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return true; }
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true; }
		return false;
	}
}

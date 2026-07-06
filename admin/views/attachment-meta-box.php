<?php
/**
 * Attachment edit-screen meta box. Four states: optimized, not-optimized,
 * not-processable, failed.
 *
 * Variables in scope (from render_meta_box):
 *   $post — the attachment WP_Post.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $post ) || ! $post instanceof WP_Post ) {
	return;
}

$slash_image_attachment_id = (int) $post->ID;
$slash_image_mime          = (string) get_post_mime_type( $slash_image_attachment_id );
$slash_image_state         = Slash_Image_Media_Library::status_for_attachment( $slash_image_attachment_id );
$slash_image_data          = get_post_meta( $slash_image_attachment_id, Slash_Image_Media_Handler::META_DATA_KEY, true );
$slash_image_backup        = get_post_meta( $slash_image_attachment_id, Slash_Image_Restore::BACKUP_META_KEY, true );

$slash_image_reoptimize_url = add_query_arg(
	array(
		'action'        => Slash_Image_Media_Library::ACTION_REOPTIMIZE,
		'attachment_id' => $slash_image_attachment_id,
		'_wpnonce'      => wp_create_nonce( Slash_Image_Media_Library::ACTION_REOPTIMIZE . '_' . $slash_image_attachment_id ),
	),
	admin_url( 'admin-post.php' )
);
$slash_image_restore_url    = add_query_arg(
	array(
		'action'        => Slash_Image_Media_Library::ACTION_RESTORE,
		'attachment_id' => $slash_image_attachment_id,
		'_wpnonce'      => wp_create_nonce( Slash_Image_Media_Library::ACTION_RESTORE . '_' . $slash_image_attachment_id ),
	),
	admin_url( 'admin-post.php' )
);

$slash_image_has_backup   = is_array( $slash_image_backup ) && ! empty( $slash_image_backup['sizes'] );
$slash_image_is_supported = Slash_Image_Api_Client::is_supported_mime( $slash_image_mime );

$slash_image_current_mode = ( is_array( $slash_image_data ) && ! empty( $slash_image_data['compression_mode'] ) )
	? (string) $slash_image_data['compression_mode']
	: (string) Slash_Image_Settings::get( 'compression_mode', 'lossy' );

$slash_image_reoptimize_url_for_mode = static function ( $slash_image_mode_choice ) use ( $slash_image_reoptimize_url ) {
	return add_query_arg( 'compression_mode', $slash_image_mode_choice, $slash_image_reoptimize_url );
};
?>

<div class="slash-image-mb">
	<?php if ( ! $slash_image_is_supported ) : ?>

		<p class="slash-image-mb__status slash-image-mb__status--warn">
			<span class="dashicons dashicons-info" aria-hidden="true"></span>
			<?php echo esc_html__( 'Not processable', 'slashimage-image-optimizer' ); ?>
		</p>
		<p class="slash-image-mb__help">
			<?php
			printf(
				/* translators: %s: MIME type, e.g. image/svg+xml */
				esc_html__( "SlashImage doesn't yet support %s files. Support for additional formats is planned.", 'slashimage-image-optimizer' ),
				'<code>' . esc_html( $slash_image_mime ) . '</code>'
			);
			?>
		</p>

	<?php elseif ( Slash_Image_Media_Library::STATUS_ERROR === $slash_image_state['kind'] ) : ?>

		<p class="slash-image-mb__status slash-image-mb__status--err">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<?php echo esc_html__( 'Failed', 'slashimage-image-optimizer' ); ?>
		</p>
		<p class="slash-image-mb__help">
			<?php echo esc_html( ! empty( $slash_image_state['message'] ) ? $slash_image_state['message'] : __( 'Optimization failed.', 'slashimage-image-optimizer' ) ); ?>
		</p>
		<?php if ( ! empty( $slash_image_state['upgrade_hint'] ) ) : ?>
			<p class="slash-image-mb__upgrade">
				<a href="<?php echo esc_url( SLASH_IMAGE_DASHBOARD_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html__( 'Upgrade for higher size limits →', 'slashimage-image-optimizer' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<p class="slash-image-mb__actions">
			<a class="button button-primary" href="<?php echo esc_url( $slash_image_reoptimize_url ); ?>">
				<?php echo esc_html__( 'Retry optimization', 'slashimage-image-optimizer' ); ?>
			</a>
			<?php if ( $slash_image_has_backup ) : ?>
				<a class="button" href="<?php echo esc_url( $slash_image_restore_url ); ?>">
					<?php echo esc_html__( 'Restore original', 'slashimage-image-optimizer' ); ?>
				</a>
			<?php endif; ?>
		</p>

	<?php elseif ( is_array( $slash_image_data ) && ! empty( $slash_image_data['optimized'] ) ) : ?>

		<?php
		echo wp_kses(
			Slash_Image_Column::render_for_attachment( $slash_image_attachment_id ),
			Slash_Image_Column::allowed_html()
		);

		// Action buttons live in the meta box only — column is informational.
		?>
		<?php if ( $slash_image_has_backup ) : ?>
			<div class="slash-image-reoptimize-modes">
				<span class="slash-image-label"><?php esc_html_e( 'Re-optimize as:', 'slashimage-image-optimizer' ); ?></span>
				<?php
				// Only the two NON-current modes are shown as links; the current
				// mode is omitted entirely. The middot separator appears between the
				// links only (none leading/trailing).
				$slash_image_other_modes = array_values( array_diff( array( 'lossy', 'glossy', 'lossless' ), array( $slash_image_current_mode ) ) );
				$slash_image_first       = true;
				foreach ( $slash_image_other_modes as $slash_image_mode_choice ) :
					if ( ! $slash_image_first ) {
						echo ' <span class="slash-image-mode-sep" aria-hidden="true">·</span> ';
					}
					$slash_image_first = false;
					?>
					<a class="slash-image-mode-link" data-action="reoptimize" data-id="<?php echo esc_attr( $slash_image_attachment_id ); ?>" data-mode="<?php echo esc_attr( $slash_image_mode_choice ); ?>" href="<?php echo esc_url( $slash_image_reoptimize_url_for_mode( $slash_image_mode_choice ) ); ?>"><?php echo esc_html( ucfirst( $slash_image_mode_choice ) ); ?></a>
					<?php
				endforeach;
				?>
			</div>
			<p class="slash-image-mb__actions">
				<a class="button" href="<?php echo esc_url( $slash_image_restore_url ); ?>">
					<?php echo esc_html__( 'Restore original', 'slashimage-image-optimizer' ); ?>
				</a>
			</p>
		<?php endif; ?>

	<?php else : ?>

		<p class="slash-image-mb__status slash-image-mb__status--muted">
			<span class="dashicons dashicons-clock" aria-hidden="true"></span>
			<?php echo esc_html__( 'Not optimized', 'slashimage-image-optimizer' ); ?>
		</p>
		<p class="slash-image-mb__help">
			<?php echo esc_html__( 'Optimize this image to reduce its size and generate modern format variants.', 'slashimage-image-optimizer' ); ?>
		</p>
		<p class="slash-image-mb__actions">
			<a class="button button-primary" data-action="optimize-now" data-id="<?php echo esc_attr( $slash_image_attachment_id ); ?>" href="<?php echo esc_url( $slash_image_reoptimize_url ); ?>">
				<?php echo esc_html__( 'Optimize now', 'slashimage-image-optimizer' ); ?>
			</a>
		</p>

	<?php endif; ?>
</div>

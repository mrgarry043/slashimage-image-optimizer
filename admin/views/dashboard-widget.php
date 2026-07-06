<?php
/**
 * Dashboard widget template. Rendered inside the WordPress core dashboard
 * widget chrome (postbox).
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'read' ) ) {
	return;
}

$slash_image_stats         = Slash_Image_Stats::snapshot();
$slash_image_connection    = Slash_Image_Connection::snapshot();
$slash_image_is_connected  = ! empty( $slash_image_connection['connected'] );
$slash_image_is_invalid    = ! empty( $slash_image_connection['invalid'] );
$slash_image_is_admin_user = current_user_can( 'manage_options' );

$slash_image_total_count  = (int) ( $slash_image_stats['total_optimized'] ?? 0 );
$slash_image_saved_bytes  = (int) ( $slash_image_stats['total_saved_bytes'] ?? 0 );
$slash_image_last_updated = (int) ( $slash_image_stats['last_updated'] ?? 0 );

$slash_image_settings_url = admin_url( 'upload.php?page=' . Slash_Image_Admin::MENU_SLUG );
$slash_image_bulk_url     = admin_url( 'upload.php?page=' . Slash_Image_Bulk_Page::MENU_SLUG );

$slash_image_is_fresh_install = ( ! $slash_image_is_connected && 0 === $slash_image_total_count );

if ( $slash_image_is_invalid ) {
	$slash_image_pill_class = 'is-invalid';
	$slash_image_pill_label = __( 'Reconnect needed', 'slashimage-image-optimizer' );
} elseif ( $slash_image_is_connected ) {
	$slash_image_pill_class = 'is-connected';
	$slash_image_pill_label = __( 'Connected', 'slashimage-image-optimizer' );
} else {
	$slash_image_pill_class = 'is-disconnected';
	$slash_image_pill_label = __( 'Not configured', 'slashimage-image-optimizer' );
}
?>

<div class="slash-image-dw">
	<header class="slash-image-dw__header">
		<?php
		if ( $slash_image_is_admin_user ) :
			?>
			<a class="slash-image-dw-pill <?php echo esc_attr( $slash_image_pill_class ); ?>" href="<?php echo esc_url( $slash_image_settings_url ); ?>">
				<span class="slash-image-dw-pill__dot" aria-hidden="true"></span>
				<span><?php echo esc_html( $slash_image_pill_label ); ?></span>
			</a>
			<?php
		else :
			?>
			<span class="slash-image-dw-pill <?php echo esc_attr( $slash_image_pill_class ); ?>">
				<span class="slash-image-dw-pill__dot" aria-hidden="true"></span>
				<span><?php echo esc_html( $slash_image_pill_label ); ?></span>
			</span>
			<?php
		endif;
		?>
	</header>

	<?php if ( $slash_image_is_fresh_install && $slash_image_is_admin_user ) : ?>
		<div class="slash-image-dw__onboard">
			<p class="slash-image-dw__onboard-text">
				<?php echo esc_html__( 'Get started: connect your slashimage.com account to start optimizing images automatically.', 'slashimage-image-optimizer' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $slash_image_settings_url ); ?>">
					<?php echo esc_html__( 'Configure SlashImage', 'slashimage-image-optimizer' ); ?>
				</a>
			</p>
		</div>

	<?php elseif ( $slash_image_is_fresh_install ) : ?>
		<p class="slash-image-dw__onboard-text">
			<?php echo esc_html__( 'SlashImage is installed but not yet configured by an administrator.', 'slashimage-image-optimizer' ); ?>
		</p>

	<?php else : ?>
		<div class="slash-image-dw__stats">
			<div class="slash-image-dw__cell">
				<p class="slash-image-dw__value"><?php echo esc_html( number_format_i18n( $slash_image_total_count ) ); ?></p>
				<p class="slash-image-dw__label"><?php echo esc_html__( 'Images optimized', 'slashimage-image-optimizer' ); ?></p>
			</div>
			<div class="slash-image-dw__cell">
				<p class="slash-image-dw__value">
				<?php
					$slash_image_saved_h = size_format( $slash_image_saved_bytes, 1 );
					echo esc_html( $slash_image_saved_h ? $slash_image_saved_h : '0 B' );
				?>
				</p>
				<p class="slash-image-dw__label"><?php echo esc_html__( 'Bandwidth saved', 'slashimage-image-optimizer' ); ?></p>
			</div>
		</div>

		<?php if ( $slash_image_last_updated > 0 ) : ?>
			<p class="slash-image-dw__when">
				<?php
				/* translators: %s: human time difference */
				printf( esc_html__( 'Last optimization: %s ago', 'slashimage-image-optimizer' ), esc_html( human_time_diff( $slash_image_last_updated, time() ) ) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( $slash_image_is_admin_user ) : ?>
			<p class="slash-image-dw__actions">
				<a class="button button-primary" href="<?php echo esc_url( $slash_image_bulk_url ); ?>">
					<?php echo esc_html__( 'Bulk optimize media', 'slashimage-image-optimizer' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $slash_image_settings_url ); ?>">
					<?php echo esc_html__( 'Manage settings', 'slashimage-image-optimizer' ); ?>
				</a>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>

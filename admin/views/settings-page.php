<?php
/**
 * Settings → Slash Image page template (flat-panel redesign).
 *
 * One centered panel: header · stats strip · tabs · single options.php form
 * (Overview + Settings tab bodies) · footer. Sections are flat and hairline-
 * divided; each is a self-contained .slash-image-group so a single `cards`
 * class on .slash-image-app flips them to cards (see settings.css "Card mode").
 *
 * Functional hooks (data-tab / data-target / IDs / field names) are unchanged
 * from the previous markup, so admin/js/settings.js drives this verbatim.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

// Re-probe a previously-connected key (throttled ~10 min, short timeout) so a
// key that died since the last load renders the correct state on THIS load.
// Must run before snapshot() — a flip invalidates the cached state.
Slash_Image_Connection::maybe_recheck_key();

$slash_image_state         = Slash_Image_Connection::snapshot();
$slash_image_is_connected  = ! empty( $slash_image_state['connected'] );
$slash_image_is_invalid    = ! empty( $slash_image_state['invalid'] );
$slash_image_api_key       = (string) Slash_Image_Settings::get( 'api_key', '' );
$slash_image_saved_at      = (int) get_option( 'slash_image_settings_saved_at', 0 );
$slash_image_lib           = Slash_Image_Bulk_Processor::library_counts();
$slash_image_plan          = Slash_Image_Connection::get_plan_cache();
$slash_image_dashboard_url = SLASH_IMAGE_DASHBOARD_URL;

// Stats strip figures, INCLUDING thumbnails:
//  - Optimized = exact files done (main images + their optimized thumbnails).
//  - Unoptimized = credits_estimate() — pending images × non-excluded sizes,
//    i.e. the same "image files (incl thumbnails) still to optimize" number
//    the Bulk page shows as the credits needed to finish.
$slash_image_optimized_files   = (int) $slash_image_lib['optimized'] + (int) $slash_image_lib['thumbnails_optimized'];
$slash_image_unoptimized_files = (int) Slash_Image_Bulk_Processor::credits_estimate();
$slash_image_bulk_url      = admin_url( 'upload.php?page=slash-image-bulk' );

$slash_image_auto_optimize_uploads = (bool) Slash_Image_Settings::get( 'auto_optimize_uploads', true );
$slash_image_compression_mode      = (string) Slash_Image_Settings::get( 'compression_mode', 'lossy' );
$slash_image_frontend_mode         = (string) Slash_Image_Settings::get( 'frontend_serving_mode', 'picture' );
$slash_image_server_kind           = Slash_Image_Server::detect();
$slash_image_htaccess_active       = Slash_Image_Htaccess::is_active();
$slash_image_htaccess_writable     = Slash_Image_Server::htaccess_writable();
$slash_image_generate_webp         = (bool) Slash_Image_Settings::get( 'generate_webp', true );
$slash_image_generate_avif         = (bool) Slash_Image_Settings::get( 'generate_avif', true );
$slash_image_convert_png_to_jpeg   = (bool) Slash_Image_Settings::get( 'convert_png_to_jpeg', false );
$slash_image_resize_on_upload      = (bool) Slash_Image_Settings::get( 'resize_on_upload', false );
$slash_image_max_width             = (int) Slash_Image_Settings::get( 'max_width', 1560 );
$slash_image_max_height            = (int) Slash_Image_Settings::get( 'max_height', 1560 );
$slash_image_keep_backups          = (bool) Slash_Image_Settings::get( 'keep_backups', true );
$slash_image_smart_backups         = (bool) Slash_Image_Settings::get( 'smart_backups', true );
$slash_image_backup_mode_desc      = $slash_image_smart_backups
	? __( 'Backs up only the original to save disk space. When you restore, thumbnails are rebuilt from it using your current image sizes.', 'slashimage-image-optimizer' )
	: __( 'Backs up the original and all its thumbnails, so a restore brings everything back exactly as it was. Uses more disk space.', 'slashimage-image-optimizer' );
$slash_image_auto_delete_backups   = (bool) Slash_Image_Settings::get( 'auto_delete_backups', false );
$slash_image_backup_retention_days = (int) Slash_Image_Settings::get( 'backup_retention_days', 90 );
$slash_image_uninstall_remove_all  = (bool) Slash_Image_Settings::get( 'uninstall_remove_all', false );

$slash_image_available_sizes        = Slash_Image_Settings::available_image_sizes();
$slash_image_excluded_sizes         = Slash_Image_Settings::excluded_image_sizes();
$slash_image_custom_exclusions_raw  = (string) Slash_Image_Settings::get( 'custom_exclusions', '' );
$slash_image_custom_exclusions_list = Slash_Image_Settings::custom_exclusion_patterns();

$slash_image_initial_tab = $slash_image_is_connected ? 'dashboard' : 'settings';
$slash_image_option_name = Slash_Image_Settings::OPTION_NAME;

$slash_image_initial_helper_text = ( 'glossy' === $slash_image_compression_mode )
	? __( 'Higher quality with moderate compression. Choose this for portfolios or photography sites where image fidelity matters.', 'slashimage-image-optimizer' )
	: ( ( 'lossless' === $slash_image_compression_mode )
		? __( 'Minimum compression. Choose this only when you need pixel-perfect originals.', 'slashimage-image-optimizer' )
		: __( 'Lossy is best for most sites — smallest files with quality differences invisible to most viewers.', 'slashimage-image-optimizer' ) );

// Flat group header.
$slash_image_group_head = static function ( $icon, $title, $desc = '', $danger = false ) {
	?>
	<div class="slash-image-grouphead<?php echo $danger ? ' danger' : ''; ?>">
		<span class="slash-image-group__ic" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span></span>
		<div>
			<h2 class="slash-image-group__title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( '' !== $desc ) : ?>
				<p class="slash-image-group__desc"><?php echo esc_html( $desc ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
};
?>

<div class="slash-image-app slash-image-app--settings" data-initial-tab="<?php echo esc_attr( $slash_image_initial_tab ); ?>">

	<!-- Header -->
	<header class="slash-image-app__hero">
		<div class="slash-image-brand">
			<span class="slash-image-brand__mark" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M16 5 L8 19" /></svg>
			</span>
			<span class="slash-image-brand__name"><?php echo esc_html__( 'SlashImage', 'slashimage-image-optimizer' ); ?></span>
			<span class="slash-image-brand__version">v<?php echo esc_html( SLASH_IMAGE_VERSION ); ?></span>
		</div>
		<?php
		if ( $slash_image_is_invalid ) {
			$slash_image_pill_class = 'is-invalid';
			$slash_image_pill_text  = __( 'Reconnect needed', 'slashimage-image-optimizer' );
		} elseif ( $slash_image_is_connected ) {
			$slash_image_pill_class = 'is-connected';
			$slash_image_pill_text  = __( 'Connected', 'slashimage-image-optimizer' );
		} else {
			$slash_image_pill_class = 'is-disconnected';
			$slash_image_pill_text  = __( 'Not configured', 'slashimage-image-optimizer' );
		}
		?>
		<div class="slash-image-conn-pill <?php echo esc_attr( $slash_image_pill_class ); ?>" id="slash-image-conn-pill">
			<span class="slash-image-conn-pill__dot" aria-hidden="true"></span>
			<span class="slash-image-conn-pill__label"><?php echo esc_html( $slash_image_pill_text ); ?></span>
		</div>
	</header>

	<!-- Stats strip -->
	<div class="slash-image-strip">
		<div class="slash-image-strip__item">
			<span class="slash-image-strip__num"><?php echo esc_html( number_format_i18n( $slash_image_optimized_files ) ); ?></span>
			<span class="slash-image-strip__lbl"><?php echo esc_html__( 'Optimized', 'slashimage-image-optimizer' ); ?></span>
		</div>
		<span class="slash-image-strip__sep" aria-hidden="true"></span>
		<?php
		$slash_image_stats_tip = __( 'Includes the full image and all generated thumbnail sizes. To reduce this count, disable unused thumbnail sizes in plugin settings.', 'slashimage-image-optimizer' );
		?>
		<div class="slash-image-strip__item">
			<span class="slash-image-strip__num"><?php echo esc_html( number_format_i18n( $slash_image_unoptimized_files ) ); ?></span>
			<span class="slash-image-strip__lbl"><?php echo esc_html__( 'Unoptimized', 'slashimage-image-optimizer' ); ?></span>
			<span class="slash-image-strip__info" tabindex="0" aria-label="<?php echo esc_attr( $slash_image_stats_tip ); ?>">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<span class="slash-image-strip__tip" aria-hidden="true"><?php esc_html_e( 'Includes the full image and all generated thumbnail sizes. To reduce this count, disable unused thumbnail sizes in', 'slashimage-image-optimizer' ); ?> <a href="<?php echo esc_url( admin_url( 'upload.php?page=slash-image-settings#settings' ) ); ?>" class="tip-link"><?php esc_html_e( 'plugin settings', 'slashimage-image-optimizer' ); ?></a>.</span>
			</span>
		</div>
		<div class="slash-image-strip__item slash-image-strip__credits">
			<?php
			$slash_image_plan_known   = is_array( $slash_image_plan ) && array_key_exists( 'monthly_limit', $slash_image_plan );
			$slash_image_is_unlimited = $slash_image_plan_known && ( null === $slash_image_plan['monthly_limit'] );
			if ( ! $slash_image_plan_known ) :
				?>
				<span class="slash-image-strip__lbl"><?php echo esc_html__( 'Credits', 'slashimage-image-optimizer' ); ?></span>
				<span class="slash-image-credit-val">&mdash;</span>
			<?php elseif ( $slash_image_is_unlimited ) : ?>
				<span class="slash-image-strip__lbl"><?php echo esc_html__( 'Credits', 'slashimage-image-optimizer' ); ?></span>
				<span class="slash-image-credit-unlimited"><?php echo esc_html__( 'Unlimited Plan', 'slashimage-image-optimizer' ); ?></span>
			<?php else : ?>
				<?php
				$slash_image_cr_limit     = (int) $slash_image_plan['monthly_limit'];
				$slash_image_cr_used      = (int) $slash_image_plan['images_used_this_month'];
				$slash_image_cr_remaining = is_int( $slash_image_plan['images_remaining'] ) ? (int) $slash_image_plan['images_remaining'] : max( 0, $slash_image_cr_limit - $slash_image_cr_used );
				$slash_image_cr_pct       = $slash_image_cr_limit > 0 ? min( 100, max( 0, (int) round( $slash_image_cr_used / $slash_image_cr_limit * 100 ) ) ) : 100;
				$slash_image_cr_low       = ( $slash_image_cr_remaining <= 10 );
				$slash_image_cr_title     = sprintf(
					/* translators: %1$s: images used, %2$s: monthly limit, %3$s: images remaining */
					__( '%1$s of %2$s credits used · %3$s remaining', 'slashimage-image-optimizer' ),
					number_format_i18n( $slash_image_cr_used ),
					number_format_i18n( $slash_image_cr_limit ),
					number_format_i18n( $slash_image_cr_remaining )
				);
				?>
				<span class="slash-image-strip__lbl"><?php echo esc_html__( 'Credits used', 'slashimage-image-optimizer' ); ?></span>
				<span class="slash-image-credit-val" title="<?php echo esc_attr( $slash_image_cr_title ); ?>"><?php echo esc_html( $slash_image_cr_pct ); ?>%</span>
				<span class="slash-image-credit-bar<?php echo $slash_image_cr_low ? ' is-low' : ''; ?>" title="<?php echo esc_attr( $slash_image_cr_title ); ?>"><i style="width: <?php echo esc_attr( $slash_image_cr_pct ); ?>%;"></i></span>
			<?php endif; ?>
		</div>
	</div>

	<!-- Tabs -->
	<nav class="slash-image-app__nav" role="tablist" aria-label="<?php echo esc_attr__( 'SlashImage sections', 'slashimage-image-optimizer' ); ?>">
		<button type="button" class="slash-image-tab" data-tab="dashboard" role="tab" aria-controls="slash-image-tab-dashboard" aria-selected="false">
			<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><?php echo esc_html__( 'Overview', 'slashimage-image-optimizer' ); ?>
		</button>
		<button type="button" class="slash-image-tab" data-tab="settings" role="tab" aria-controls="slash-image-tab-settings" aria-selected="false">
			<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><?php echo esc_html__( 'Settings', 'slashimage-image-optimizer' ); ?>
		</button>
	</nav>

	<form method="post" action="options.php" novalidate class="slash-image-form">
		<?php settings_fields( Slash_Image_Settings::OPTION_GROUP ); ?>

		<div class="slash-image-body">
			<div class="slash-image-settings-errors"><?php settings_errors( Slash_Image_Settings::OPTION_NAME ); ?></div>

			<!-- ─────────────── OVERVIEW TAB ─────────────── -->
			<div class="slash-image-tab-panel" id="slash-image-tab-dashboard" data-tab-panel="dashboard" role="tabpanel">

				<!-- Compression -->
				<section class="slash-image-group">
					<?php $slash_image_group_head( 'performance', __( 'Compression', 'slashimage-image-optimizer' ), __( 'Balance of quality and file size for every optimization.', 'slashimage-image-optimizer' ) ); ?>
					<div class="slash-image-group__body">
						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Compression mode', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__followup" id="slash-image-helper-compression-mode">
									<span class="slash-image-helper-badge" id="slash-image-helper-recommended" <?php echo 'lossy' === $slash_image_compression_mode ? '' : 'hidden'; ?>><?php echo esc_html__( 'Recommended', 'slashimage-image-optimizer' ); ?></span>
									<span id="slash-image-helper-text"><?php echo esc_html( $slash_image_initial_helper_text ); ?></span>
								</p>
							</div>
							<div class="slash-image-row__control">
								<div class="slash-image-segmented" role="radiogroup" aria-label="<?php echo esc_attr__( 'Compression mode', 'slashimage-image-optimizer' ); ?>" data-target="slash-image-input-compression-mode" data-helper-target="slash-image-helper-compression-mode">
									<?php
									$slash_image_compression_modes = array(
										'lossy'    => __( 'Lossy', 'slashimage-image-optimizer' ),
										'glossy'   => __( 'Glossy', 'slashimage-image-optimizer' ),
										'lossless' => __( 'Lossless', 'slashimage-image-optimizer' ),
									);
									foreach ( $slash_image_compression_modes as $slash_image_value => $slash_image_label ) :
										$slash_image_active = ( $slash_image_value === $slash_image_compression_mode );
										?>
										<button type="button" class="slash-image-segment<?php echo $slash_image_active ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $slash_image_value ); ?>" role="radio" aria-checked="<?php echo $slash_image_active ? 'true' : 'false'; ?>" tabindex="<?php echo $slash_image_active ? '0' : '-1'; ?>"><?php echo esc_html( $slash_image_label ); ?></button>
									<?php endforeach; ?>
								</div>
								<input type="hidden" id="slash-image-input-compression-mode" name="<?php echo esc_attr( $slash_image_option_name ); ?>[compression_mode]" value="<?php echo esc_attr( $slash_image_compression_mode ); ?>" />
							</div>
						</div>

						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Auto-optimize on upload', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Queue every new upload automatically; optimization runs in the background.', 'slashimage-image-optimizer' ); ?></p>
							</div>
							<div class="slash-image-row__control">
								<?php $slash_image_f = $slash_image_option_name . '[auto_optimize_uploads]'; ?>
								<input type="hidden" name="<?php echo esc_attr( $slash_image_f ); ?>" value="0" />
								<label class="slash-image-toggle">
									<input type="checkbox" name="<?php echo esc_attr( $slash_image_f ); ?>" value="1" <?php checked( $slash_image_auto_optimize_uploads ); ?> />
									<span class="slash-image-toggle__track"><span class="slash-image-toggle__thumb"></span></span>
								</label>
							</div>
						</div>
					</div>
				</section>

				<!-- Output Formats -->
				<section class="slash-image-group">
					<?php $slash_image_group_head( 'format-image', __( 'Output Formats', 'slashimage-image-optimizer' ), __( 'Modern formats generated alongside the original.', 'slashimage-image-optimizer' ) ); ?>
					<div class="slash-image-group__body">
						<?php
						$slash_image_format_toggles = array(
							'generate_webp'       => array( __( 'Generate WebP', 'slashimage-image-optimizer' ), __( 'Modern format with broad browser support.', 'slashimage-image-optimizer' ), $slash_image_generate_webp ),
							'generate_avif'       => array( __( 'Generate AVIF', 'slashimage-image-optimizer' ), __( 'Newest format. Smallest files where supported.', 'slashimage-image-optimizer' ), $slash_image_generate_avif ),
							'convert_png_to_jpeg' => array( __( 'Convert PNG to JPEG', 'slashimage-image-optimizer' ), __( 'When the PNG has no transparency. Saves extra space.', 'slashimage-image-optimizer' ), $slash_image_convert_png_to_jpeg ),
						);
						foreach ( $slash_image_format_toggles as $slash_image_key => $slash_image_row ) :
							$slash_image_f = $slash_image_option_name . '[' . $slash_image_key . ']';
							?>
							<div class="slash-image-row">
								<div class="slash-image-row__head">
									<div class="slash-image-row__label"><?php echo esc_html( $slash_image_row[0] ); ?></div>
									<p class="slash-image-row__help"><?php echo esc_html( $slash_image_row[1] ); ?></p>
								</div>
								<div class="slash-image-row__control">
									<input type="hidden" name="<?php echo esc_attr( $slash_image_f ); ?>" value="0" />
									<label class="slash-image-toggle">
										<input type="checkbox" name="<?php echo esc_attr( $slash_image_f ); ?>" value="1" <?php checked( $slash_image_row[2] ); ?> />
										<span class="slash-image-toggle__track"><span class="slash-image-toggle__thumb"></span></span>
									</label>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</section>

				<!-- Frontend Serving -->
				<section class="slash-image-group" id="slash-image-frontend-card">
					<?php $slash_image_group_head( 'visibility', __( 'Frontend Serving', 'slashimage-image-optimizer' ), __( 'How modern formats are delivered to browsers.', 'slashimage-image-optimizer' ) ); ?>
					<div class="slash-image-group__body">
						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Delivery method', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__followup" id="slash-image-helper-frontend-mode"></p>
							</div>
							<div class="slash-image-row__control">
								<div class="slash-image-segmented" role="radiogroup" aria-label="<?php echo esc_attr__( 'Delivery method', 'slashimage-image-optimizer' ); ?>" data-target="slash-image-input-frontend-mode" data-helper-target="slash-image-helper-frontend-mode">
									<?php
									$slash_image_frontend_modes = array(
										'picture'  => __( 'Picture tag', 'slashimage-image-optimizer' ),
										'htaccess' => __( 'Server rewrite', 'slashimage-image-optimizer' ),
										'disabled' => __( 'None', 'slashimage-image-optimizer' ),
									);
									foreach ( $slash_image_frontend_modes as $slash_image_value => $slash_image_label ) :
										$slash_image_active = ( $slash_image_value === $slash_image_frontend_mode );
										?>
										<button type="button" class="slash-image-segment<?php echo $slash_image_active ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $slash_image_value ); ?>" role="radio" aria-checked="<?php echo $slash_image_active ? 'true' : 'false'; ?>" tabindex="<?php echo $slash_image_active ? '0' : '-1'; ?>"><?php echo esc_html( $slash_image_label ); ?></button>
									<?php endforeach; ?>
								</div>
								<input type="hidden" id="slash-image-input-frontend-mode" name="<?php echo esc_attr( $slash_image_option_name ); ?>[frontend_serving_mode]" value="<?php echo esc_attr( $slash_image_frontend_mode ); ?>" />
							</div>
						</div>

						<?php // Unconditional CDN/Cloudflare caveat — shown by JS whenever Server Rewrite is the selected delivery method (data-show-when-mode), NOT gated on Cloudflare detection (detection misses localhost dev + Cloudflare turned on after setup). ?>
						<div class="slash-image-alert is-warning slash-image-fe-cloudflare" id="slash-image-fe-cloudflare" data-show-when-mode="htaccess">
							<p>
								<?php echo esc_html__( "Server rewrite doesn't work behind Cloudflare unless you're on Cloudflare Enterprise.", 'slashimage-image-optimizer' ); ?>
								<a href="https://slashimage.com/docs/serving-next-gen-images/why-server-rewrite-dont-work-with-cloudflare" target="_blank" rel="noopener noreferrer" class="slash-image-fe-link"><?php echo esc_html__( 'Learn why', 'slashimage-image-optimizer' ); ?></a>
							</p>
						</div>

						<div class="slash-image-fe-actions" id="slash-image-fe-actions" data-show-when-mode="htaccess" data-server="<?php echo esc_attr( $slash_image_server_kind ); ?>" data-writable="<?php echo $slash_image_htaccess_writable ? '1' : '0'; ?>">
							<?php if ( in_array( $slash_image_server_kind, array( 'apache', 'litespeed' ), true ) ) : ?>
								<div class="slash-image-fe-apply" data-active="<?php echo $slash_image_htaccess_active ? '1' : '0'; ?>">
									<?php if ( $slash_image_htaccess_active ) : ?>
										<p class="slash-image-fe-status is-success" id="slash-image-fe-status"><?php echo esc_html__( '✓ Rewrite rules active in .htaccess', 'slashimage-image-optimizer' ); ?></p>
										<button type="button" class="slash-image-btn slash-image-btn--danger-outline" id="slash-image-fe-remove"><?php echo esc_html__( 'Remove rewrite rules', 'slashimage-image-optimizer' ); ?></button>
									<?php else : ?>
										<button type="button" class="slash-image-btn slash-image-btn--primary" id="slash-image-fe-apply" <?php echo $slash_image_htaccess_writable ? '' : 'disabled'; ?>><?php echo esc_html__( 'Apply rewrite rules to .htaccess', 'slashimage-image-optimizer' ); ?></button>
										<?php if ( ! $slash_image_htaccess_writable ) : ?>
											<p class="slash-image-fe-status is-error"><?php echo esc_html__( 'The .htaccess file is not writable. Check file permissions.', 'slashimage-image-optimizer' ); ?></p>
										<?php endif; ?>
									<?php endif; ?>
									<div id="slash-image-fe-result" class="slash-image-alert slash-image-alert--inline" role="status" aria-live="polite" hidden></div>
								</div>
							<?php elseif ( 'nginx' === $slash_image_server_kind ) : ?>
								<div class="slash-image-fe-nginx">
									<p><?php echo esc_html__( 'Nginx requires manual server configuration. See the setup guide:', 'slashimage-image-optimizer' ); ?>
										<a href="https://slashimage.com/docs/serving-next-gen-images/nginx-rewrite-rules" target="_blank" rel="noopener noreferrer" class="slash-image-fe-link"><?php echo esc_html__( 'Open Nginx docs →', 'slashimage-image-optimizer' ); ?></a>
									</p>
								</div>
							<?php else : ?>
								<div class="slash-image-fe-unknown">
									<p><?php echo esc_html__( 'Server type could not be detected. Use the picture tag mode for reliable delivery.', 'slashimage-image-optimizer' ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<!-- Resize -->
				<section class="slash-image-group">
					<?php $slash_image_group_head( 'editor-expand', __( 'Resize', 'slashimage-image-optimizer' ), __( 'Scale very large images down on your server before they are optimized.', 'slashimage-image-optimizer' ) ); ?>
					<div class="slash-image-group__body">
						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Resize large images', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Scale down images that are bigger than the size you set.', 'slashimage-image-optimizer' ); ?></p>
							</div>
							<div class="slash-image-row__control">
								<?php $slash_image_f = $slash_image_option_name . '[resize_on_upload]'; ?>
								<input type="hidden" name="<?php echo esc_attr( $slash_image_f ); ?>" value="0" />
								<label class="slash-image-toggle">
									<input type="checkbox" id="slash-image-resize-toggle" name="<?php echo esc_attr( $slash_image_f ); ?>" value="1" <?php checked( $slash_image_resize_on_upload ); ?> />
									<span class="slash-image-toggle__track"><span class="slash-image-toggle__thumb"></span></span>
								</label>
							</div>
						</div>

						<div class="slash-image-row slash-image-resize-bounds <?php echo $slash_image_resize_on_upload ? '' : 'is-disabled'; ?>">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Maximum dimensions', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Images are only scaled down, never up. Set either dimension to 0 for no limit on that side. WordPress already scales uploads to 2560 px, so a value above 2560 has no effect.', 'slashimage-image-optimizer' ); ?></p>
							</div>
							<div class="slash-image-row__control">
								<span class="slash-image-inline-fields">
									<span class="slash-image-input-group">
										<input type="number" min="0" max="10000" step="1" name="<?php echo esc_attr( $slash_image_option_name ); ?>[max_width]" value="<?php echo esc_attr( $slash_image_max_width ); ?>" aria-label="<?php echo esc_attr__( 'Max width', 'slashimage-image-optimizer' ); ?>" />
										<span class="slash-image-input-suffix">px</span>
									</span>
									<span class="slash-image-dims-x" aria-hidden="true">×</span>
									<span class="slash-image-input-group">
										<input type="number" min="0" max="10000" step="1" name="<?php echo esc_attr( $slash_image_option_name ); ?>[max_height]" value="<?php echo esc_attr( $slash_image_max_height ); ?>" aria-label="<?php echo esc_attr__( 'Max height', 'slashimage-image-optimizer' ); ?>" />
										<span class="slash-image-input-suffix">px</span>
									</span>
								</span>
							</div>
						</div>
					</div>
				</section>

				<!-- Backups -->
				<section class="slash-image-group">
					<?php $slash_image_group_head( 'backup', __( 'Backups', 'slashimage-image-optimizer' ), __( 'Keep originals so you can revert anytime.', 'slashimage-image-optimizer' ) ); ?>
					<div class="slash-image-group__body">
						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Keep backup of originals', 'slashimage-image-optimizer' ); ?></div>
							</div>
							<div class="slash-image-row__control">
								<?php $slash_image_f = $slash_image_option_name . '[keep_backups]'; ?>
								<input type="hidden" name="<?php echo esc_attr( $slash_image_f ); ?>" value="0" />
								<label class="slash-image-toggle">
									<input type="checkbox" id="slash-image-backups-toggle" name="<?php echo esc_attr( $slash_image_f ); ?>" value="1" <?php checked( $slash_image_keep_backups ); ?> />
									<span class="slash-image-toggle__track"><span class="slash-image-toggle__thumb"></span></span>
								</label>
							</div>
						</div>

						<div class="slash-image-row slash-image-smart-backups-bounds <?php echo $slash_image_keep_backups ? '' : 'is-hidden'; ?>">
							<div class="slash-image-row__head">
								<p class="slash-image-row__help" id="slash-image-helper-backup-mode"><?php echo esc_html( $slash_image_backup_mode_desc ); ?></p>
							</div>
							<div class="slash-image-row__control">
								<div class="slash-image-segmented" role="radiogroup" aria-label="<?php echo esc_attr__( 'Backup mode', 'slashimage-image-optimizer' ); ?>" data-target="slash-image-input-backup-mode" data-helper-target="slash-image-helper-backup-mode">
									<button type="button" class="slash-image-segment<?php echo $slash_image_smart_backups ? ' is-active' : ''; ?>" data-value="1" role="radio" aria-checked="<?php echo $slash_image_smart_backups ? 'true' : 'false'; ?>" tabindex="<?php echo $slash_image_smart_backups ? '0' : '-1'; ?>"><?php echo esc_html__( 'Smart backup', 'slashimage-image-optimizer' ); ?></button>
									<button type="button" class="slash-image-segment<?php echo $slash_image_smart_backups ? '' : ' is-active'; ?>" data-value="0" role="radio" aria-checked="<?php echo $slash_image_smart_backups ? 'false' : 'true'; ?>" tabindex="<?php echo $slash_image_smart_backups ? '-1' : '0'; ?>"><?php echo esc_html__( 'Full backup', 'slashimage-image-optimizer' ); ?></button>
								</div>
								<input type="hidden" id="slash-image-input-backup-mode" name="<?php echo esc_attr( $slash_image_option_name ); ?>[smart_backups]" value="<?php echo $slash_image_smart_backups ? '1' : '0'; ?>" />
							</div>
						</div>

						<div class="slash-image-row slash-image-auto-delete-bounds <?php echo $slash_image_keep_backups ? '' : 'is-hidden'; ?>">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Auto-delete backups', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Automatically delete original backups after a set number of days.', 'slashimage-image-optimizer' ); ?></p>
							</div>
							<div class="slash-image-row__control">
								<?php $slash_image_f = $slash_image_option_name . '[auto_delete_backups]'; ?>
								<input type="hidden" name="<?php echo esc_attr( $slash_image_f ); ?>" value="0" />
								<label class="slash-image-toggle">
									<input type="checkbox" id="slash-image-auto-delete-toggle" name="<?php echo esc_attr( $slash_image_f ); ?>" value="1" <?php checked( $slash_image_auto_delete_backups ); ?> />
									<span class="slash-image-toggle__track"><span class="slash-image-toggle__thumb"></span></span>
								</label>
							</div>
						</div>

						<div class="slash-image-row slash-image-retention-bounds <?php echo ( $slash_image_keep_backups && $slash_image_auto_delete_backups ) ? '' : 'is-hidden'; ?>">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Delete backups after', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Delete backups older than this many days.', 'slashimage-image-optimizer' ); ?></p>
							</div>
							<div class="slash-image-row__control">
								<span class="slash-image-input-group">
									<input type="number" min="1" max="3650" step="1" name="<?php echo esc_attr( $slash_image_option_name ); ?>[backup_retention_days]" value="<?php echo esc_attr( $slash_image_backup_retention_days ); ?>" aria-label="<?php echo esc_attr__( 'Auto-delete backups after days', 'slashimage-image-optimizer' ); ?>" />
									<span class="slash-image-input-suffix"><?php echo esc_html__( 'days', 'slashimage-image-optimizer' ); ?></span>
								</span>
							</div>
						</div>
					</div>
				</section>
			</div>

			<!-- ─────────────── SETTINGS TAB ─────────────── -->
			<div class="slash-image-tab-panel" id="slash-image-tab-settings" data-tab-panel="settings" role="tabpanel">

				<!-- API Key -->
				<section class="slash-image-group">
					<?php $slash_image_group_head( 'admin-network', __( 'API Key', 'slashimage-image-optimizer' ), __( 'Connect your slashimage.com account.', 'slashimage-image-optimizer' ) ); ?>
					<div class="slash-image-group__body">
						<?php if ( $slash_image_is_connected ) : ?>
							<div class="slash-image-keyfield">
								<span class="slash-image-keyfield__inputwrap">
									<input type="text" class="slash-image-key-readonly" value="<?php echo esc_attr( Slash_Image_Connection::get_fingerprint() ); ?>" readonly aria-label="<?php echo esc_attr__( 'Connected API key', 'slashimage-image-optimizer' ); ?>" />
								</span>
								<button type="button" id="slash-image-disconnect" class="button slash-image-btn-disconnect"><?php esc_html_e( 'Disconnect', 'slashimage-image-optimizer' ); ?></button>
							</div>
						<?php else : ?>
							<?php if ( $slash_image_is_invalid ) : ?>
								<div class="slash-image-keyfield__invalid" role="alert">
									<p class="slash-image-keyfield__invalid-msg"><?php esc_html_e( 'Your API key is no longer valid - it may have been revoked or regenerated. Reconnect to resume optimizing.', 'slashimage-image-optimizer' ); ?></p>
									<?php $slash_image_dead_fingerprint = Slash_Image_Connection::get_fingerprint(); ?>
									<?php if ( '' !== $slash_image_dead_fingerprint ) : ?>
										<p class="slash-image-keyfield__deadkey">
											<?php
											printf(
												/* translators: %s: masked fingerprint of the now-invalid key */
												esc_html__( 'Disconnected key: %s', 'slashimage-image-optimizer' ),
												'<code>' . esc_html( $slash_image_dead_fingerprint ) . '</code>'
											);
											?>
										</p>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<div class="slash-image-keyfield">
								<span class="slash-image-keyfield__inputwrap">
									<input id="slash-image-api-key" type="text" value="" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( $slash_image_is_invalid ? __( 'Paste a valid API key to reconnect', 'slashimage-image-optimizer' ) : __( 'Paste your API key to connect', 'slashimage-image-optimizer' ) ); ?>" />
								</span>
								<button type="button" id="slash-image-connect" class="slash-image-btn slash-image-btn--primary"><?php echo esc_html( $slash_image_is_invalid ? __( 'Reconnect', 'slashimage-image-optimizer' ) : __( 'Connect', 'slashimage-image-optimizer' ) ); ?></button>
							</div>
							<p class="slash-image-keyfield__error" id="slash-image-key-error" role="alert" hidden></p>
							<?php if ( ! $slash_image_is_invalid ) : ?>
								<p class="slash-image-keyfield__signup" id="slash-image-keyfield-signup">
									<?php
									printf(
										/* translators: %s: link to slashimage.com */
										esc_html__( "Don't have a key? %s", 'slashimage-image-optimizer' ),
										'<a href="' . esc_url( $slash_image_dashboard_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get one free at slashimage.com →', 'slashimage-image-optimizer' ) . '</a>'
									);
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</section>

				<!-- Image Size Exclusions -->
				<section class="slash-image-group" id="image-sizes">
					<?php $slash_image_group_head( 'filter', __( 'Image Size Exclusions', 'slashimage-image-optimizer' ), __( 'Choose which image sizes to optimize, and skip files by keyword.', 'slashimage-image-optimizer' ) ); ?>
					<div class="slash-image-group__body">
						<div class="slash-image-sub-title"><?php echo esc_html__( 'Image sizes', 'slashimage-image-optimizer' ); ?></div>
						<div class="slash-image-sub-desc"><?php echo esc_html__( 'Smaller sizes save fewer bytes — skip them to conserve credits.', 'slashimage-image-optimizer' ); ?></div>
						<div class="slash-image-sizegrid">
							<input type="hidden" name="<?php echo esc_attr( $slash_image_option_name ); ?>[excluded_image_sizes][__placeholder__]" value="0" />
							<?php
							foreach ( $slash_image_available_sizes as $slash_image_size_key => $slash_image_size_data ) :
								$slash_image_is_excluded  = in_array( (string) $slash_image_size_key, $slash_image_excluded_sizes, true );
								$slash_image_is_optimized = ! $slash_image_is_excluded;
								$slash_image_w            = (int) $slash_image_size_data['width'];
								$slash_image_h            = (int) $slash_image_size_data['height'];
								$slash_image_dimensions   = '';
								if ( 'full' !== $slash_image_size_key && $slash_image_w > 0 && $slash_image_h > 0 ) {
									$slash_image_dimensions = sprintf( '%1$d × %2$d', $slash_image_w, $slash_image_h );
								} elseif ( 'full' !== $slash_image_size_key && $slash_image_w > 0 ) {
									/* translators: %d: width */
									$slash_image_dimensions = sprintf( __( '%d × auto', 'slashimage-image-optimizer' ), $slash_image_w );
								} elseif ( 'full' !== $slash_image_size_key && $slash_image_h > 0 ) {
									/* translators: %d: height */
									$slash_image_dimensions = sprintf( __( 'auto × %d', 'slashimage-image-optimizer' ), $slash_image_h );
								}
								?>
								<label class="slash-image-sizegrid__item">
									<input type="checkbox" name="<?php echo esc_attr( $slash_image_option_name ); ?>[__included_sizes][]" value="<?php echo esc_attr( $slash_image_size_key ); ?>" data-size-key="<?php echo esc_attr( $slash_image_size_key ); ?>" <?php checked( $slash_image_is_optimized ); ?> />
									<span class="slash-image-sizegrid__label">
										<span class="slash-image-sizegrid__name"><?php echo esc_html( $slash_image_size_data['label'] ); ?></span>
										<?php if ( '' !== $slash_image_dimensions ) : ?>
											<span class="slash-image-sizegrid__dim"><?php echo esc_html( $slash_image_dimensions ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
						<div class="slash-image-sizegrid__hidden" id="slash-image-excluded-hidden" aria-hidden="true">
							<?php foreach ( $slash_image_excluded_sizes as $slash_image_excluded_key ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $slash_image_option_name ); ?>[excluded_image_sizes][]" value="<?php echo esc_attr( $slash_image_excluded_key ); ?>" />
							<?php endforeach; ?>
						</div>

						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Custom exclusions', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Skip files matching these keywords or patterns in their filename or URL.', 'slashimage-image-optimizer' ); ?></p>
								<p class="slash-image-row__followup" id="slash-image-exclusions-summary">
									<?php
									$slash_image_count = count( $slash_image_custom_exclusions_list );
									if ( $slash_image_count > 0 ) {
										echo esc_html( sprintf( /* translators: %d: count of patterns */ _n( 'Currently excluding %d pattern.', 'Currently excluding %d patterns.', $slash_image_count, 'slashimage-image-optimizer' ), $slash_image_count ) );
									} else {
										echo esc_html__( 'No custom patterns yet.', 'slashimage-image-optimizer' );
									}
									?>
								</p>
							</div>
							<div class="slash-image-row__control">
								<button type="button" class="slash-image-btn" id="slash-image-exclusions-edit"><?php echo esc_html__( 'Edit Exclusions', 'slashimage-image-optimizer' ); ?></button>
							</div>
						</div>
						<input type="hidden" name="<?php echo esc_attr( $slash_image_option_name ); ?>[custom_exclusions]" id="slash-image-custom-exclusions-input" value="<?php echo esc_attr( $slash_image_custom_exclusions_raw ); ?>" />
					</div>
				</section>

				<!-- Danger Zone -->
				<section class="slash-image-group danger">
					<?php $slash_image_group_head( 'warning', __( 'Danger Zone', 'slashimage-image-optimizer' ), __( 'Operations and power-user settings that change or destroy data.', 'slashimage-image-optimizer' ), true ); ?>
					<div class="slash-image-group__body">
						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Remove all data when plugin is deleted', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Off by default. Uninstalling SlashImage normally keeps your settings, stats, and per-attachment data so you can reinstall later without losing anything.', 'slashimage-image-optimizer' ); ?></p>
								<p class="slash-image-row__help"><strong><?php echo esc_html__( 'When this is enabled, deleting the plugin permanently removes:', 'slashimage-image-optimizer' ); ?></strong></p>
								<ul class="slash-image-row__list">
									<li><?php echo esc_html__( 'All SlashImage settings and preferences', 'slashimage-image-optimizer' ); ?></li>
									<li><?php echo esc_html__( 'Optimization stats and history', 'slashimage-image-optimizer' ); ?></li>
									<li><?php echo esc_html__( 'Per-attachment optimization data and backup index', 'slashimage-image-optimizer' ); ?></li>
									<li><?php echo esc_html__( 'The .htaccess rewrite block (when using server-rewrite mode)', 'slashimage-image-optimizer' ); ?></li>
								</ul>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Optimized images, WebP/AVIF variants, and original backups stay on disk. Disable this if you might reinstall SlashImage later.', 'slashimage-image-optimizer' ); ?></p>
							</div>
							<div class="slash-image-row__control">
								<?php $slash_image_f = $slash_image_option_name . '[uninstall_remove_all]'; ?>
								<input type="hidden" name="<?php echo esc_attr( $slash_image_f ); ?>" value="0" />
								<label class="slash-image-toggle">
									<input type="checkbox" name="<?php echo esc_attr( $slash_image_f ); ?>" value="1" <?php checked( $slash_image_uninstall_remove_all ); ?> />
									<span class="slash-image-toggle__track"><span class="slash-image-toggle__thumb"></span></span>
								</label>
							</div>
						</div>

						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Restore all originals from backup', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><?php echo esc_html__( 'Reverts every optimized attachment that has a SlashImage backup. WebP and AVIF variants are removed.', 'slashimage-image-optimizer' ); ?></p>
								<div id="slash-image-restore-result" class="slash-image-alert slash-image-alert--inline" role="status" aria-live="polite" hidden></div>
							</div>
							<div class="slash-image-row__control">
								<button type="button" class="slash-image-btn slash-image-btn--danger-outline" id="slash-image-restore-all" data-state="idle"><?php echo esc_html__( 'Restore all', 'slashimage-image-optimizer' ); ?></button>
							</div>
						</div>

						<div class="slash-image-row">
							<div class="slash-image-row__head">
								<div class="slash-image-row__label"><?php echo esc_html__( 'Delete all backups', 'slashimage-image-optimizer' ); ?></div>
								<p class="slash-image-row__help"><strong><?php echo esc_html__( 'Permanent.', 'slashimage-image-optimizer' ); ?></strong> <?php echo esc_html__( 'Removes every file under uploads/slashimage-backups/ and clears the backup index. You will not be able to restore originals after this.', 'slashimage-image-optimizer' ); ?></p>
								<div id="slash-image-delete-result" class="slash-image-alert slash-image-alert--inline" role="status" aria-live="polite" hidden></div>
							</div>
							<div class="slash-image-row__control">
								<button type="button" class="slash-image-btn slash-image-btn--danger" id="slash-image-delete-backups" data-state="idle"><?php echo esc_html__( 'Delete all', 'slashimage-image-optimizer' ); ?></button>
							</div>
						</div>
					</div>
				</section>
			</div>
		</div>

		<!-- Footer -->
		<footer class="slash-image-footer">
			<span class="slash-image-footer__meta" id="slash-image-savebar-meta">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php
				if ( $slash_image_saved_at > 0 ) {
					/* translators: %s: human time difference, e.g. "5 minutes" */
					printf( esc_html__( 'Last saved %s ago', 'slashimage-image-optimizer' ), esc_html( human_time_diff( $slash_image_saved_at, time() ) ) );
				} else {
					echo esc_html__( 'Not saved yet', 'slashimage-image-optimizer' );
				}
				?>
			</span>
			<div class="slash-image-footer__actions">
				<button type="submit" class="slash-image-btn slash-image-btn--soft" id="slash-image-save-bulk" data-bulk-url="<?php echo esc_url( $slash_image_bulk_url ); ?>"><?php echo esc_html__( 'Save & Bulk Optimize →', 'slashimage-image-optimizer' ); ?></button>
				<button type="submit" class="slash-image-btn slash-image-btn--primary slash-image-btn--lg"><?php echo esc_html__( 'Save changes', 'slashimage-image-optimizer' ); ?></button>
			</div>
		</footer>
	</form>

	<!-- Custom Exclusions modal -->
	<div class="slash-image-modal" id="slash-image-exclusions-modal" role="dialog" aria-modal="true" aria-labelledby="slash-image-exclusions-modal-title" hidden>
		<div class="slash-image-modal__backdrop" data-action="modal-close"></div>
		<div class="slash-image-modal__panel" role="document">
			<header class="slash-image-modal__header">
				<h2 class="slash-image-modal__title" id="slash-image-exclusions-modal-title"><?php echo esc_html__( 'Image Exclusions', 'slashimage-image-optimizer' ); ?></h2>
				<button type="button" class="slash-image-modal__close" data-action="modal-close" aria-label="<?php echo esc_attr__( 'Close', 'slashimage-image-optimizer' ); ?>">×</button>
			</header>
			<div class="slash-image-modal__body">
				<p><?php echo esc_html__( 'Enter the name or keyword of images to exclude from optimization. One per line. Matches are case-insensitive substrings against the filename and URL path.', 'slashimage-image-optimizer' ); ?></p>
				<p class="slash-image-modal__hint"><strong><?php echo esc_html__( 'Examples:', 'slashimage-image-optimizer' ); ?></strong> logo, favicon, icon-, brand/</p>
				<textarea id="slash-image-exclusions-textarea" class="slash-image-modal__textarea" rows="8" placeholder="logo&#10;favicon&#10;icon-" spellcheck="false"><?php echo esc_textarea( $slash_image_custom_exclusions_raw ); ?></textarea>
				<p class="slash-image-modal__hint">
					<?php
					echo esc_html( sprintf( /* translators: %1$d: max patterns, %2$d: max chars per pattern */ __( 'Up to %1$d patterns, %2$d characters each.', 'slashimage-image-optimizer' ), Slash_Image_Settings::CUSTOM_EXCLUSIONS_MAX_PATTERNS, Slash_Image_Settings::CUSTOM_EXCLUSIONS_MAX_PATTERN_LEN ) );
					?>
				</p>
			</div>
			<footer class="slash-image-modal__footer">
				<button type="button" class="slash-image-btn" data-action="modal-close"><?php echo esc_html__( 'Cancel', 'slashimage-image-optimizer' ); ?></button>
				<button type="button" class="slash-image-btn slash-image-btn--primary" id="slash-image-exclusions-save"><?php echo esc_html__( 'Save changes', 'slashimage-image-optimizer' ); ?></button>
			</footer>
		</div>
	</div>

	<!-- Reusable confirmation modal (Danger Zone). Inner panel is .slash-image-modal-box, NOT .slash-image-modal (that class is the exclusions-modal overlay above). -->
	<div id="slash-image-confirm-modal" class="slash-image-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="slash-image-modal-title" hidden>
		<div class="slash-image-modal-box">
			<h3 id="slash-image-modal-title" class="slash-image-modal-title"></h3>
			<p class="slash-image-modal-body"></p>
			<div class="slash-image-modal-actions">
				<button type="button" class="button slash-image-modal-cancel"><?php esc_html_e( 'Cancel', 'slashimage-image-optimizer' ); ?></button>
				<button type="button" class="button slash-image-modal-confirm"></button>
			</div>
		</div>
	</div>
</div>

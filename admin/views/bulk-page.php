<?php
/**
 * Media → Bulk Optimize page template (redesigned).
 *
 * Pure presentation. All dynamic numbers come from Slash_Image_Bulk_Processor::snapshot();
 * bulk.js re-renders the same elements on each poll. Interactive control IDs
 * (start/pause/resume/cancel/force/retry/clear, cron banner, failed card) are
 * unchanged so the existing bulk.js wiring keeps binding.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$slash_image_conn         = Slash_Image_Connection::snapshot();
$slash_image_is_connected = ! empty( $slash_image_conn['connected'] );
$slash_image_is_invalid   = ! empty( $slash_image_conn['invalid'] );
$slash_image_data         = Slash_Image_Bulk_Processor::snapshot();
$slash_image_cron_status  = Slash_Image_Cron_Probe::evaluate();

$slash_image_counts      = $slash_image_data['library'];
$slash_image_size_totals = $slash_image_data['totals'];

// A run that completed before this page loaded returns to the idle Start state
// (the redesign surfaces completion as a refreshed donut, not a summary card).
// GET is side-effect-free; the session stays 'completed' in the DB.
$slash_image_bulk_status = (string) ( $slash_image_data['status'] ?? 'idle' );
if ( 'completed' === $slash_image_bulk_status ) {
	$slash_image_bulk_status = 'idle';
}

$slash_image_is_running   = ( 'running' === $slash_image_bulk_status );
$slash_image_is_paused    = ( 'paused' === $slash_image_bulk_status );
$slash_image_is_idle      = ! $slash_image_is_running && ! $slash_image_is_paused;
$slash_image_show_running = ( $slash_image_is_running || $slash_image_is_paused );

// Donut conic stop = share of the library optimized.
$slash_image_optimized_pct = ( (int) $slash_image_counts['total'] > 0 )
	? (int) round( $slash_image_counts['optimized'] / $slash_image_counts['total'] * 100 )
	: 0;

// Hero savings % = 1 − optimized/original bytes.
$slash_image_savings_pct = ( (int) $slash_image_size_totals['original_bytes'] > 0 )
	? (int) round( ( 1 - $slash_image_size_totals['best_format_bytes'] / $slash_image_size_totals['original_bytes'] ) * 100 )
	: 0;

// Optimized-size bar fill (original bar is always 100%).
$slash_image_opt_bar_width = ( (int) $slash_image_size_totals['original_bytes'] > 0 )
	? round( $slash_image_size_totals['best_format_bytes'] / $slash_image_size_totals['original_bytes'] * 100, 1 )
	: 0;

$slash_image_processed       = (int) ( $slash_image_data['processed'] ?? 0 );
$slash_image_total           = (int) ( $slash_image_data['total'] ?? 0 );
$slash_image_pct             = (int) ( $slash_image_data['percent'] ?? 0 );
$slash_image_failed_count    = (int) ( $slash_image_data['failed_count'] ?? 0 );
$slash_image_queue_remaining = (int) ( $slash_image_data['queue_remaining'] ?? 0 );
$slash_image_skipped         = (int) ( $slash_image_data['skipped'] ?? 0 );
$slash_image_credits         = (int) ( $slash_image_data['credits_estimate'] ?? 0 );
$slash_image_total_thumbs    = (int) ( $slash_image_data['total_thumbnails'] ?? 0 );
$slash_image_recent          = ( isset( $slash_image_data['recent_completions'] ) && is_array( $slash_image_data['recent_completions'] ) ) ? $slash_image_data['recent_completions'] : array();

$slash_image_orig_h = size_format( (int) $slash_image_size_totals['original_bytes'], 1 );
$slash_image_opt_h  = size_format( (int) $slash_image_size_totals['best_format_bytes'], 1 );
$slash_image_orig_h = $slash_image_orig_h ? $slash_image_orig_h : '0 B';
$slash_image_opt_h  = $slash_image_opt_h ? $slash_image_opt_h : '0 B';

$slash_image_media_filter = function ( $value ) {
	return admin_url( 'upload.php?slash_image_status=' . $value );
};
?>

<div class="slash-image-app slash-image-app--bulk" data-cron-status="<?php echo esc_attr( $slash_image_cron_status ); ?>">
	<div class="bulk-stack">

		<div class="slash-image-bulk-cron-banner" id="slash-image-bulk-cron-banner" hidden>
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<span>
				<?php echo esc_html__( "WP-Cron is disabled on this host. Keep this tab open while bulk processing runs. If you close the tab, processing will pause and you'll need to return here to resume.", 'slashimage-image-optimizer' ); ?>
			</span>
		</div>

		<!-- HEADER -->
		<div class="head-bar">
			<span class="brand-mark" aria-hidden="true">/</span>
			<div class="head-titles">
				<h1><?php echo esc_html__( 'Bulk Optimize', 'slashimage-image-optimizer' ); ?></h1>
				<div class="crumb">
					<?php
					printf(
						/* translators: %1$s: number of images, %2$s: estimated number of thumbnails */
						esc_html__( '%1$s images & %2$s thumbnails in this library', 'slashimage-image-optimizer' ),
						'<span id="slash-image-crumb-images">' . esc_html( number_format_i18n( $slash_image_counts['total'] ) ) . '</span>',
						'<span id="slash-image-crumb-thumbs">' . esc_html( number_format_i18n( $slash_image_total_thumbs ) ) . '</span>'
					);
					?>
				</div>
			</div>
			<span class="auto-chip <?php echo $slash_image_is_connected ? '' : 'is-off'; ?>">
				<span class="dot" aria-hidden="true"></span>
				<?php
				if ( $slash_image_is_invalid ) {
					echo esc_html__( 'Reconnect needed', 'slashimage-image-optimizer' );
				} else {
					echo esc_html( $slash_image_is_connected ? __( 'Connected', 'slashimage-image-optimizer' ) : __( 'Not configured', 'slashimage-image-optimizer' ) );
				}
				?>
			</span>
		</div>

		<!-- STATUS & SAVINGS — always shown -->
		<div class="slash-image-card status-card" id="slash-image-status-card">
			<div class="status">
				<div class="status-left">
					<div class="donut" style="background: conic-gradient( var(--slash-color-primary) 0 <?php echo (int) $slash_image_optimized_pct; ?>%, var(--slash-color-track) <?php echo (int) $slash_image_optimized_pct; ?>% 100% );">
						<div class="hole">
							<span class="big"><?php echo (int) $slash_image_optimized_pct; ?>%</span>
							<span class="lbl"><?php echo esc_html__( 'Optimized', 'slashimage-image-optimizer' ); ?></span>
						</div>
					</div>
					<ul class="donut-legend">
						<li>
							<span class="sw sw-primary" aria-hidden="true"></span>
							<span><?php echo esc_html__( 'Optimized', 'slashimage-image-optimizer' ); ?></span>
							<b><?php echo esc_html( number_format_i18n( $slash_image_counts['optimized'] ) ); ?></b>
						</li>
						<li>
							<span class="sw sw-track" aria-hidden="true"></span>
							<span><?php echo esc_html__( 'Pending', 'slashimage-image-optimizer' ); ?></span>
							<b><?php echo esc_html( number_format_i18n( $slash_image_counts['not_optimized'] ) ); ?></b>
						</li>
						<li>
							<span class="sw sw-danger" aria-hidden="true"></span>
							<span><?php echo esc_html__( 'Errors', 'slashimage-image-optimizer' ); ?></span>
							<b><?php echo esc_html( number_format_i18n( $slash_image_counts['errors'] ) ); ?></b>
						</li>
					</ul>
				</div>
				<div class="status-right">
					<div class="saved-eyebrow"><?php echo esc_html__( 'Space saved', 'slashimage-image-optimizer' ); ?></div>
					<div class="hero-flex">
						<div class="hero-pct"><?php echo esc_html( $slash_image_savings_pct ); ?>%</div>
						<div class="hero-text">
							<div class="hero-strong"><?php esc_html_e( 'Smaller File Size', 'slashimage-image-optimizer' ); ?></div>
							<div class="hero-muted"><?php esc_html_e( 'Optimized by SlashImage.', 'slashimage-image-optimizer' ); ?></div>
						</div>
					</div>
					<div class="size-rows">
						<div class="size-row orig">
							<div class="row-top">
								<span class="l"><?php echo esc_html__( 'Original size', 'slashimage-image-optimizer' ); ?></span>
								<span class="v"><?php echo esc_html( $slash_image_orig_h ); ?></span>
							</div>
							<div class="track"><i style="width:100%"></i></div>
						</div>
						<div class="size-row opt">
							<div class="row-top">
								<span class="l"><?php echo esc_html__( 'Optimized size', 'slashimage-image-optimizer' ); ?></span>
								<span class="v"><?php echo esc_html( $slash_image_opt_h ); ?></span>
							</div>
							<div class="track"><i style="width:<?php echo (float) $slash_image_opt_bar_width; ?>%"></i></div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- ACTION CARD (idle + running blocks) -->
		<div class="slash-image-card action-card" id="slash-image-bulk-action-card" data-status="<?php echo esc_attr( $slash_image_bulk_status ); ?>">
			<div class="card-pad">

				<!-- IDLE -->
				<div class="idle <?php echo $slash_image_show_running ? 'hide' : ''; ?>">
					<?php // One-shot purge reminder — unhidden by bulk.js only on the running/paused -> completed transition (never on a plain reload of a completed run). ?>
					<div class="slash-image-bulk-complete-note" id="slash-image-bulk-complete-note" hidden>
						<?php echo esc_html__( 'Optimization complete.', 'slashimage-image-optimizer' ) . ' ' . esc_html( Slash_Image_Admin::cache_purge_reminder() ); ?>
					</div>
					<?php // Restore-run skip-and-report note — text set by bulk.js on completion when images were mid-optimize. ?>
					<div class="slash-image-bulk-complete-note" id="slash-image-bulk-deferred-note" hidden></div>
					<div class="action-head">
						<span class="ico" aria-hidden="true">
							<svg width="18" height="18" viewBox="0 0 14 14" fill="currentColor"><path d="M3 1.5v11l9-5.5z"/></svg>
						</span>
						<div>
							<h2><?php echo esc_html__( 'Optimize the rest of your library', 'slashimage-image-optimizer' ); ?></h2>
							<div class="sub"><?php echo esc_html__( 'Runs in the background — you can safely leave this page.', 'slashimage-image-optimizer' ); ?></div>
						</div>
					</div>

					<div class="cta-area">
						<button type="button" class="btn btn-primary" id="slash-image-bulk-start" <?php echo $slash_image_is_connected ? '' : 'disabled'; ?>>
							<svg width="13" height="13" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><path d="M3 1.5v11l9-5.5z"/></svg>
							<?php echo esc_html__( 'Start Bulk Optimization', 'slashimage-image-optimizer' ); ?>
						</button>
						<span class="credit-note">
							<?php echo esc_html__( 'Uses about', 'slashimage-image-optimizer' ); ?>
							<b><?php echo esc_html( number_format_i18n( $slash_image_credits ) . ' ' . __( 'credits', 'slashimage-image-optimizer' ) ); ?></b>
							<span class="info" tabindex="0" role="img" aria-label="<?php echo esc_attr__( 'How credits are counted', 'slashimage-image-optimizer' ); ?>">
								<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M8 7.2v3.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="8" cy="5.2" r="0.9" fill="currentColor"/></svg>
								<span class="tip">
									<strong><?php esc_html_e( 'How credits work', 'slashimage-image-optimizer' ); ?></strong>
									<p><?php esc_html_e( 'Each image you optimize costs 1 credit. If WordPress creates multiple sizes of that image (a thumbnail, a medium version, etc.), each size counts separately.', 'slashimage-image-optimizer' ); ?></p>
									<p><?php esc_html_e( 'AVIF and WebP versions are always free.', 'slashimage-image-optimizer' ); ?></p>
									<p><?php esc_html_e( 'To use fewer credits, disable the sizes you don\'t need in the', 'slashimage-image-optimizer' ); ?>
										<a href="<?php echo esc_url( admin_url( 'upload.php?page=slash-image-settings#image-sizes' ) ); ?>" class="tip-link"><?php esc_html_e( 'plugin settings', 'slashimage-image-optimizer' ); ?></a>.</p>
								</span>
							</span>
						</span>
					</div>

					<label class="reopt">
						<input type="checkbox" id="slash-image-bulk-force" />
						<span class="t">
							<strong>
								<?php
								printf(
									/* translators: %s: number of already-optimized images */
									esc_html__( 'Re-optimize the %s already-optimized images', 'slashimage-image-optimizer' ),
									'<span id="slash-image-reopt-count">' . esc_html( number_format_i18n( $slash_image_counts['optimized'] ) ) . '</span>'
								);
								?>
							</strong>
							<span class="h"><?php echo esc_html__( 'Reprocesses them with your current compression settings. Uses additional credits.', 'slashimage-image-optimizer' ); ?></span>
						</span>
					</label>

					<?php if ( ! $slash_image_is_connected ) : ?>
						<p class="slash-image-bulk-nokey">
							<?php
							printf(
								/* translators: %s: link to settings */
								esc_html__( 'Add an API key in %s to enable bulk processing.', 'slashimage-image-optimizer' ),
								'<a href="' . esc_url( admin_url( 'upload.php?page=' . Slash_Image_Admin::MENU_SLUG . '#settings' ) ) . '">' . esc_html__( 'Media → SlashImage', 'slashimage-image-optimizer' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<!-- RUNNING -->
				<div class="running <?php echo $slash_image_show_running ? 'show' : ''; ?>">
					<div class="run-head">
						<span class="run-spinner" aria-hidden="true">
							<svg width="18" height="18" viewBox="0 0 16 16" fill="none"><path d="M8 1.5a6.5 6.5 0 11-6.36 5.18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
						</span>
						<div>
							<h2><?php echo esc_html__( 'Optimizing your library…', 'slashimage-image-optimizer' ); ?></h2>
							<div class="sub"><?php echo esc_html__( 'Keep this tab open or come back later — it runs in the background.', 'slashimage-image-optimizer' ); ?></div>
						</div>
						<div class="count">
							<span class="run-count"><?php echo esc_html( number_format_i18n( $slash_image_processed ) ); ?></span>
							<span>/ <span class="run-total"><?php echo esc_html( number_format_i18n( $slash_image_total ) ); ?></span></span>
						</div>
					</div>

					<div class="run-track"><i style="width:<?php echo (int) $slash_image_pct; ?>%"></i></div>
					<div class="run-pctline">
						<span class="si-pct">
							<?php
							printf(
								/* translators: %d: percent complete */
								esc_html__( '%d%% complete', 'slashimage-image-optimizer' ),
								(int) $slash_image_pct
							);
							?>
						</span>
						<span class="si-remaining" id="slash-image-progress-rate"></span>
					</div>

					<div class="run-stats">
						<div class="c">
							<div class="k"><?php echo esc_html__( 'Optimized', 'slashimage-image-optimizer' ); ?></div>
							<div class="v run-stat-optimized"><?php echo esc_html( number_format_i18n( $slash_image_processed ) ); ?></div>
						</div>
						<div class="c">
							<div class="k"><?php echo esc_html__( 'Skipped', 'slashimage-image-optimizer' ); ?></div>
							<div class="v run-stat-skipped"><?php echo esc_html( number_format_i18n( $slash_image_skipped ) ); ?></div>
						</div>
						<div class="c">
							<div class="k"><?php echo esc_html__( 'Remaining', 'slashimage-image-optimizer' ); ?></div>
							<div class="v run-stat-remaining"><?php echo esc_html( number_format_i18n( $slash_image_queue_remaining ) ); ?></div>
						</div>
					</div>

					<div class="now-list">
						<?php foreach ( $slash_image_recent as $slash_image_row ) : ?>
							<?php if ( 'active' === ( $slash_image_row['state'] ?? '' ) ) : ?>
								<div class="now-row active">
									<span class="pulse" aria-hidden="true"></span>
									<span class="fname"><span class="doing"><?php echo esc_html__( 'Optimizing', 'slashimage-image-optimizer' ); ?></span> <?php echo esc_html( $slash_image_row['filename'] ); ?></span>
									<span class="meta"><?php echo esc_html( $slash_image_row['size'] ); ?></span>
								</div>
							<?php else : ?>
								<div class="now-row">
									<svg class="check" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
									<span class="fname"><?php echo esc_html( $slash_image_row['filename'] ); ?></span>
									<span class="meta">
										<?php echo esc_html( $slash_image_row['original_size'] ); ?> &rarr; <?php echo esc_html( $slash_image_row['optimized_size'] ); ?>
										<span class="save">&minus;<?php echo (int) $slash_image_row['saved_percent']; ?>%</span>
									</span>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>

					<p class="slash-image-progress-stale" id="slash-image-progress-stale" hidden>
						<?php echo esc_html__( 'Polling paused after 30 minutes of inactivity. Refresh to resume.', 'slashimage-image-optimizer' ); ?>
					</p>

					<div class="cta-area">
						<button type="button" class="btn btn-ghost" id="slash-image-bulk-pause" <?php echo $slash_image_is_running ? '' : 'hidden'; ?>>
							<?php echo esc_html__( 'Pause', 'slashimage-image-optimizer' ); ?>
						</button>
						<button type="button" class="btn btn-ghost" id="slash-image-bulk-resume" <?php echo $slash_image_is_paused ? '' : 'hidden'; ?>>
							<?php echo esc_html__( 'Resume', 'slashimage-image-optimizer' ); ?>
						</button>
						<button type="button" class="btn btn-ghost btn-danger" id="slash-image-bulk-cancel" <?php echo $slash_image_show_running ? '' : 'hidden'; ?>>
							<?php echo esc_html__( 'Cancel', 'slashimage-image-optimizer' ); ?>
						</button>
					</div>
				</div>

			</div>
		</div>

		<!-- FAILED CARD -->
		<div class="slash-image-card failed-card" id="slash-image-failed-card" <?php echo $slash_image_failed_count > 0 ? '' : 'hidden'; ?>>
			<div class="card-pad">
				<div class="failed-head">
					<span class="ico ico-warn" aria-hidden="true"><span class="dashicons dashicons-warning"></span></span>
					<div>
						<h2>
							<span id="slash-image-failed-count"><?php echo esc_html( number_format_i18n( $slash_image_failed_count ) ); ?></span>
							<?php echo esc_html__( 'images failed to optimize', 'slashimage-image-optimizer' ); ?>
						</h2>
						<p class="failed-sub">
							<?php
							printf(
								/* translators: %s: link to filtered media library */
								esc_html__( 'See details in %s.', 'slashimage-image-optimizer' ),
								'<a href="' . esc_url( $slash_image_media_filter( 'error' ) ) . '">' . esc_html__( 'Media Library → filter "Optimization error"', 'slashimage-image-optimizer' ) . '</a>'
							);
							?>
						</p>
					</div>
					<div class="failed-actions">
						<button type="button" class="btn btn-ghost" id="slash-image-bulk-retry-failed">
							<?php echo esc_html__( 'Retry failed', 'slashimage-image-optimizer' ); ?>
						</button>
						<button type="button" class="btn btn-ghost" id="slash-image-bulk-clear-failed">
							<?php echo esc_html__( 'Clear list', 'slashimage-image-optimizer' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

	</div>
</div>

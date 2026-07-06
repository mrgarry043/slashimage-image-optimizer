<?php
/**
 * Uninstall handler. Fires when the user clicks Delete on the Plugins screen.
 *
 * Default behavior is minimal — just unschedule cron and clear transient
 * caches — so a user who deletes Slash Image to debug or try alternatives
 * can reinstall later with their settings, stats, and image data intact.
 *
 * Full data removal is opt-in via Settings → Danger Zone →
 * "Remove all data when plugin is deleted". Even in full-removal mode,
 * Slash Image never touches files under wp-content/uploads/ — optimized
 * images, WebP/AVIF variants, and original backups all stay on disk.
 *
 * @package SlashImage
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-slash-image-uninstaller.php';

Slash_Image_Uninstaller::run();

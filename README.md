# SlashImage

Automatically compress your WordPress images and serve WebP and AVIF to every visitor.

This plugin is powered by the [SlashImage](https://slashimage.com) cloud optimization service. [Get a free API key](https://slashimage.com/dashboard) to start — 200 images every month, no credit card required.

SlashImage is a WordPress plugin that optimizes your images through the SlashImage service. It is a thin client over the SlashImage API at slashimage.com: your images are sent to the API for processing, the optimized versions are returned and saved back to your site, and the original is deleted from the SlashImage servers immediately after processing. All the heavy compression runs on the service, so your hosting is never slowed down.

Install it, paste a free API key, and every image you upload from then on is compressed and converted in the background. Your uploads never wait.

## Features

- **Automatic optimization on upload.** New images are queued and optimized in the background, so your upload returns immediately and your media library never blocks. You can switch auto-optimize off and run images manually instead.
- **WebP and AVIF, served automatically.** SlashImage creates WebP and AVIF copies of your JPEGs and PNGs and serves the best format each visitor's browser supports, with the original as a fallback. Serving works without server config for most sites, or you can use Apache `.htaccess` rules or the provided Nginx snippets.
- **Animated GIF support.** Animated GIFs are converted to animated WebP, usually a fraction of the original size with the animation intact.
- **Automatic PNG to JPEG.** PNGs are converted to a lighter JPEG only when the image will genuinely benefit, such as a photograph with no transparency. Graphics and logos are left as-is. (Off by default; opt in from settings.)
- **Three compression modes.** Lossy for the smallest files, Glossy for high-fidelity photography, and Lossless for pixel-perfect originals. You can re-optimize individual images in a different mode.
- **Bulk optimize.** Process your entire existing media library in the background from Media to Bulk Optimize. Pause, resume, or cancel any time, and it keeps going if you close the tab.
- **Smart Backups.** Every original is backed up before any change. Smart Backups stores only the original and regenerates thumbnails on restore, for the same protection with less disk use. Restore any image with one click, or restore your whole site from Settings.
- **Resize on upload (optional).** Optionally downscale large images to a maximum dimension as they are optimized, 1560px by default. Off by default; it only ever scales images down, never up.
- **Precision exclusions.** Exclude individual thumbnail sizes, or skip any image whose filename or path matches your own rules.
- **Multisite.** Every subsite is configured independently with its own API key, queue, and settings.

## Requirements

- WordPress 6.5 or higher (tested up to 7.0)
- PHP 7.4 or higher
- A free SlashImage API key from https://slashimage.com

The free tier covers 200 images every month, with no credit card required.

## Installation

**From a release (recommended for this repo):**

1. Download `slashimage-<version>.zip` from the [Releases](../../releases) page (the attached asset, not the auto-generated "Source code" archive).
2. In wp-admin, go to Plugins > Add New > Upload Plugin and upload the zip.
3. Activate SlashImage from the Plugins menu.

**From source:**

Clone this repository into `wp-content/plugins/slashimage-image-optimizer/` and activate it from the Plugins menu.

**Then connect it:**

1. Get a free API key at https://slashimage.com.
2. Go to Media > SlashImage and paste the key. The connection is verified automatically.
3. New uploads are now optimized in the background. To process existing media, go to Media > Bulk Optimize and click Start Bulk Optimization.

SlashImage is published on WordPress.org at https://wordpress.org/plugins/slashimage-image-optimizer/ — the canonical place to install and update it.

## How your images are handled

This plugin connects to the SlashImage API to compress and optimize images. Images are sent to the API at `api.slashimage.com` for processing and the optimized versions are returned. Images are not stored on the SlashImage servers after processing. Your filenames, ALT text, captions, and other media details are left untouched. Only the image file itself is optimized.

## Documentation

Full documentation is at https://slashimage.com/docs.

## Status

SlashImage 1.0.0 is live on WordPress.org at https://wordpress.org/plugins/slashimage-image-optimizer/. This repo mirrors each release.

## License

GPLv2 or later. See [LICENSE](LICENSE).

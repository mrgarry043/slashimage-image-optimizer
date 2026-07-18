=== SlashImage - Image Optimizer: Compress Images, Convert WebP & AVIF ===
Contributors: slashimage
Tags: image-optimization, compress-images, webp, avif, optimize-images
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically compress every image and serve WebP and AVIF to every visitor. Small files, the quality you choose, zero manual work.

== Description ==

Your images are almost certainly the heaviest thing on your website. They slow down every page, which can hurt your search rankings and frustrate visitors. SlashImage fixes that automatically, the moment you hit upload.

**Install it. Paste your free API key. That is the entire setup.** From that point on, every image you add is compressed, converted to WebP and AVIF, and served in the right format for each visitor. Automatically, in the background.

Typical sites see image file sizes drop by 80 to 90%, with no visible difference in quality.

= Smart Optimization Tuned to Each Image =

Instead of applying one fixed quality setting to every image, SlashImage analyzes each image and reduces its file size while keeping it visually close to the original, so you get smaller files without a visible drop in quality.

= ⚡ Completely Automatic, From the Second You Upload =

Upload an image and SlashImage gets to work in the background instantly. Your upload never waits. Your media library never blocks. New posts, pages, and products simply start loading faster, with nothing for you to click.

Want manual control? Switch off auto-optimize and run images yourself. By default, it just works.

= Smart Features =

**Smart Backups: Full Safety, Less Disk Space**
Smart Backups stores only your original image, not a copy of every generated thumbnail. When you restore, each thumbnail is regenerated automatically from that original — full protection while using less disk space.

**Automatic PNG to JPEG Conversion**
Photos saved as PNG can be many times larger than they need to be. SlashImage analyzes each PNG and converts it to a lighter JPEG only when the image will genuinely benefit, such as a photograph with no transparency. Illustrations, flat-color graphics, and logos are left as-is, so you get smaller files with no downside and nothing to manage yourself.

**Precision Exclusions**
Keep specific images untouched. Exclude individual thumbnail sizes, or skip any image whose filename or path matches your own rules.

= 🎚️ Three Compression Modes for Every Kind of Site =

* **Lossy (recommended)** The smallest files, with quality differences that are very hard to see. A good fit for blogs, businesses, and WooCommerce stores.
* **Glossy** High-fidelity compression that protects fine photographic detail. Made for photographers and portfolios.
* **Lossless** Zero quality loss, for when you need pixel-perfect originals.

= 📦 Optimize Your Entire Library in One Click =

Already sitting on thousands of images? Open Media to Bulk Optimize, click Start, and walk away. SlashImage works through your whole media library in the background, every image and every thumbnail, while you get on with your day. Pause, resume, or cancel any time. Close the tab and it keeps going.

= 🛡️ Your Originals Are Always Safe =

Every original is backed up before SlashImage changes a thing. Restore any image with one click from the Media Library, the attachment screen, or restore your whole site at once from Settings. Changed your mind about anything? It is one click back.

= 🎯 WebP and AVIF, Served Automatically to Everyone =

SlashImage creates WebP and AVIF copies of every image and serves each visitor the most efficient format their browser supports. AVIF is typically smaller than WebP at the same quality. Browsers that support it get it, and everyone else gets a fallback, so images always display. Animated GIFs are converted to animated WebP too, often much smaller while keeping the animation intact.

No server config required for most sites. Prefer server-level delivery? SlashImage supports Apache .htaccess rules and gives you ready-made Nginx snippets too.

= 🏢 Built for Agencies and Multiple Sites =

* **A separate key per site.** Generate sub-keys at slashimage.com, each with its own independent usage tracking.
* **Your billing stays private.** Plans, credits, and account details are never shown inside the plugin. Your clients only ever see the stats for their own site.
* **Full Multisite support.** Every subsite runs with its own key, queue, and settings.

= ✅ Works With Your Whole WordPress Stack =

Built to play nicely with your themes, page builders, and plugins, including WooCommerce, Elementor, Yoast SEO, WP Rocket, W3 Total Cache, WP Super Cache, Cloudflare, and WordPress Multisite.

= 🚀 Lighter Images, Faster Pages =

Lighter images can mean faster pages and better Core Web Vitals scores, for a smoother experience for every visitor. SlashImage gets you there without you touching a single setting.

**Get your free API key at https://slashimage.com**

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ or install via the Plugins screen in WordPress.
2. Activate SlashImage from the Plugins menu.
3. Get a free API key at https://slashimage.com.
4. Visit Media → SlashImage and paste the key. The connection is verified automatically.
5. New uploads are now optimized in the background. To process existing media, visit Media → Bulk Optimize and click Start Bulk Optimization.

== Frequently Asked Questions ==

= How many images can I optimize for free? =
The free plan optimizes 200 images each month at no cost. The plugin itself stays fully functional regardless of how many images you have optimized.

= How does the optimization actually work? =
When you upload an image, SlashImage sends it to our optimization servers, optimizes it, generates WebP and AVIF versions, and saves the results back to your site. All the heavy lifting happens on our servers, so your hosting is never slowed down.

= Do my images leave my server? =
Images are sent to the SlashImage API for compression, the optimized version is returned and saved to your server, and the original is deleted from our servers immediately after processing.

= Will it slow down my uploads? =
No. Images are queued and compressed in the background, so your upload returns immediately. The optimized version replaces the original within minutes.

= Can I optimize images I already have? =
Yes. Go to Media to Bulk Optimize and click Start. SlashImage processes your existing Media Library in the background.

= Can I restore my original images? =
Yes. SlashImage backs up every original before making changes. Restore any image from the Media Library or the attachment edit screen.

= Will my images stay optimized if I stop using SlashImage? =
Yes. Your optimized images are saved directly on your site and stay exactly as they are. The WebP and AVIF versions remain on disk too. Deactivating the plugin simply stops new images from being optimized.

= Can I choose which compression level to use? =
Yes. Pick from three modes in the settings: Lossy for the smallest files, Glossy for high-fidelity photography, and Lossless for pixel-perfect originals. You can change the mode at any time, and re-optimize individual images in a different mode from the Media Library.

= Does SlashImage change my image filenames or ALT text? =
No. Your filenames, ALT text, captions, and all other media details are left completely untouched. Only the image file itself is optimized.

= Does it work with my caching or CDN plugin? =
Yes. SlashImage works alongside caching plugins and CDNs, including Cloudflare, right out of the box. Because optimized images are saved over the same file path, you may occasionally want to purge your cache so visitors see the newest version, and SlashImage reminds you when that is worth doing.

= I manage several client sites. How do I keep usage separate? =
Generate a separate sub-key for each site at slashimage.com. Install the plugin on each site with its own key. Usage is tracked per key and billing details are never displayed in the plugin.

= Does it work on WordPress Multisite? =
Yes. Each subsite is configured independently with its own API key and processing queue.

= What image formats are supported? =
SlashImage optimizes JPEG, PNG, and GIF images, including every thumbnail size WordPress generates. It creates WebP and AVIF versions of your JPEGs and PNGs, and converts animated GIFs to animated WebP, all served automatically to modern browsers.

= What happens if a large image times out? =
Very large images (above roughly 100 megapixels) may hit timeout window. If that happens, the image is marked as failed and you can retry it individually. WordPress automatically scales images above 2560px on upload, so this only affects sites where that scaling is disabled.

= How do I report a bug or security issue? =
For general bugs, please open a thread on the plugin support forum. For security issues, contact us at https://slashimage.com so we can address them promptly.

== Screenshots ==

1. SlashImage Overview tab showing the Compression, Output Formats, and Frontend Serving cards.
2. Bulk Optimize page, ready to start optimizing your media library.
3. Bulk Optimize page during a run, with progress bar and recent rate.
4. SlashImage settings page (Media → SlashImage) with the API key entered and the connected pill in the hero strip.
5. Media Library list view with the SlashImage status column populated.
6. Attachment edit screen showing the SlashImage meta box with optimization details.

== Changelog ==

= 1.1.0 =
* New: WP-CLI support — manage optimization from the command line with `wp slashimage status`, `optimize [<ids>|--all] [--force]`, `restore [<ids>|--all] [--yes]`, and `cancel`. Useful for large libraries and hosts where WP-Cron is disabled.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== External services ==

This plugin connects to the SlashImage API (https://api.slashimage.com) to compress and optimize your images, which cannot be done locally by the plugin. When an image is optimized (on upload, during a bulk run, or on re-optimize) it is sent to the API and the optimized version is returned and saved to your site; the original is deleted from the API servers immediately after processing. The plugin also contacts the API to verify your API key when you connect it. Each request includes your API key and your site address (sent in the Origin header, so the API can honor keys restricted to specific domains); no page URLs or other personal data are sent. Your use of the API is covered by its terms of service (https://slashimage.com/terms-of-service) and privacy policy (https://slashimage.com/privacy-policy).

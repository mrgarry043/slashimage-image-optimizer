<?php
/**
 * Pure-PHP HTML rewriter that wraps <img> tags with <picture>+<source>
 * elements when sibling .avif / .webp variants exist on disk.
 *
 * No WP function calls inside this class — everything that needs
 * environment knowledge (uploads basedir, file existence) is supplied
 * by an injected `Slash_Image_Variant_Resolver` instance. This makes
 * the rewriter unit-testable without booting WordPress.
 *
 * Strategy mirrors how WordPress core's wp_filter_content_tags() walks
 * <img> elements via regex. Same fragility tradeoff, same proof point.
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Rewriter {

	const NO_REWRITE_CLASS = 'no-slash-image';
	const NO_REWRITE_ATTR  = 'data-no-slash-image';

	private $resolver;

	/** @var bool */
	private $emit_avif;

	/** @var bool */
	private $emit_webp;

	public function __construct( Slash_Image_Variant_Resolver $resolver, array $opts = array() ) {
		$this->resolver  = $resolver;
		$this->emit_avif = array_key_exists( 'emit_avif', $opts ) ? (bool) $opts['emit_avif'] : true;
		$this->emit_webp = array_key_exists( 'emit_webp', $opts ) ? (bool) $opts['emit_webp'] : true;
	}

	/**
	 * Walk the given HTML and wrap eligible <img> tags.
	 *
	 * @param string $html Input HTML.
	 * @return string      Rewritten HTML.
	 */
	public function rewrite( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}

		// Mask out any <picture>...</picture> blocks so we never double-wrap
		// nested <img> elements inside them.
		$placeholders = array();
		$counter      = 0;
		$masked       = preg_replace_callback(
			'#<picture\b[^>]*>.*?</picture>#is',
			function ( $m ) use ( &$placeholders, &$counter ) {
				$key = "\x00SLASHIMG_PICTURE_{$counter}\x00";
				$counter++;
				$placeholders[ $key ] = $m[0];
				return $key;
			},
			$html
		);

		if ( null === $masked ) {
			return $html; // PCRE failed; bail safely.
		}

		$rewritten = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) {
				return $this->maybe_wrap_img( $m[0] );
			},
			$masked
		);

		if ( null === $rewritten ) {
			return $html;
		}

		// Restore picture blocks.
		if ( ! empty( $placeholders ) ) {
			$rewritten = strtr( $rewritten, $placeholders );
		}

		return $rewritten;
	}

	/**
	 * Decide whether to wrap a single <img> and emit the wrapped markup, or
	 * return the tag unchanged. Public so callers (and tests) can poke directly.
	 */
	public function maybe_wrap_img( $img_html ) {
		$attrs = self::parse_attributes( $img_html );

		if ( empty( $attrs['src'] ) ) {
			return $img_html;
		}

		// Class / attribute escape hatches.
		$class = isset( $attrs['class'] ) ? (string) $attrs['class'] : '';
		if ( '' !== $class ) {
			$tokens = preg_split( '/\s+/', strtolower( $class ) );
			if ( in_array( self::NO_REWRITE_CLASS, $tokens, true ) ) {
				return $img_html;
			}
		}
		if ( isset( $attrs[ self::NO_REWRITE_ATTR ] ) ) {
			return $img_html;
		}

		// Programmatic skip.
		if ( function_exists( 'apply_filters' ) ) {
			$skip = apply_filters( 'slash_image_skip_image', false, $img_html, $attrs['src'] );
			if ( $skip ) {
				return $img_html;
			}
		}

		// Resolve the primary src — bail early when off-site / unsupported.
		$src_path = $this->resolver->url_to_path( $attrs['src'] );
		if ( null === $src_path ) {
			return $img_html;
		}

		// Build per-format srcset entries. The original <img> always carries the
		// untouched original srcset (or an inferred 1× entry from src).
		$srcset_entries = isset( $attrs['srcset'] ) ? self::parse_srcset( $attrs['srcset'] ) : array();
		if ( empty( $srcset_entries ) ) {
			$srcset_entries = array(
				array(
					'url'        => $attrs['src'],
					'descriptor' => '',
				),
			);
		}

		$avif_srcset = array();
		$webp_srcset = array();

		foreach ( $srcset_entries as $entry ) {
			$path = $this->resolver->url_to_path( $entry['url'] );
			if ( null === $path ) {
				continue;
			}
			$variants = $this->resolver->variants_for( $path );

			// GIF treats the WebP/AVIF toggles as one "modern variant" switch: the
			// API never makes AVIF for a GIF, so emit the WebP <source> when EITHER
			// toggle is on. Non-GIF sources keep the strict per-format gate, so a
			// JPEG with WebP off still emits no WebP source. (A GIF never has a
			// .gif.avif sibling, so the avif branch below stays a no-op for GIF.)
			$is_gif  = ( 'gif' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) );
			$webp_ok = $this->emit_webp || ( $is_gif && $this->emit_avif );

			if ( $this->emit_avif && ! empty( $variants['avif'] ) ) {
				$avif_srcset[] = self::format_srcset_entry( $entry['url'] . '.avif', $entry['descriptor'] );
			}
			if ( $webp_ok && ! empty( $variants['webp'] ) ) {
				$webp_srcset[] = self::format_srcset_entry( $entry['url'] . '.webp', $entry['descriptor'] );
			}
		}

		// If neither variant has any entry, leave the <img> alone.
		if ( empty( $avif_srcset ) && empty( $webp_srcset ) ) {
			return $img_html;
		}

		// sizes attribute is critical when srcset is present — without it the
		// browser assumes 100vw and downloads the largest variant. Copy from
		// the original <img>; if missing but srcset is, infer from the image
		// width so each <source> can still pick the right size.
		$sizes_value = '';
		if ( isset( $attrs['sizes'] ) && '' !== $attrs['sizes'] ) {
			$sizes_value = (string) $attrs['sizes'];
		} elseif ( ! empty( $srcset_entries ) ) {
			$inferred_width = self::infer_image_width( $attrs, $srcset_entries );
			if ( $inferred_width > 0 ) {
				$sizes_value = sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $inferred_width );
			}
		}

		$sizes_attr = '' !== $sizes_value ? ' sizes="' . self::esc_attr( $sizes_value ) . '"' : '';

		$sources = '';
		if ( ! empty( $avif_srcset ) ) {
			$sources .= '<source type="image/avif" srcset="' . self::esc_attr( implode( ', ', $avif_srcset ) ) . '"' . $sizes_attr . '>';
		}
		if ( ! empty( $webp_srcset ) ) {
			$sources .= '<source type="image/webp" srcset="' . self::esc_attr( implode( ', ', $webp_srcset ) ) . '"' . $sizes_attr . '>';
		}

		return '<picture>' . $sources . $img_html . '</picture>';
	}

	/**
	 * Best-effort image width for the default sizes value:
	 *   1. Use the width attribute when present.
	 *   2. Otherwise pick the largest w-descriptor in the srcset.
	 *   3. Otherwise return 0 (caller falls back to omitting sizes).
	 */
	private static function infer_image_width( array $attrs, array $srcset_entries ) {
		if ( ! empty( $attrs['width'] ) && is_numeric( $attrs['width'] ) ) {
			return (int) $attrs['width'];
		}
		$max = 0;
		foreach ( $srcset_entries as $entry ) {
			$desc = isset( $entry['descriptor'] ) ? (string) $entry['descriptor'] : '';
			if ( '' === $desc ) {
				continue;
			}
			if ( preg_match( '/^(\d+)w$/', trim( $desc ), $m ) ) {
				$w = (int) $m[1];
				if ( $w > $max ) {
					$max = $w;
				}
			}
		}
		return $max;
	}

	/* ── Static helpers (pure parsing, no I/O) ───────────────────── */

	public static function parse_attributes( $tag_html ) {
		$attrs = array();
		if ( ! preg_match( '#^<img\b([^>]*)/?>$#is', trim( $tag_html ), $m ) ) {
			return $attrs;
		}
		$body = $m[1];
		// Match name="value" | name='value' | name=value | name (boolean)
		if ( preg_match_all(
			'#([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?#',
			$body,
			$matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $matches as $pair ) {
				$name  = strtolower( $pair[1] );
				$value = '';
				if ( isset( $pair[2] ) && '' !== $pair[2] ) {
					$value = $pair[2];
				} elseif ( isset( $pair[3] ) && '' !== $pair[3] ) {
					$value = $pair[3];
				} elseif ( isset( $pair[4] ) && '' !== $pair[4] ) {
					$value = $pair[4];
				}
				$attrs[ $name ] = self::decode_attr( $value );
			}
		}
		return $attrs;
	}

	public static function parse_srcset( $srcset ) {
		$out = array();
		if ( ! is_string( $srcset ) || '' === $srcset ) {
			return $out;
		}
		// srcset entries are comma-separated; entries may contain commas inside
		// data: URLs but for our purposes we only care about regular URLs.
		$entries = array_filter( array_map( 'trim', explode( ',', $srcset ) ) );
		foreach ( $entries as $entry ) {
			$parts = preg_split( '/\s+/', $entry, 2 );
			if ( empty( $parts[0] ) ) {
				continue;
			}
			$out[] = array(
				'url'        => $parts[0],
				'descriptor' => isset( $parts[1] ) ? $parts[1] : '',
			);
		}
		return $out;
	}

	private static function format_srcset_entry( $url, $descriptor ) {
		return '' === $descriptor ? $url : $url . ' ' . $descriptor;
	}

	private static function decode_attr( $value ) {
		return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

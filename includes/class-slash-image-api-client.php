<?php
/**
 * HTTP client for the slashimage.com API.
 *
 * Public methods:
 *   verify_key( $api_key )                     — used by the settings page
 *   optimize( $file_path, $options, $kind )    — used by upload + bulk handlers
 *
 * On a successful optimize() call, this class also increments local stats
 * via Slash_Image_Stats::record_optimization(). pre_optimized responses
 * count as 1 optimization with 0 saved bytes (a credit was consumed).
 *
 * @package SlashImage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Slash_Image_Api_Client {

	const VERIFY_TIMEOUT      = 15;

	/**
	 * Per-request timeout (seconds) for an /v1/optimize call — the ONLY timeout;
	 * there is no in-request retry and no cross-attempt budget. An immediate
	 * retry can't help a slow or busy server (the same image takes the same
	 * time), and the synchronous request is held open through the proxy and a
	 * PHP-FPM worker, so retrying in-request risks tripping the proxy's own
	 * timeout. Failures are retried *later* instead — the next bulk pass or a
	 * manual Retry. 45 s timeout; filterable via
	 * `slash_image_optimize_timeout` so operators on long-timeout infra (or
	 * intentionally processing very large images) can raise it.
	 */
	const OPTIMIZE_TIMEOUT    = 45;

	/**
	 * Image MIME types the optimization API accepts as input. Allowlist —
	 * anything outside this set is treated as unsupported and skipped before
	 * any API call (SVG, PDF, TIFF, BMP, HEIC, …).
	 *
	 * Single source of truth: the upload gate, the worker pre-flight, the
	 * bulk feed/count queries, and the Media Library column renderer all
	 * derive from this constant via is_supported_mime(). An allowlist is
	 * deliberate — new upload types stay unsupported until explicitly added,
	 * rather than silently slipping through to the API.
	 */
	const SUPPORTED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' );

	public static function url( $path ) {
		$base = trailingslashit( SLASH_IMAGE_API_BASE_URL );
		return $base . ltrim( (string) $path, '/' );
	}

	/**
	 * Whether the configured API base URL uses HTTPS. The Bearer API key is only
	 * ever attached to a request when this is true — a base URL misconfigured to
	 * http:// (via the SLASH_IMAGE_API_BASE_URL override constant) must never send
	 * the key in cleartext. The shipped default is https; this guards an operator
	 * override. Consumed by the 'insecure_endpoint' short-circuit in optimize()
	 * and verify_key().
	 *
	 * @return bool
	 */
	private static function endpoint_is_secure() {
		return 'https' === strtolower( (string) wp_parse_url( SLASH_IMAGE_API_BASE_URL, PHP_URL_SCHEME ) );
	}

	/**
	 * The site's request Origin for API calls — "{scheme}://{host}" derived from
	 * home_url() (e.g. https://example.com), with no port, path, or query. The API
	 * derives the request host from this header (Referer fallback) and matches it
	 * against a domain-restricted key's allow/block list, so every request must
	 * carry it or a restricted key 401s with origin_required. The host is sent
	 * AS-IS — the API matches the exact host plus its www. variant, so we do NOT
	 * normalize www ourselves; the scheme defaults to https when home_url() omits
	 * one. A bare host would fail the API's URL parse, so the scheme is mandatory.
	 *
	 * Returns '' when home_url() has no parseable host (then no Origin is sent and
	 * an unrestricted key still works). The value — '' included — is passed
	 * through the slash_image_request_origin filter so sites behind a proxy or on
	 * multisite can override the Origin sent to the API.
	 *
	 * @return string Scheme+host, e.g. https://example.com, or '' if no host.
	 */
	private static function site_origin() {
		$home   = home_url();
		$host   = (string) wp_parse_url( $home, PHP_URL_HOST );
		$scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );

		$origin = '';
		if ( '' !== $host ) {
			$origin = ( '' !== $scheme ? $scheme : 'https' ) . '://' . $host;
		}

		/**
		 * Filter the Origin header value sent on every slashimage.com API request.
		 *
		 * Defaults to the site's scheme+host from home_url() (e.g.
		 * https://example.com), or '' when no host can be parsed (no Origin sent).
		 * Override for sites behind a reverse proxy or on multisite where the
		 * public host differs from home_url().
		 *
		 * @param string $origin Computed scheme+host, or '' if no parseable host.
		 */
		return (string) apply_filters( 'slash_image_request_origin', $origin );
	}

	/**
	 * The common request headers for every API call — Authorization + Accept, plus
	 * an Origin header (the site's scheme+host) so domain-restricted keys validate.
	 * Origin is added ONLY when site_origin() is non-empty, so a site whose host
	 * can't be parsed sends no Origin and an unrestricted key still works. The
	 * streaming optimize path adds no Content-Type (cURL sets the multipart
	 * boundary); the in-memory path merges its own Content-Type on top of this.
	 *
	 * @param string $api_key The Bearer API key.
	 * @return array Header name => value map.
	 */
	private static function base_headers( $api_key ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Accept'        => 'application/json',
		);

		$origin = self::site_origin();
		if ( '' !== $origin ) {
			$headers['Origin'] = $origin;
		}

		return $headers;
	}

	/**
	 * Whether the given MIME type is one the API can optimize. Allowlist
	 * check against SUPPORTED_MIME_TYPES.
	 *
	 * @param string $mime MIME type, e.g. from get_post_mime_type().
	 * @return bool
	 */
	public static function is_supported_mime( $mime ) {
		return in_array( (string) $mime, self::SUPPORTED_MIME_TYPES, true );
	}

	/**
	 * Verify an API key and read its plan/usage.
	 *
	 * Probes GET /v1/keys/me — the recommended key-validity endpoint (works for
	 * both master AND sub keys; /v1/usage/stats 403s sub keys). On a 200 it also
	 * returns the normalized `plan` object; a missing/malformed plan in a 200 is
	 * `'plan' => null` (unknown plan), NOT a failure. Error mapping is unchanged.
	 *
	 * @param string $api_key The key to verify.
	 * @return array { ok:bool, code:string, plan?:array|null, ... }
	 */
	public static function verify_key( $api_key, $timeout = self::VERIFY_TIMEOUT ) {
		$timeout = (int) $timeout > 0 ? (int) $timeout : self::VERIFY_TIMEOUT;

		if ( ! self::endpoint_is_secure() ) {
			return array(
				'ok'      => false,
				'code'    => 'insecure_endpoint',
				'message' => __( 'Refusing to send your API key: the optimization service URL is not HTTPS. Check the SLASH_IMAGE_API_BASE_URL setting.', 'slashimage-image-optimizer' ),
			);
		}

		$response = wp_remote_get(
			self::url( '/v1/keys/me' ),
			array(
				'timeout' => $timeout,
				'headers' => self::base_headers( $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 'network_error',
				'message' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $status ) {
			return array(
				'ok'   => true,
				'code' => 'valid',
				'plan' => self::extract_plan( wp_remote_retrieve_body( $response ) ),
			);
		}

		return array(
			'ok'     => false,
			'code'   => self::map_status_to_code( $status ),
			'status' => $status,
		);
	}

	/**
	 * Normalize the `plan` object from a /v1/keys/me (or /v1/usage/stats) 200
	 * body to { monthly_limit:int|null, images_used_this_month:int,
	 * images_remaining:int|null }, or null when the plan key is absent or
	 * malformed (treated as an unknown plan, not an error). monthly_limit null =
	 * unlimited (images_remaining null too); a capped plan with no
	 * images_remaining derives max(0, monthly_limit − images_used_this_month).
	 *
	 * @param string $body Raw response body.
	 * @return array|null
	 */
	public static function extract_plan( $body ) {
		$decoded = json_decode( (string) $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['plan'] ) || ! is_array( $decoded['plan'] ) ) {
			return null;
		}
		$plan = $decoded['plan'];
		if ( ! array_key_exists( 'monthly_limit', $plan ) ) {
			return null; // Malformed — no cap field.
		}

		$monthly_limit = ( null === $plan['monthly_limit'] ) ? null : (int) $plan['monthly_limit'];
		$used          = isset( $plan['images_used_this_month'] ) ? (int) $plan['images_used_this_month'] : 0;

		if ( null === $monthly_limit ) {
			$remaining = null;
		} elseif ( array_key_exists( 'images_remaining', $plan ) && null !== $plan['images_remaining'] ) {
			$remaining = (int) $plan['images_remaining'];
		} else {
			$remaining = max( 0, $monthly_limit - $used );
		}

		return array(
			'monthly_limit'          => $monthly_limit,
			'images_used_this_month' => $used,
			'images_remaining'       => $remaining,
		);
	}

	/**
	 * Optimize one image.
	 *
	 * @param string $file_path Absolute path to the image on disk.
	 * @param array  $options   Per-call overrides. Anything not provided is read from settings.
	 * @param string $kind      'main' | 'thumbnail'. Used for stats accounting only.
	 * @return array            ['ok' => bool, 'code' => string, 'data' => array|null, ...]
	 */
	public static function optimize( $file_path, array $options = array(), $kind = 'main' ) {
		$api_key = (string) Slash_Image_Settings::get( 'api_key', '' );
		if ( '' === $api_key ) {
			return array(
				'ok'   => false,
				'code' => 'no_api_key',
			);
		}

		// Never transmit the Bearer key over cleartext http. The shipped base is
		// https; this only trips on a misconfigured SLASH_IMAGE_API_BASE_URL
		// override. Terminal (see Slash_Image_Worker::categorize_result) — a
		// re-send won't fix a configuration problem.
		if ( ! self::endpoint_is_secure() ) {
			return array(
				'ok'      => false,
				'code'    => 'insecure_endpoint',
				'message' => __( 'Refusing to send your API key: the optimization service URL is not HTTPS. Check the SLASH_IMAGE_API_BASE_URL setting.', 'slashimage-image-optimizer' ),
			);
		}

		if ( ! is_string( $file_path ) || '' === $file_path || ! is_readable( $file_path ) ) {
			return array(
				'ok'   => false,
				'code' => 'file_unreadable',
			);
		}

		if ( (int) @filesize( $file_path ) <= 0 ) {
			return array(
				'ok'   => false,
				'code' => 'file_unreadable',
			);
		}

		// Decide the upload path. Streaming the file from disk via cURL (CURLFile)
		// avoids holding the input bytes AND the assembled multipart body in PHP
		// memory; the in-memory build is the fallback when the cURL transport
		// isn't available. No pre-flight memory projection: the worker bounds
		// per-tick memory reactively (see Slash_Image_Worker), matching the
		// runtime-check model.
		$stream = self::should_stream_upload();

		$fields   = self::build_fields( $options );
		$mime     = self::guess_mime( $file_path );
		$filename = wp_basename( $file_path );

		// Single attempt — no in-request retry, no cross-attempt budget. A slow
		// or busy server isn't fixed by an immediate re-send; transient failures
		// (network/timeout/5xx/429) are retried *later* by the next bulk pass or
		// a manual Retry, which the queue's retry/backoff already handles.
		$timeout = (int) apply_filters( 'slash_image_optimize_timeout', self::OPTIMIZE_TIMEOUT );

		$response = $stream
			? self::post_optimize_streaming( $file_path, $fields, $api_key, $timeout, $mime, $filename )
			: self::post_optimize_in_memory( $file_path, $fields, $api_key, $timeout, $mime, $filename );

		if ( is_wp_error( $response ) ) {
			// The in-memory path can fail to read the file after the pre-flight
			// is_readable() check (rare race); it returns this sentinel so we
			// still report 'file_unreadable' rather than mislabelling it a
			// network failure.
			if ( 'slash_image_unreadable' === $response->get_error_code() ) {
				return array(
					'ok'   => false,
					'code' => 'file_unreadable',
				);
			}
			// Classify the transport failure from the WP_Error message — a hit
			// timeout (ran the full OPTIMIZE_TIMEOUT) vs a genuine connection
			// failure — but NEVER surface that raw string: it's an internal
			// cURL/WP_HTTP message ("cURL error 28: Operation timed out after
			// 45002 milliseconds …"), not something a user should see. The worker
			// maps the returned code to the plugin's own copy via message_for_code().
			return array(
				'ok'   => false,
				'code' => self::classify_network_error( $response->get_error_message() ),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $status ) {
			return self::handle_success( $response, $kind );
		}

		// Non-200: one classification path so 429 / 503 / 5xx all map by the
		// body `code` (primary), with the HTTP status as the fallback. We also
		// surface the API's human `error` string — the worker stores it as the
		// queue row's error_message for permanent failures — and any Retry-After
		// header (503 service_busy carries one), which the worker uses as the
		// requeue backoff on the transient throttle paths instead of the default.
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $raw_body, true );
		$body_data = is_array( $decoded ) ? $decoded : array();

		$result = array(
			'ok'     => false,
			'code'   => self::classify_error( $status, $raw_body ),
			'status' => $status,
		);

		// Surface the RAW API `code` so the worker can tell a genuine invalid_key
		// from the domain codes that classify_error() collapses into invalid_key
		// (only the genuine one flips connection status — see is_key_dead_code()).
		if ( isset( $body_data['code'] ) && is_string( $body_data['code'] ) && '' !== $body_data['code'] ) {
			$result['api_code'] = (string) $body_data['code'];
		}

		if ( isset( $body_data['error'] ) && is_string( $body_data['error'] ) && '' !== $body_data['error'] ) {
			$result['message'] = (string) $body_data['error'];
		}

		if ( 'rate_limited' === $result['code'] ) {
			$retry_after = self::parse_retry_after( $response );
			if ( $retry_after > 0 ) {
				$result['retry_after'] = $retry_after;
			}
		}

		return $result;
	}

	/**
	 * POST /v1/optimize by streaming the file from disk via cURL (CURLFile).
	 *
	 * Stays on wp_remote_post() — the plugin's "all HTTP via wp_remote_*"
	 * convention — and injects the CURLFile into the cURL handle WP builds, via
	 * a scoped http_api_curl hook. The body passed to wp_remote_post is an empty
	 * placeholder: no file_get_contents() runs and nothing assembles the
	 * multipart body in PHP, so the input bytes and the body never coexist as
	 * PHP strings (the memory win — neither is held in PHP at all). cURL
	 * generates the multipart/form-data Content-Type + boundary itself once
	 * POSTFIELDS is an array, so we deliberately omit our own Content-Type here.
	 *
	 * The hook is added immediately before the call and removed in finally, so
	 * it never touches another plugin's wp_remote_* traffic — and it self-guards
	 * on the _slash_image_stream_upload arg regardless.
	 *
	 * @return array|WP_Error wp_remote_post() result.
	 */
	private static function post_optimize_streaming( $file_path, array $fields, $api_key, $timeout, $mime, $filename ) {
		add_action( 'http_api_curl', array( __CLASS__, 'inject_curl_upload' ), 10, 3 );
		try {
			return wp_remote_post(
				self::url( '/v1/optimize' ),
				array(
					'timeout'                    => max( 1, (int) $timeout ),
					// No Content-Type — cURL sets multipart/form-data + its own
					// boundary when CURLOPT_POSTFIELDS is an array. Origin survives
					// (the http_api_curl hook only sets POSTFIELDS, not HTTPHEADER).
					'headers'                    => self::base_headers( $api_key ),
					// Placeholder; the hook swaps POSTFIELDS for the CURLFile
					// array, so the real body is never built as a PHP string.
					'body'                       => '',
					'_slash_image_stream_upload' => array(
						'path'     => $file_path,
						'mime'     => $mime,
						'filename' => $filename,
						'fields'   => $fields,
					),
				)
			);
		} finally {
			remove_action( 'http_api_curl', array( __CLASS__, 'inject_curl_upload' ), 10 );
		}
	}

	/**
	 * POST /v1/optimize with the multipart body assembled in PHP memory — the
	 * fallback for hosts where WP won't use the cURL transport (so the
	 * http_api_curl hook would never fire). Reads the file and builds the body
	 * string up front, so it holds both in PHP memory (the higher-memory path).
	 *
	 * @return array|WP_Error wp_remote_post() result, or a slash_image_unreadable
	 *                        WP_Error when the file can't be read.
	 */
	private static function post_optimize_in_memory( $file_path, array $fields, $api_key, $timeout, $mime, $filename ) {
		$contents = @file_get_contents( $file_path );
		if ( false === $contents ) {
			return new WP_Error( 'slash_image_unreadable', 'Could not read image file for upload.' );
		}

		$multipart = Slash_Image_Multipart::build(
			$fields,
			array(
				'image' => array(
					'filename' => $filename,
					'mime'     => $mime,
					'contents' => $contents,
				),
			)
		);
		unset( $contents );

		return wp_remote_post(
			self::url( '/v1/optimize' ),
			array(
				'timeout' => max( 1, (int) $timeout ),
				'headers' => array_merge(
					self::base_headers( $api_key ),
					array( 'Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary'] )
				),
				'body'    => $multipart['body'],
			)
		);
	}

	/**
	 * http_api_curl callback: replace the placeholder POSTFIELDS with an array
	 * carrying a CURLFile so cURL streams the image from disk. No-ops on any
	 * request that doesn't carry our _slash_image_stream_upload arg, so it's
	 * safe even though WP fires this action for every cURL request while it's
	 * registered (and it's only registered for the duration of our own call).
	 *
	 * @param resource|\CurlHandle $handle cURL handle (passed by reference in core).
	 * @param array                $r      Parsed request args.
	 * @param string               $url    Request URL.
	 * @return void
	 */
	public static function inject_curl_upload( $handle, $r, $url ) {
		if ( empty( $r['_slash_image_stream_upload'] ) || ! is_array( $r['_slash_image_stream_upload'] ) ) {
			return; // Not our flagged request — never touch other plugins' traffic.
		}

		$is_handle = is_resource( $handle ) || ( class_exists( 'CurlHandle' ) && $handle instanceof \CurlHandle );
		if ( ! $is_handle || ! class_exists( 'CURLFile' ) || ! function_exists( 'curl_setopt' ) ) {
			return;
		}

		$spec = $r['_slash_image_stream_upload'];
		$path = isset( $spec['path'] ) ? (string) $spec['path'] : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			return;
		}

		$postfields = array();
		if ( ! empty( $spec['fields'] ) && is_array( $spec['fields'] ) ) {
			foreach ( $spec['fields'] as $name => $value ) {
				$postfields[ (string) $name ] = Slash_Image_Multipart::field_value( $value );
			}
		}
		$postfields['image'] = new CURLFile(
			$path,
			isset( $spec['mime'] ) ? (string) $spec['mime'] : 'application/octet-stream',
			isset( $spec['filename'] ) ? (string) $spec['filename'] : 'upload.bin'
		);

		curl_setopt( $handle, CURLOPT_POSTFIELDS, $postfields ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Inside core http_api_curl hook; streams the upload from disk via CURLFile to avoid OOM. Request remains a filterable wp_remote_post(). See method docblock + LAUNCH-PLUGIN.md reviewer note.
	}

	/**
	 * Whether to stream the upload via cURL (CURLFile) on this call. True only
	 * when WP will actually use the cURL transport — otherwise the http_api_curl
	 * hook never fires and the placeholder body would be sent. Mirrors WP's own
	 * transport selection (cURL preferred over streams; cURL must support SSL
	 * for an https endpoint; the use_curl_transport / http_api_transports
	 * filters), then defers the boolean to the pure decide_upload_path().
	 *
	 * Override with the 'slash_image_stream_upload' filter (return true|false)
	 * to force a path — used by tests and as an operator escape hatch.
	 *
	 * @return bool
	 */
	public static function should_stream_upload() {
		$endpoint = self::url( '/v1/optimize' );
		$is_https = ( 0 === stripos( $endpoint, 'https:' ) );

		$curl_usable = function_exists( 'curl_init' )
			&& function_exists( 'curl_exec' )
			&& function_exists( 'curl_setopt' )
			&& class_exists( 'CURLFile' )
			&& ! self::php_function_disabled( 'curl_init' )
			&& ! self::php_function_disabled( 'curl_exec' );

		$curl_ssl_ok = true;
		if ( $curl_usable && $is_https && function_exists( 'curl_version' ) ) {
			$v           = curl_version();
			$features    = ( is_array( $v ) && isset( $v['features'] ) ) ? (int) $v['features'] : 0;
			$ssl_flag    = defined( 'CURL_VERSION_SSL' ) ? CURL_VERSION_SSL : 0;
			$curl_ssl_ok = (bool) ( $features & $ssl_flag );
		}

		// WP tries transports in order and uses the first whose ::test() passes.
		// cURL only "wins" if present and not ordered after streams.
		$order                  = apply_filters( 'http_api_transports', array( 'curl', 'streams' ), array(), $endpoint ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WP filter, read intentionally
		$transport_prefers_curl = false;
		if ( is_array( $order ) ) {
			$ci                     = array_search( 'curl', $order, true );
			$si                     = array_search( 'streams', $order, true );
			$transport_prefers_curl = ( false !== $ci ) && ( false === $si || $ci < $si );
		}

		$use_curl_filter = (bool) apply_filters( 'use_curl_transport', true, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WP filter, read intentionally

		$force = apply_filters( 'slash_image_stream_upload', null );

		$decision = self::decide_upload_path(
			array(
				'force'                  => is_null( $force ) ? null : (bool) $force,
				'curl_usable'            => (bool) $curl_usable,
				'curl_ssl_ok'            => (bool) $curl_ssl_ok,
				'transport_prefers_curl' => (bool) $transport_prefers_curl,
				'use_curl_filter'        => $use_curl_filter,
			)
		);

		return 'stream' === $decision;
	}

	/**
	 * Pure transport-path decision (no runtime reads — fully unit-testable).
	 * Returns 'stream' or 'memory' from a pre-gathered environment snapshot.
	 *
	 * 'force' (true|false|null) short-circuits everything — the
	 * slash_image_stream_upload filter override. Otherwise streaming requires
	 * ALL of: cURL usable, cURL SSL OK (for an https endpoint), the transport
	 * order preferring cURL, and the use_curl_transport filter truthy. Any miss
	 * → 'memory' (the safe fallback that doesn't depend on the hook firing).
	 *
	 * @param array $env { force, curl_usable, curl_ssl_ok, transport_prefers_curl, use_curl_filter }
	 * @return string 'stream' | 'memory'
	 */
	public static function decide_upload_path( array $env ) {
		$force = array_key_exists( 'force', $env ) ? $env['force'] : null;
		if ( null !== $force ) {
			return $force ? 'stream' : 'memory';
		}

		if ( empty( $env['curl_usable'] ) ) {
			return 'memory';
		}
		if ( empty( $env['curl_ssl_ok'] ) ) {
			return 'memory';
		}
		if ( empty( $env['transport_prefers_curl'] ) ) {
			return 'memory';
		}
		if ( empty( $env['use_curl_filter'] ) ) {
			return 'memory';
		}

		return 'stream';
	}

	/**
	 * Whether a PHP function name appears in the disable_functions ini list.
	 *
	 * @param string $function_name Function to look for.
	 * @return bool
	 */
	private static function php_function_disabled( $function_name ) {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return in_array( $function_name, $disabled, true );
	}

	/**
	 * Classify a wp_remote_post WP_Error message into our internal code: a
	 * request that ran the full timeout without a response → 'timeout_exceeded'
	 * (cURL "Operation timed out" / "cURL error 28"); anything else (DNS,
	 * connection refused, SSL) → 'network_error'. Pure (string in, code out) so
	 * it's unit-testable. Both codes are transient/retryable.
	 */
	public static function classify_network_error( $error_message ) {
		$msg = (string) $error_message;
		if ( false !== stripos( $msg, 'timed out' )
			|| false !== stripos( $msg, 'timeout' )
			|| false !== stripos( $msg, 'curl error 28' )
		) {
			return 'timeout_exceeded';
		}
		return 'network_error';
	}

	private static function handle_success( $response, $kind ) {
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		// X-Images-Remaining: live account credits, returned on every successful
		// optimize. Passed up raw — "unlimited" or an integer string, or null when
		// the header is absent — for the caller to patch the local plan cache with
		// (Slash_Image_Connection::update_remaining); never parsed here.
		$remaining_header = wp_remote_retrieve_header( $response, 'x-images-remaining' );
		$images_remaining = ( is_string( $remaining_header ) && '' !== $remaining_header ) ? $remaining_header : null;

		if ( ! is_array( $decoded ) || empty( $decoded['optimized'] ) || ! is_array( $decoded['optimized'] ) ) {
			return array(
				'ok'   => false,
				'code' => 'unexpected_response',
			);
		}

		$original_kb = isset( $decoded['original']['size_kb'] ) ? (int) $decoded['original']['size_kb'] : 0;
		$saved_kb    = isset( $decoded['saved_kb'] ) ? (int) $decoded['saved_kb'] : 0;
		$saved_bytes = max( 0, $saved_kb * 1024 );
		$pre_opt     = ! empty( $decoded['pre_optimized'] );
		$png_to_jpeg = ! empty( $decoded['png_converted_to_jpeg'] );
		$saved_pct   = isset( $decoded['saved_percent'] ) ? (int) $decoded['saved_percent'] : 0;

		$variants = array();
		foreach ( array( 'original_format', 'webp', 'avif' ) as $key ) {
			if ( isset( $decoded['optimized'][ $key ]['data'] ) && is_string( $decoded['optimized'][ $key ]['data'] ) ) {
				$decoded_bytes = base64_decode( $decoded['optimized'][ $key ]['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes API-returned optimized image bytes (WebP/AVIF/original) from the documented JSON response, not executable code; strict mode rejects non-base64 input.
				if ( false !== $decoded_bytes ) {
					$variants[ $key ] = array(
						'format' => isset( $decoded['optimized'][ $key ]['format'] ) ? (string) $decoded['optimized'][ $key ]['format'] : '',
						'size'   => strlen( $decoded_bytes ),
						'bytes'  => $decoded_bytes,
					);
				}
			}
		}

		if ( ! isset( $variants['original_format'] ) ) {
			return array(
				'ok'   => false,
				'code' => 'unexpected_response',
			);
		}

		$bytes_for_stats = $pre_opt ? 0 : $saved_bytes;
		Slash_Image_Stats::record_optimization( $kind, $bytes_for_stats );

		return array(
			'ok'               => true,
			'code'             => $pre_opt ? 'pre_optimized' : 'optimized',
			'images_remaining' => $images_remaining,
			'data'             => array(
				'pre_optimized'         => $pre_opt,
				'png_converted_to_jpeg' => $png_to_jpeg,
				'original_size_kb'      => $original_kb,
				'saved_kb'              => $saved_kb,
				'saved_bytes'           => $saved_bytes,
				'saved_percent'         => $saved_pct,
				'variants'              => $variants,
				'notes'                 => isset( $decoded['notes'] ) && is_array( $decoded['notes'] ) ? $decoded['notes'] : array(),
			),
		);
	}

	private static function build_fields( array $options ) {
		$mode_default              = (string) Slash_Image_Settings::get( 'compression_mode', 'lossy' );
		$gen_webp_default          = (bool) Slash_Image_Settings::get( 'generate_webp', true );
		$gen_avif_default          = (bool) Slash_Image_Settings::get( 'generate_avif', true );
		$convert_png_default       = (bool) Slash_Image_Settings::get( 'convert_png_to_jpeg', false );
		// Resize is handled entirely WP-side (Slash_Image_Media_Handler::maybe_resize_full,
		// downscale-only). max_width/max_height are NEVER sent to the API — the server
		// only ever receives an already-sized file.
		$fields = array(
			'compression_mode'    => isset( $options['compression_mode'] ) ? (string) $options['compression_mode'] : $mode_default,
			'generate_webp'       => array_key_exists( 'generate_webp', $options ) ? (bool) $options['generate_webp'] : $gen_webp_default,
			'generate_avif'       => array_key_exists( 'generate_avif', $options ) ? (bool) $options['generate_avif'] : $gen_avif_default,
			'convert_png_to_jpeg' => array_key_exists( 'convert_png_to_jpeg', $options ) ? (bool) $options['convert_png_to_jpeg'] : $convert_png_default,
			// preserve_metadata hardcoded false for v1 — UI deferred to v1.1 pending
			// reliable API support on the jpegli encoder (jpegli retains ICC only;
			// full EXIF/XMP requires mozjpeg). Option remains registered/sanitized.
			'preserve_metadata'   => false,
		);

		return $fields;
	}

	private static function guess_mime( $file_path ) {
		$type = wp_check_filetype( $file_path );
		if ( ! empty( $type['type'] ) ) {
			return $type['type'];
		}
		return 'application/octet-stream';
	}

	/**
	 * Map an HTTP error response to an internal plugin error code.
	 *
	 * The API standardized its error envelope on 2026-05-31 to
	 * `{ success:false, error:<human>, code:<machine> }`, and the machine `code`
	 * is now the PRIMARY discriminator — the human `error` string is never
	 * matched (copy can change without notice). The HTTP status is only a
	 * fallback for a missing / unrecognised `code`, so an unexpected response is
	 * still classified sensibly. Full code → plugin-code table: map_api_code().
	 *
	 * Defensive: a non-200 without an explicit `success:false` is still treated
	 * as an error (the pre-2026-05-31 503 envelope shipped no `success` field).
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Raw response body.
	 * @return string Internal plugin error code.
	 */
	public static function classify_error( $status, $body ) {
		$status  = (int) $status;
		$decoded = json_decode( (string) $body, true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		// A non-200 with no explicit success flag is still an error; only an
		// explicit `success:true` (a contradiction on a non-200) would not be.
		$is_error = isset( $decoded['success'] )
			? ( false === $decoded['success'] )
			: ( $status >= 400 );

		$code = isset( $decoded['code'] ) ? (string) $decoded['code'] : '';
		if ( $is_error && '' !== $code ) {
			$mapped = self::map_api_code( $code );
			if ( null !== $mapped ) {
				return $mapped;
			}
		}

		// No usable `code` (absent, or an unrecognised/future one) → classify
		// off the HTTP status.
		return self::status_fallback_code( $status );
	}

	/**
	 * Map a standardized API `code` to the internal plugin error code, or null
	 * when the code is unrecognised (the caller then falls back to the HTTP
	 * status). The four 401 codes collapse to invalid_key; the 500-series
	 * infrastructure codes and the two malformed-request 400 codes all become a
	 * transient server_error; service_busy (503) and credit_limit_reached (429)
	 * are the throttle codes → rate_limited (the worker honors Retry-After).
	 *
	 * @param string $code API `code` value.
	 * @return string|null Plugin error code, or null if unrecognised.
	 */
	private static function map_api_code( $code ) {
		switch ( (string) $code ) {
			case 'invalid_key':
			case 'domain_not_allowed':
			case 'domain_blocked':
			case 'origin_required':
				return 'invalid_key';
			case 'monthly_limit_reached':
				return 'payment_required';
			case 'credit_limit_reached':
			case 'service_busy':
				return 'rate_limited';
			case 'size_exceeded_server':
				return 'size_exceeded_server';
			case 'size_exceeded_for_plan':
				return 'size_exceeded_for_plan';
			case 'not_processable_format':
			case 'unsupported_format':
				return 'not_processable_format';
			case 'gif_too_large':
				return 'gif_too_large';
			case 'no_image':
			case 'invalid_params':
			case 'server_error':
			case 'auth_error':
			case 'billing_error':
			case 'credit_error':
				return 'server_error';
			default:
				return null;
		}
	}

	/**
	 * HTTP-status fallback for when the body carried no usable `code`. Keeps the
	 * permanent classification for the size/format caps (a proxy-generated 413
	 * or a code-less 415 is still a permanent skip), then delegates to
	 * map_status_to_code() for the auth/billing/throttle/server statuses.
	 *
	 * @param int $status HTTP status code.
	 * @return string Internal plugin error code.
	 */
	private static function status_fallback_code( $status ) {
		$status = (int) $status;

		// Oversize cap: permanent even without a recognised sub-code.
		if ( 413 === $status ) {
			return 'file_too_large';
		}
		// Unprocessable input: permanent.
		if ( 415 === $status ) {
			return 'not_processable_format';
		}

		return self::map_status_to_code( $status );
	}

	private static function map_status_to_code( $status ) {
		switch ( (int) $status ) {
			case 401:
				return 'invalid_key';
			case 402:
				return 'payment_required';
			case 429:
				return 'rate_limited';
			default:
				if ( $status >= 500 ) {
					return 'server_error';
				}
				return 'unexpected_status';
		}
	}

	private static function parse_retry_after( $response ) {
		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( '' === $value || null === $value ) {
			return 0;
		}
		if ( ctype_digit( (string) $value ) ) {
			return (int) $value;
		}
		$ts = strtotime( (string) $value );
		if ( false === $ts ) {
			return 0;
		}
		return max( 0, $ts - time() );
	}
}

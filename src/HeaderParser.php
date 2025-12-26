<?php
/**
 * Header parser for HTTP caching headers.
 *
 * @package CacheChecker
 */

namespace CacheChecker;

/**
 * Parses HTTP headers into a structured format for analysis.
 */
class HeaderParser {

	/**
	 * CDN cache status headers to check.
	 *
	 * @var array
	 */
	private const CDN_HEADERS = [
		'cf-cache-status',      // Cloudflare.
		'x-cache',              // AWS CloudFront, Varnish.
		'x-vercel-cache',       // Vercel.
		'x-fastly-request-id',  // Fastly.
		'x-served-by',          // Fastly.
		'x-cdn',                // Generic.
		'x-edge-location',      // Generic.
		'x-akamai-request-id',  // Akamai.
		'x-cache-hits',         // Various CDNs.
	];

	/**
	 * Parse HTTP headers into structured format.
	 *
	 * @param \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array $headers Raw headers.
	 *
	 * @return array Parsed header structure.
	 */
	public function parse( $headers ): array {
		// Convert to array if needed.
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		} elseif ( is_object( $headers ) ) {
			$headers = (array) $headers;
		}

		// Normalize header keys to lowercase.
		$normalized = [];
		foreach ( $headers as $key => $value ) {
			$normalized[ strtolower( $key ) ] = is_array( $value ) ? $value[0] : $value;
		}

		return [
			'cache_control'   => $this->parse_cache_control( $normalized['cache-control'] ?? '' ),
			'expires'         => $this->parse_expires( $normalized['expires'] ?? '' ),
			'etag'            => $this->parse_etag( $normalized['etag'] ?? '' ),
			'last_modified'   => $normalized['last-modified'] ?? null,
			'vary'            => $this->parse_vary( $normalized['vary'] ?? '' ),
			'age'             => isset( $normalized['age'] ) ? (int) $normalized['age'] : null,
			'cdn'             => $this->detect_cdn( $normalized ),
			'cors'            => $this->parse_cors( $normalized ),
			'content_type'    => $normalized['content-type'] ?? null,
			'raw'             => $normalized,
		];
	}

	/**
	 * Parse Cache-Control header into directives.
	 *
	 * @param string $header Cache-Control header value.
	 *
	 * @return array Parsed directives.
	 */
	private function parse_cache_control( string $header ): array {
		if ( empty( $header ) ) {
			return [
				'raw'                     => '',
				'directives'              => [],
				'max_age'                 => null,
				's_maxage'                => null,
				'public'                  => false,
				'private'                 => false,
				'no_cache'                => false,
				'no_store'                => false,
				'immutable'               => false,
				'must_revalidate'         => false,
				'stale_while_revalidate'  => null,
				'stale_if_error'          => null,
			];
		}

		$directives = [];
		$parts      = array_map( 'trim', explode( ',', $header ) );

		foreach ( $parts as $part ) {
			if ( strpos( $part, '=' ) !== false ) {
				list( $key, $value ) = explode( '=', $part, 2 );
				$directives[ strtolower( trim( $key ) ) ] = trim( $value, '" ' );
			} else {
				$directives[ strtolower( trim( $part ) ) ] = true;
			}
		}

		return [
			'raw'                     => $header,
			'directives'              => $directives,
			'max_age'                 => isset( $directives['max-age'] ) ? (int) $directives['max-age'] : null,
			's_maxage'                => isset( $directives['s-maxage'] ) ? (int) $directives['s-maxage'] : null,
			'public'                  => isset( $directives['public'] ),
			'private'                 => isset( $directives['private'] ),
			'no_cache'                => isset( $directives['no-cache'] ),
			'no_store'                => isset( $directives['no-store'] ),
			'immutable'               => isset( $directives['immutable'] ),
			'must_revalidate'         => isset( $directives['must-revalidate'] ),
			'stale_while_revalidate'  => isset( $directives['stale-while-revalidate'] )
				? (int) $directives['stale-while-revalidate']
				: null,
			'stale_if_error'          => isset( $directives['stale-if-error'] )
				? (int) $directives['stale-if-error']
				: null,
		];
	}

	/**
	 * Parse Expires header.
	 *
	 * @param string $header Expires header value.
	 *
	 * @return array|null Parsed expiration info.
	 */
	private function parse_expires( string $header ): ?array {
		if ( empty( $header ) ) {
			return null;
		}

		$timestamp = strtotime( $header );

		if ( $timestamp === false ) {
			return [
				'raw'       => $header,
				'timestamp' => null,
				'valid'     => false,
			];
		}

		return [
			'raw'        => $header,
			'timestamp'  => $timestamp,
			'valid'      => true,
			'is_expired' => $timestamp < time(),
			'seconds'    => max( 0, $timestamp - time() ),
		];
	}

	/**
	 * Parse ETag header.
	 *
	 * @param string $header ETag header value.
	 *
	 * @return array|null Parsed ETag info.
	 */
	private function parse_etag( string $header ): ?array {
		if ( empty( $header ) ) {
			return null;
		}

		$is_weak = strpos( $header, 'W/' ) === 0;
		$value   = $is_weak ? substr( $header, 2 ) : $header;
		$value   = trim( $value, '"' );

		return [
			'raw'     => $header,
			'value'   => $value,
			'is_weak' => $is_weak,
		];
	}

	/**
	 * Parse Vary header.
	 *
	 * @param string $header Vary header value.
	 *
	 * @return array Parsed Vary directives.
	 */
	private function parse_vary( string $header ): array {
		if ( empty( $header ) ) {
			return [
				'raw'     => '',
				'headers' => [],
			];
		}

		$headers = array_map(
			function ( $h ) {
				return strtolower( trim( $h ) );
			},
			explode( ',', $header )
		);

		return [
			'raw'                    => $header,
			'headers'                => $headers,
			'has_accept_encoding'    => in_array( 'accept-encoding', $headers, true ),
			'has_cookie'             => in_array( 'cookie', $headers, true ),
			'has_user_agent'         => in_array( 'user-agent', $headers, true ),
			'has_wildcard'           => in_array( '*', $headers, true ),
		];
	}

	/**
	 * Detect CDN from headers.
	 *
	 * @param array $headers Normalized headers.
	 *
	 * @return array CDN detection results.
	 */
	private function detect_cdn( array $headers ): array {
		$result = [
			'detected'     => false,
			'provider'     => null,
			'cache_status' => null,
			'indicators'   => [],
		];

		// Check each CDN header.
		foreach ( self::CDN_HEADERS as $cdn_header ) {
			if ( isset( $headers[ $cdn_header ] ) ) {
				$result['detected']                   = true;
				$result['indicators'][ $cdn_header ] = $headers[ $cdn_header ];
			}
		}

		// Try to identify specific provider.
		if ( isset( $headers['cf-cache-status'] ) ) {
			$result['provider']     = 'Cloudflare';
			$result['cache_status'] = $headers['cf-cache-status'];
		} elseif ( isset( $headers['x-vercel-cache'] ) ) {
			$result['provider']     = 'Vercel';
			$result['cache_status'] = $headers['x-vercel-cache'];
		} elseif ( isset( $headers['x-fastly-request-id'] ) ) {
			$result['provider']     = 'Fastly';
			$result['cache_status'] = $headers['x-cache'] ?? null;
		} elseif ( isset( $headers['x-akamai-request-id'] ) ) {
			$result['provider']     = 'Akamai';
			$result['cache_status'] = $headers['x-cache'] ?? null;
		} elseif ( isset( $headers['x-cache'] ) ) {
			// Generic x-cache, likely AWS CloudFront or Varnish.
			$result['provider']     = 'Unknown CDN';
			$result['cache_status'] = $headers['x-cache'];
		}

		return $result;
	}

	/**
	 * Parse CORS headers.
	 *
	 * @param array $headers Normalized headers.
	 *
	 * @return array CORS header info.
	 */
	private function parse_cors( array $headers ): array {
		$acao = $headers['access-control-allow-origin'] ?? null;

		return [
			'allow_origin'      => $acao,
			'has_cors'          => $acao !== null,
			'allows_all'        => $acao === '*',
			'allow_credentials' => isset( $headers['access-control-allow-credentials'] )
				&& strtolower( $headers['access-control-allow-credentials'] ) === 'true',
		];
	}
}

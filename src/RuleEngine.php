<?php
/**
 * Rule engine for analyzing cache headers against best practices.
 *
 * @package CacheChecker
 */

namespace CacheChecker;

/**
 * Analyzes cache headers and identifies issues based on best practices.
 */
class RuleEngine {

	/**
	 * Issue severity levels.
	 */
	public const LEVEL_ERROR   = 'error';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_INFO    = 'info';

	/**
	 * Recommended max-age values by resource type (in seconds).
	 */
	private const RECOMMENDED_MAX_AGE = [
		ResourceType::JS    => 31536000, // 1 year for fingerprinted.
		ResourceType::CSS   => 31536000, // 1 year for fingerprinted.
		ResourceType::FONT  => 31536000, // 1 year for fingerprinted.
		ResourceType::IMAGE => 86400,    // 1 day.
		ResourceType::HTML  => 60,       // 1 minute for browser.
	];

	/**
	 * Minimum acceptable max-age for static assets (1 hour).
	 */
	private const MIN_STATIC_MAX_AGE = 3600;

	/**
	 * Analyze headers for a resource and return issues.
	 *
	 * @param array  $headers       Parsed headers from HeaderParser.
	 * @param string $resource_type Resource type constant.
	 * @param string $url           Resource URL.
	 * @param bool   $fingerprinted Whether the resource appears fingerprinted.
	 *
	 * @return array List of issues found.
	 */
	public function analyze( array $headers, string $resource_type, string $url, bool $fingerprinted = false ): array {
		$issues = [];

		// Run checks based on resource type.
		$issues = array_merge( $issues, $this->check_common_issues( $headers ) );

		if ( ResourceType::is_static( $resource_type ) ) {
			$issues = array_merge(
				$issues,
				$this->check_static_asset_issues( $headers, $resource_type, $fingerprinted )
			);
		}

		if ( $resource_type === ResourceType::HTML ) {
			$issues = array_merge( $issues, $this->check_html_issues( $headers ) );
		}

		if ( $resource_type === ResourceType::IMAGE ) {
			$issues = array_merge( $issues, $this->check_image_issues( $headers ) );
		}

		if ( $resource_type === ResourceType::API ) {
			$issues = array_merge( $issues, $this->check_api_issues( $headers ) );
		}

		if ( $resource_type === ResourceType::FONT ) {
			$issues = array_merge( $issues, $this->check_font_issues( $headers ) );
		}

		// Check CDN-specific issues.
		if ( $headers['cdn']['detected'] ) {
			$issues = array_merge( $issues, $this->check_cdn_issues( $headers, $resource_type ) );
		}

		return $issues;
	}

	/**
	 * Check for common cache header issues.
	 *
	 * @param array $headers Parsed headers.
	 *
	 * @return array Issues found.
	 */
	private function check_common_issues( array $headers ): array {
		$issues = [];
		$cc     = $headers['cache_control'];

		// Conflicting directives.
		if ( $cc['no_store'] && $cc['max_age'] !== null && $cc['max_age'] > 0 ) {
			$issues[] = $this->create_issue(
				'conflicting_directives',
				'no-store conflicts with max-age',
				'Cache-Control contains both no-store and max-age, which is contradictory.',
				self::LEVEL_ERROR
			);
		}

		if ( $cc['public'] && $cc['private'] ) {
			$issues[] = $this->create_issue(
				'conflicting_directives',
				'public and private directives conflict',
				'Cache-Control cannot be both public and private.',
				self::LEVEL_ERROR
			);
		}

		// Vary: * makes resource uncacheable.
		if ( $headers['vary']['has_wildcard'] ?? false ) {
			$issues[] = $this->create_issue(
				'vary_wildcard',
				'Vary: * makes resource effectively uncacheable',
				'Using Vary: * means each request is treated as unique, defeating caching.',
				self::LEVEL_ERROR
			);
		}

		// Vary: User-Agent causes cache fragmentation.
		if ( $headers['vary']['has_user_agent'] ?? false ) {
			$issues[] = $this->create_issue(
				'vary_user_agent',
				'Vary: User-Agent causes severe cache fragmentation',
				'User-Agent has thousands of variants. Consider normalizing at CDN level instead.',
				self::LEVEL_WARNING
			);
		}

		// Vary: Cookie can cause fragmentation.
		if ( $headers['vary']['has_cookie'] ?? false ) {
			$issues[] = $this->create_issue(
				'vary_cookie',
				'Vary: Cookie may cause cache fragmentation',
				'Cookie values vary per user. Consider bypassing cache for authenticated users instead.',
				self::LEVEL_WARNING
			);
		}

		return $issues;
	}

	/**
	 * Check issues specific to static assets (JS, CSS, fonts).
	 *
	 * @param array  $headers       Parsed headers.
	 * @param string $resource_type Resource type.
	 * @param bool   $fingerprinted Whether asset is fingerprinted.
	 *
	 * @return array Issues found.
	 */
	private function check_static_asset_issues( array $headers, string $resource_type, bool $fingerprinted ): array {
		$issues = [];
		$cc     = $headers['cache_control'];

		// Check no-store on static assets.
		if ( $cc['no_store'] ) {
			$issues[] = $this->create_issue(
				'no_store_static',
				'no-store on static asset is wasteful',
				'Static assets should be cached. no-store prevents all caching.',
				self::LEVEL_ERROR
			);
		}

		// Check no-cache on fingerprinted assets.
		if ( $fingerprinted && $cc['no_cache'] ) {
			$issues[] = $this->create_issue(
				'no_cache_fingerprinted',
				'no-cache on fingerprinted asset causes unnecessary revalidation',
				'Fingerprinted assets have unique URLs and can be cached immutably.',
				self::LEVEL_WARNING
			);
		}

		// Check for missing immutable on fingerprinted assets.
		if ( $fingerprinted && ! $cc['immutable'] ) {
			$issues[] = $this->create_issue(
				'missing_immutable',
				'Missing immutable directive on fingerprinted asset',
				'Fingerprinted assets should use immutable to prevent revalidation on reload.',
				self::LEVEL_WARNING
			);
		}

		// Check max-age is reasonable for fingerprinted assets.
		if ( $fingerprinted ) {
			if ( $cc['max_age'] === null ) {
				$issues[] = $this->create_issue(
					'missing_max_age',
					'Missing max-age on fingerprinted static asset',
					'Fingerprinted assets should have max-age=31536000 (1 year).',
					self::LEVEL_ERROR
				);
			} elseif ( $cc['max_age'] < 86400 ) {
				$issues[] = $this->create_issue(
					'short_max_age_fingerprinted',
					'Short max-age on fingerprinted asset',
					sprintf(
						'Fingerprinted assets should have max-age=31536000. Current: %d seconds.',
						$cc['max_age']
					),
					self::LEVEL_WARNING
				);
			}
		} else {
			// Non-fingerprinted static assets.
			if ( $cc['max_age'] !== null && $cc['max_age'] < self::MIN_STATIC_MAX_AGE ) {
				$issues[] = $this->create_issue(
					'short_max_age',
					'Short max-age on static asset',
					sprintf(
						'Static assets should have at least 1 hour caching. Current: %d seconds.',
						$cc['max_age']
					),
					self::LEVEL_WARNING
				);
			}

			// Non-fingerprinted should have ETag for revalidation.
			if ( $headers['etag'] === null && $headers['last_modified'] === null ) {
				$issues[] = $this->create_issue(
					'missing_validators',
					'Missing ETag and Last-Modified on non-fingerprinted asset',
					'Without fingerprinting, assets need validators for efficient revalidation.',
					self::LEVEL_INFO
				);
			}
		}

		// Check private on public static assets.
		if ( $cc['private'] && ! $cc['no_store'] ) {
			$issues[] = $this->create_issue(
				'private_static',
				'private directive on static asset',
				'Static assets should be public to allow CDN caching.',
				self::LEVEL_WARNING
			);
		}

		return $issues;
	}

	/**
	 * Check issues specific to HTML documents.
	 *
	 * @param array $headers Parsed headers.
	 *
	 * @return array Issues found.
	 */
	private function check_html_issues( array $headers ): array {
		$issues = [];
		$cc     = $headers['cache_control'];

		// Check for missing stale-while-revalidate.
		if ( $cc['stale_while_revalidate'] === null && ! $cc['no_store'] && ! $cc['private'] ) {
			$issues[] = $this->create_issue(
				'missing_swr',
				'Missing stale-while-revalidate on HTML',
				'stale-while-revalidate improves perceived performance during revalidation.',
				self::LEVEL_INFO
			);
		}

		// Check for missing validators.
		if ( $headers['etag'] === null && $headers['last_modified'] === null ) {
			if ( ! $cc['no_store'] ) {
				$issues[] = $this->create_issue(
					'missing_validators_html',
					'Missing ETag or Last-Modified on HTML',
					'Validators enable cheap 304 revalidation responses.',
					self::LEVEL_WARNING
				);
			}
		}

		// Check for immutable on HTML (bad practice).
		if ( $cc['immutable'] ) {
			$issues[] = $this->create_issue(
				'immutable_html',
				'immutable directive on HTML document',
				'HTML content changes; immutable locks users into stale versions.',
				self::LEVEL_ERROR
			);
		}

		// Very long max-age without s-maxage might be problematic.
		if ( $cc['max_age'] !== null && $cc['max_age'] > 3600 && $cc['s_maxage'] === null ) {
			$issues[] = $this->create_issue(
				'long_browser_cache_html',
				'Long browser cache for HTML without CDN differentiation',
				sprintf(
					'max-age of %d seconds is long for HTML. Consider using shorter max-age with longer s-maxage.',
					$cc['max_age']
				),
				self::LEVEL_INFO
			);
		}

		return $issues;
	}

	/**
	 * Check issues specific to images.
	 *
	 * @param array $headers Parsed headers.
	 *
	 * @return array Issues found.
	 */
	private function check_image_issues( array $headers ): array {
		$issues = [];
		$cc     = $headers['cache_control'];

		// Check no-store on images.
		if ( $cc['no_store'] ) {
			$issues[] = $this->create_issue(
				'no_store_image',
				'no-store on image is wasteful',
				'Images should be cached for at least one day.',
				self::LEVEL_WARNING
			);
		}

		// Check for very short max-age.
		if ( $cc['max_age'] !== null && $cc['max_age'] < 3600 ) {
			$issues[] = $this->create_issue(
				'short_max_age_image',
				'Short max-age on image',
				sprintf(
					'Images should typically be cached for at least 1 day. Current: %d seconds.',
					$cc['max_age']
				),
				self::LEVEL_INFO
			);
		}

		return $issues;
	}

	/**
	 * Check issues specific to API endpoints.
	 *
	 * @param array $headers Parsed headers.
	 *
	 * @return array Issues found.
	 */
	private function check_api_issues( array $headers ): array {
		$issues = [];
		$cc     = $headers['cache_control'];

		// APIs should generally use s-maxage for CDN caching.
		if ( $cc['max_age'] !== null && $cc['s_maxage'] === null && ! $cc['private'] ) {
			$issues[] = $this->create_issue(
				'missing_s_maxage_api',
				'API using max-age without s-maxage',
				'Consider using s-maxage to differentiate CDN caching from browser caching.',
				self::LEVEL_INFO
			);
		}

		// Check for stale-if-error on APIs.
		if ( $cc['stale_if_error'] === null && ! $cc['no_store'] && ! $cc['private'] ) {
			$issues[] = $this->create_issue(
				'missing_stale_if_error',
				'Missing stale-if-error on API',
				'stale-if-error provides resilience during origin outages.',
				self::LEVEL_INFO
			);
		}

		return $issues;
	}

	/**
	 * Check issues specific to font files.
	 *
	 * @param array $headers Parsed headers.
	 *
	 * @return array Issues found.
	 */
	private function check_font_issues( array $headers ): array {
		$issues = [];

		// Fonts need CORS headers for cross-origin loading (preload with crossorigin attribute).
		if ( ! $headers['cors']['has_cors'] ) {
			$issues[] = $this->create_issue(
				'missing_cors_font',
				'Missing CORS header on font',
				'Fonts need Access-Control-Allow-Origin header for preload to work correctly.',
				self::LEVEL_WARNING
			);
		}

		return $issues;
	}

	/**
	 * Check CDN-specific issues.
	 *
	 * @param array  $headers       Parsed headers.
	 * @param string $resource_type Resource type.
	 *
	 * @return array Issues found.
	 */
	private function check_cdn_issues( array $headers, string $resource_type ): array {
		$issues = [];
		$cc     = $headers['cache_control'];

		// Behind CDN but no s-maxage for public resources.
		if ( ! $cc['private'] && ! $cc['no_store'] && $cc['s_maxage'] === null ) {
			if ( $resource_type === ResourceType::HTML || $resource_type === ResourceType::API ) {
				$issues[] = $this->create_issue(
					'missing_s_maxage_cdn',
					'Behind CDN but missing s-maxage',
					'Use s-maxage to set different cache duration for CDN vs browser.',
					self::LEVEL_INFO
				);
			}
		}

		// Check CDN cache status.
		$cache_status = strtoupper( $headers['cdn']['cache_status'] ?? '' );
		if ( in_array( $cache_status, [ 'BYPASS', 'DYNAMIC', 'MISS' ], true ) ) {
			if ( ResourceType::is_static( $resource_type ) ) {
				$issues[] = $this->create_issue(
					'cdn_not_caching',
					sprintf( 'CDN reports %s for static asset', $cache_status ),
					'Static assets should be cached at the CDN level.',
					self::LEVEL_WARNING
				);
			}
		}

		return $issues;
	}

	/**
	 * Create an issue array.
	 *
	 * @param string $code        Issue code for identification.
	 * @param string $title       Short issue title.
	 * @param string $description Detailed description.
	 * @param string $level       Severity level.
	 *
	 * @return array Issue data.
	 */
	private function create_issue( string $code, string $title, string $description, string $level ): array {
		return [
			'code'        => $code,
			'title'       => $title,
			'description' => $description,
			'level'       => $level,
		];
	}
}

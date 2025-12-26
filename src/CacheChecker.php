<?php
/**
 * Main CacheChecker class that orchestrates the analysis.
 *
 * @package CacheChecker
 */

namespace CacheChecker;

/**
 * Main class for analyzing cache headers of a URL and its assets.
 */
class CacheChecker {

	/**
	 * Configuration options.
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * Header parser instance.
	 *
	 * @var HeaderParser
	 */
	private HeaderParser $header_parser;

	/**
	 * Asset extractor instance.
	 *
	 * @var AssetExtractor
	 */
	private AssetExtractor $asset_extractor;

	/**
	 * Rule engine instance.
	 *
	 * @var RuleEngine
	 */
	private RuleEngine $rule_engine;

	/**
	 * Default options.
	 *
	 * @var array
	 */
	private const DEFAULT_OPTIONS = [
		'timeout'          => 30,
		'user_agent'       => 'CacheChecker/1.0',
		'follow_redirects' => true,
		'max_assets'       => 100,
		'check_assets'     => true,
	];

	/**
	 * Constructor.
	 *
	 * @param array $options Configuration options.
	 */
	public function __construct( array $options = [] ) {
		$this->options         = array_merge( self::DEFAULT_OPTIONS, $options );
		$this->header_parser   = new HeaderParser();
		$this->asset_extractor = new AssetExtractor();
		$this->rule_engine     = new RuleEngine();
	}

	/**
	 * Analyze a URL and its assets for caching issues.
	 *
	 * @param string $url The URL to analyze.
	 *
	 * @return array Analysis report containing results and issues.
	 */
	public function analyze( string $url ): array {
		$report = [
			'url'        => $url,
			'timestamp'  => gmdate( 'c' ),
			'redirect'   => null,
			'main_page'  => null,
			'assets'     => [],
			'summary'    => [
				'total_assets'    => 0,
				'total_issues'    => 0,
				'issues_by_level' => [
					'error'   => 0,
					'warning' => 0,
					'info'    => 0,
				],
			],
		];

		// First, check if there's a redirect (without following).
		$redirect_info = $this->check_redirect( $url );
		$final_url     = $url;

		if ( $redirect_info ) {
			$report['redirect'] = $redirect_info;
			$final_url          = $redirect_info['final_url'];

			// Count redirect issues.
			foreach ( $redirect_info['issues'] as $issue ) {
				++$report['summary']['total_issues'];
				++$report['summary']['issues_by_level'][ $issue['level'] ];
			}
		}

		// Fetch and analyze the final page.
		$main_response = $this->fetch_url( $final_url );

		if ( is_wp_error( $main_response ) ) {
			$report['error'] = $main_response->get_error_message();
			return $report;
		}

		$main_headers = wp_remote_retrieve_headers( $main_response );
		$main_body    = wp_remote_retrieve_body( $main_response );
		$status_code  = wp_remote_retrieve_response_code( $main_response );

		// Parse headers for main page.
		$parsed_headers = $this->header_parser->parse( $main_headers );

		// Analyze main page as HTML resource.
		$main_issues = $this->rule_engine->analyze(
			$parsed_headers,
			ResourceType::HTML,
			$final_url
		);

		$report['main_page'] = [
			'url'            => $final_url,
			'status_code'    => $status_code,
			'resource_type'  => ResourceType::HTML,
			'headers'        => $parsed_headers,
			'issues'         => $main_issues,
		];

		// Count main page issues.
		foreach ( $main_issues as $issue ) {
			++$report['summary']['total_issues'];
			++$report['summary']['issues_by_level'][ $issue['level'] ];
		}

		// Extract and analyze assets if enabled.
		if ( $this->options['check_assets'] && ! empty( $main_body ) ) {
			$assets = $this->asset_extractor->extract( $main_body, $final_url );
			$assets = array_slice( $assets, 0, $this->options['max_assets'] );

			$report['summary']['total_assets'] = count( $assets );

			foreach ( $assets as $asset ) {
				$asset_report = $this->analyze_asset( $asset );
				if ( $asset_report ) {
					$report['assets'][] = $asset_report;

					foreach ( $asset_report['issues'] as $issue ) {
						++$report['summary']['total_issues'];
						++$report['summary']['issues_by_level'][ $issue['level'] ];
					}
				}
			}
		}

		return $report;
	}

	/**
	 * Check if a URL redirects and analyze the redirect response.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return array|null Redirect info or null if no redirect.
	 */
	private function check_redirect( string $url ): ?array {
		$args = [
			'timeout'     => $this->options['timeout'],
			'user-agent'  => $this->options['user_agent'],
			'method'      => 'GET',
			'sslverify'   => true,
			'redirection' => 0, // Don't follow redirects.
		];

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		// Check if it's a redirect status code.
		if ( $status_code < 300 || $status_code >= 400 ) {
			return null;
		}

		$headers  = wp_remote_retrieve_headers( $response );
		$location = '';

		// Get Location header.
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$all_headers = $headers->getAll();
			$location    = $all_headers['location'] ?? '';
		} elseif ( is_array( $headers ) ) {
			$location = $headers['location'] ?? '';
		}

		if ( empty( $location ) ) {
			return null;
		}

		// Resolve relative redirect URL.
		if ( ! preg_match( '/^https?:\/\//i', $location ) ) {
			$parts    = wp_parse_url( $url );
			$location = $parts['scheme'] . '://' . $parts['host'] . $location;
		}

		// Parse and analyze redirect headers.
		$parsed_headers = $this->header_parser->parse( $headers );

		// Check for redirect-specific issues.
		$issues = [];
		$cc     = $parsed_headers['cache_control'];

		// Redirects should generally be cached.
		if ( $status_code === 301 ) {
			// Permanent redirects should be cached.
			if ( $cc['max_age'] === null && $cc['s_maxage'] === null && ! $cc['no_store'] ) {
				$issues[] = [
					'code'        => 'uncached_permanent_redirect',
					'title'       => 'Permanent redirect (301) is not cached',
					'description' => '301 redirects should be cached to avoid repeated redirect lookups.',
					'level'       => 'warning',
				];
			}
		} elseif ( $status_code === 302 || $status_code === 307 ) {
			// Temporary redirects - caching is optional but good to note.
			if ( $cc['max_age'] === null && $cc['s_maxage'] === null ) {
				$issues[] = [
					'code'        => 'uncached_temporary_redirect',
					'title'       => sprintf( 'Temporary redirect (%d) is not cached', $status_code ),
					'description' => 'Consider caching temporary redirects briefly to reduce origin load.',
					'level'       => 'info',
				];
			}
		}

		return [
			'original_url' => $url,
			'final_url'    => $location,
			'status_code'  => $status_code,
			'headers'      => $parsed_headers,
			'issues'       => $issues,
		];
	}

	/**
	 * Analyze a single asset.
	 *
	 * @param array $asset Asset information from extractor.
	 *
	 * @return array|null Asset analysis or null on failure.
	 */
	private function analyze_asset( array $asset ): ?array {
		$response = $this->fetch_url( $asset['url'], 'HEAD' );

		if ( is_wp_error( $response ) ) {
			return [
				'url'           => $asset['url'],
				'resource_type' => $asset['type'],
				'is_external'   => $asset['is_external'],
				'fingerprinted' => $asset['fingerprinted'],
				'error'         => $response->get_error_message(),
				'headers'       => [],
				'issues'        => [],
			];
		}

		$headers        = wp_remote_retrieve_headers( $response );
		$status_code    = wp_remote_retrieve_response_code( $response );
		$parsed_headers = $this->header_parser->parse( $headers );

		$issues = $this->rule_engine->analyze(
			$parsed_headers,
			$asset['type'],
			$asset['url'],
			$asset['fingerprinted']
		);

		return [
			'url'           => $asset['url'],
			'resource_type' => $asset['type'],
			'is_external'   => $asset['is_external'],
			'fingerprinted' => $asset['fingerprinted'],
			'status_code'   => $status_code,
			'headers'       => $parsed_headers,
			'issues'        => $issues,
		];
	}

	/**
	 * Fetch a URL using WordPress HTTP API.
	 *
	 * @param string $url    The URL to fetch.
	 * @param string $method HTTP method (GET or HEAD).
	 *
	 * @return array|\WP_Error Response array or error.
	 */
	private function fetch_url( string $url, string $method = 'GET' ) {
		$args = [
			'timeout'    => $this->options['timeout'],
			'user-agent' => $this->options['user_agent'],
			'method'     => $method,
			'sslverify'  => true,
		];

		if ( ! $this->options['follow_redirects'] ) {
			$args['redirection'] = 0;
		}

		return wp_remote_request( $url, $args );
	}
}

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

		// Fetch and analyze the main page.
		$main_response = $this->fetch_url( $url );

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
			$url
		);

		$report['main_page'] = [
			'url'            => $url,
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
			$assets = $this->asset_extractor->extract( $main_body, $url );
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

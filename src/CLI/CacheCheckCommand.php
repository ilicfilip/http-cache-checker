<?php
/**
 * WP-CLI command for cache checking.
 *
 * @package CacheChecker
 */

namespace CacheChecker\CLI;

use CacheChecker\CacheChecker;
use CacheChecker\Report;
use WP_CLI;

/**
 * Check HTTP caching headers for a URL and its assets.
 *
 * ## EXAMPLES
 *
 *     # Check a URL
 *     wp cache-check https://example.com
 *
 *     # Check with JSON output
 *     wp cache-check https://example.com --format=json
 *
 *     # Check only the main page (no assets)
 *     wp cache-check https://example.com --no-assets
 *
 *     # Limit number of assets to check
 *     wp cache-check https://example.com --max-assets=20
 */
class CacheCheckCommand {

	/**
	 * Analyze HTTP caching headers for a URL.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL to analyze.
	 *
	 * [--format=<format>]
	 * : Output format (text, json, table).
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 *   - table
	 * ---
	 *
	 * [--no-assets]
	 * : Skip checking assets (JS, CSS, images, fonts).
	 *
	 * [--max-assets=<number>]
	 * : Maximum number of assets to check.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--timeout=<seconds>]
	 * : Request timeout in seconds.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--level=<level>]
	 * : Minimum issue level to display (error, warning, info).
	 * ---
	 * default: info
	 * options:
	 *   - error
	 *   - warning
	 *   - info
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-check https://example.com
	 *     wp cache-check https://example.com --format=json
	 *     wp cache-check https://example.com --no-assets
	 *     wp cache-check https://example.com --max-assets=20 --level=warning
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ) {
		$url = $args[0];

		// Add https:// if protocol is omitted.
		if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
			$url = 'https://' . $url;
		}

		// Validate URL.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'Invalid URL provided.' );
		}

		$format     = $assoc_args['format'] ?? 'text';
		$check_assets = ! isset( $assoc_args['no-assets'] );
		$max_assets = (int) ( $assoc_args['max-assets'] ?? 50 );
		$timeout    = (int) ( $assoc_args['timeout'] ?? 30 );
		$min_level  = $assoc_args['level'] ?? 'info';

		WP_CLI::log( sprintf( 'Analyzing: %s', $url ) );
		WP_CLI::log( '' );

		// Run analysis.
		$checker = new CacheChecker(
			[
				'timeout'      => $timeout,
				'max_assets'   => $max_assets,
				'check_assets' => $check_assets,
			]
		);

		$start  = microtime( true );
		$result = $checker->analyze( $url );
		$elapsed = round( microtime( true ) - $start, 2 );

		$report = new Report( $result );

		// Handle error.
		if ( isset( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		// Output based on format.
		switch ( $format ) {
			case 'json':
				WP_CLI::log( $report->to_json() );
				break;

			case 'table':
				$this->output_table( $result, $min_level );
				break;

			case 'text':
			default:
				WP_CLI::log( $report->to_text() );
				break;
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Completed in %s seconds.', $elapsed ) );

		// Exit code based on issues.
		$summary = $result['summary'];
		if ( $summary['issues_by_level']['error'] > 0 ) {
			WP_CLI::error(
				sprintf( 'Found %d error(s).', $summary['issues_by_level']['error'] ),
				false
			);
		} elseif ( $summary['issues_by_level']['warning'] > 0 ) {
			WP_CLI::warning(
				sprintf( 'Found %d warning(s).', $summary['issues_by_level']['warning'] )
			);
		} else {
			WP_CLI::success( 'No significant caching issues found.' );
		}
	}

	/**
	 * Output results as a table.
	 *
	 * @param array  $result    Analysis result.
	 * @param string $min_level Minimum level to show.
	 */
	private function output_table( array $result, string $min_level ) {
		$levels     = [ 'error' => 3, 'warning' => 2, 'info' => 1 ];
		$min_priority = $levels[ $min_level ] ?? 1;

		$rows = [];

		// Collect issues from main page.
		if ( $result['main_page'] && ! empty( $result['main_page']['issues'] ) ) {
			foreach ( $result['main_page']['issues'] as $issue ) {
				if ( ( $levels[ $issue['level'] ] ?? 0 ) >= $min_priority ) {
					$rows[] = [
						'level'       => strtoupper( $issue['level'] ),
						'type'        => strtoupper( $result['main_page']['resource_type'] ),
						'url'         => $this->truncate( $result['main_page']['url'], 40 ),
						'issue'       => $issue['title'],
					];
				}
			}
		}

		// Collect issues from assets.
		foreach ( $result['assets'] as $asset ) {
			if ( ! empty( $asset['issues'] ) ) {
				foreach ( $asset['issues'] as $issue ) {
					if ( ( $levels[ $issue['level'] ] ?? 0 ) >= $min_priority ) {
						$rows[] = [
							'level'       => strtoupper( $issue['level'] ),
							'type'        => strtoupper( $asset['resource_type'] ),
							'url'         => $this->truncate( $asset['url'], 40 ),
							'issue'       => $issue['title'],
						];
					}
				}
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No issues found at the specified level.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'level', 'type', 'url', 'issue' ] );
	}

	/**
	 * Truncate a string.
	 *
	 * @param string $str String to truncate.
	 * @param int    $len Maximum length.
	 *
	 * @return string Truncated string.
	 */
	private function truncate( string $str, int $len ): string {
		if ( strlen( $str ) <= $len ) {
			return $str;
		}
		return substr( $str, 0, $len - 3 ) . '...';
	}
}

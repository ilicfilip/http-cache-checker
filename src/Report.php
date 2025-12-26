<?php
/**
 * Report formatter for cache check results.
 *
 * @package CacheChecker
 */

namespace CacheChecker;

/**
 * Formats analysis results into various output formats.
 */
class Report {

	/**
	 * Analysis data.
	 *
	 * @var array
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Analysis data from CacheChecker.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get raw data array.
	 *
	 * @return array Raw analysis data.
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Convert to JSON.
	 *
	 * @param int $flags JSON encoding flags.
	 *
	 * @return string JSON string.
	 */
	public function to_json( int $flags = JSON_PRETTY_PRINT ): string {
		return wp_json_encode( $this->data, $flags );
	}

	/**
	 * Format as plain text for CLI output.
	 *
	 * @return string Formatted text report.
	 */
	public function to_text(): string {
		$output = [];

		$output[] = '=== HTTP Cache Header Analysis ===';
		$output[] = sprintf( 'URL: %s', $this->data['url'] );
		$output[] = sprintf( 'Analyzed: %s', $this->data['timestamp'] );
		$output[] = '';

		// Check for errors.
		if ( isset( $this->data['error'] ) ) {
			$output[] = sprintf( 'ERROR: %s', $this->data['error'] );
			return implode( "\n", $output );
		}

		// Summary.
		$summary   = $this->data['summary'];
		$output[] = '--- Summary ---';
		$output[] = sprintf( 'Total assets checked: %d', $summary['total_assets'] );
		$output[] = sprintf(
			'Issues found: %d (Errors: %d, Warnings: %d, Info: %d)',
			$summary['total_issues'],
			$summary['issues_by_level']['error'],
			$summary['issues_by_level']['warning'],
			$summary['issues_by_level']['info']
		);
		$output[] = '';

		// Main page.
		if ( $this->data['main_page'] ) {
			$output[] = '--- Main Page ---';
			$output   = array_merge( $output, $this->format_resource( $this->data['main_page'] ) );
			$output[] = '';
		}

		// Assets with issues.
		$assets_with_issues = array_filter(
			$this->data['assets'],
			fn( $a ) => ! empty( $a['issues'] )
		);

		if ( ! empty( $assets_with_issues ) ) {
			$output[] = '--- Assets with Issues ---';

			foreach ( $assets_with_issues as $asset ) {
				$output   = array_merge( $output, $this->format_resource( $asset ) );
				$output[] = '';
			}
		}

		// Assets without issues (brief listing).
		$assets_ok = array_filter(
			$this->data['assets'],
			fn( $a ) => empty( $a['issues'] ) && ! isset( $a['error'] )
		);

		if ( ! empty( $assets_ok ) ) {
			$output[] = sprintf( '--- Assets OK (%d) ---', count( $assets_ok ) );
			foreach ( $assets_ok as $asset ) {
				$output[] = sprintf(
					'  ✓ [%s] %s',
					strtoupper( $asset['resource_type'] ),
					$this->truncate_url( $asset['url'], 80 )
				);
			}
			$output[] = '';
		}

		return implode( "\n", $output );
	}

	/**
	 * Format a single resource for text output.
	 *
	 * @param array $resource Resource data.
	 *
	 * @return array Lines of output.
	 */
	private function format_resource( array $resource ): array {
		$lines = [];

		$type_label = strtoupper( $resource['resource_type'] );
		$fp_label   = ( $resource['fingerprinted'] ?? false ) ? ' [fingerprinted]' : '';
		$ext_label  = ( $resource['is_external'] ?? false ) ? ' [external]' : '';

		$lines[] = sprintf( '[%s]%s%s %s', $type_label, $fp_label, $ext_label, $resource['url'] );

		if ( isset( $resource['error'] ) ) {
			$lines[] = sprintf( '  Error: %s', $resource['error'] );
			return $lines;
		}

		// Show cache-control if present.
		$cc_raw = $resource['headers']['cache_control']['raw'] ?? '';
		if ( ! empty( $cc_raw ) ) {
			$lines[] = sprintf( '  Cache-Control: %s', $cc_raw );
		} else {
			$lines[] = '  Cache-Control: (none)';
		}

		// Show other relevant headers.
		if ( $resource['headers']['etag'] ) {
			$lines[] = sprintf( '  ETag: %s', $resource['headers']['etag']['raw'] );
		}

		if ( $resource['headers']['cdn']['detected'] ) {
			$lines[] = sprintf(
				'  CDN: %s (status: %s)',
				$resource['headers']['cdn']['provider'] ?? 'detected',
				$resource['headers']['cdn']['cache_status'] ?? 'unknown'
			);
		}

		// Issues.
		if ( ! empty( $resource['issues'] ) ) {
			$lines[] = '  Issues:';
			foreach ( $resource['issues'] as $issue ) {
				$icon = $this->get_level_icon( $issue['level'] );
				$lines[] = sprintf( '    %s %s', $icon, $issue['title'] );
				$lines[] = sprintf( '      %s', $issue['description'] );
			}
		}

		return $lines;
	}

	/**
	 * Get icon for issue level.
	 *
	 * @param string $level Issue level.
	 *
	 * @return string Icon character.
	 */
	private function get_level_icon( string $level ): string {
		return match ( $level ) {
			'error'   => '✗',
			'warning' => '⚠',
			'info'    => 'ℹ',
			default   => '•',
		};
	}

	/**
	 * Truncate a URL for display.
	 *
	 * @param string $url       URL to truncate.
	 * @param int    $max_length Maximum length.
	 *
	 * @return string Truncated URL.
	 */
	private function truncate_url( string $url, int $max_length ): string {
		if ( strlen( $url ) <= $max_length ) {
			return $url;
		}

		return substr( $url, 0, $max_length - 3 ) . '...';
	}

	/**
	 * Format as HTML table.
	 *
	 * @return string HTML output.
	 */
	public function to_html(): string {
		$html = '<div class="cache-checker-report">';

		// Summary.
		$summary = $this->data['summary'];
		$html .= '<div class="summary">';
		$html .= sprintf( '<h2>Cache Analysis: %s</h2>', esc_html( $this->data['url'] ) );
		$html .= sprintf(
			'<p>Checked %d assets. Found %d issues (%d errors, %d warnings, %d info).</p>',
			$summary['total_assets'],
			$summary['total_issues'],
			$summary['issues_by_level']['error'],
			$summary['issues_by_level']['warning'],
			$summary['issues_by_level']['info']
		);
		$html .= '</div>';

		// Issues table.
		$all_issues = $this->collect_all_issues();

		if ( ! empty( $all_issues ) ) {
			$html .= '<table class="cache-issues">';
			$html .= '<thead><tr><th>Level</th><th>Resource</th><th>Issue</th><th>Description</th></tr></thead>';
			$html .= '<tbody>';

			foreach ( $all_issues as $issue ) {
				$html .= sprintf(
					'<tr class="issue-%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_attr( $issue['level'] ),
					esc_html( ucfirst( $issue['level'] ) ),
					esc_html( $this->truncate_url( $issue['url'], 50 ) ),
					esc_html( $issue['title'] ),
					esc_html( $issue['description'] )
				);
			}

			$html .= '</tbody></table>';
		} else {
			$html .= '<p class="no-issues">No caching issues found!</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Collect all issues from main page and assets.
	 *
	 * @return array Flattened list of issues with URLs.
	 */
	private function collect_all_issues(): array {
		$all_issues = [];

		// Main page issues.
		if ( $this->data['main_page'] && ! empty( $this->data['main_page']['issues'] ) ) {
			foreach ( $this->data['main_page']['issues'] as $issue ) {
				$issue['url'] = $this->data['main_page']['url'];
				$all_issues[] = $issue;
			}
		}

		// Asset issues.
		foreach ( $this->data['assets'] as $asset ) {
			if ( ! empty( $asset['issues'] ) ) {
				foreach ( $asset['issues'] as $issue ) {
					$issue['url'] = $asset['url'];
					$all_issues[] = $issue;
				}
			}
		}

		// Sort by level (errors first).
		usort(
			$all_issues,
			function ( $a, $b ) {
				$order = [ 'error' => 0, 'warning' => 1, 'info' => 2 ];
				return ( $order[ $a['level'] ] ?? 3 ) <=> ( $order[ $b['level'] ] ?? 3 );
			}
		);

		return $all_issues;
	}

	/**
	 * Check if analysis found any issues at a given level or higher.
	 *
	 * @param string $min_level Minimum level to check ('error', 'warning', 'info').
	 *
	 * @return bool Whether issues at or above this level were found.
	 */
	public function has_issues( string $min_level = 'info' ): bool {
		$levels = [ 'error' => 3, 'warning' => 2, 'info' => 1 ];
		$min    = $levels[ $min_level ] ?? 1;

		foreach ( $levels as $level => $priority ) {
			if ( $priority >= $min && ( $this->data['summary']['issues_by_level'][ $level ] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}
}

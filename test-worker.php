<?php
/**
 * Test script for WorkerClient integration.
 *
 * Usage: php test-worker.php <url-to-analyze>
 * Example: php test-worker.php https://example.com
 *
 * @package CacheChecker
 */

namespace CacheChecker;

// Prevent web access.
if ( php_sapi_name() !== 'cli' ) {
	die( 'This script can only be run from the command line.' );
}

// Check for URL argument.
if ( $argc < 2 ) {
	echo "Usage: php test-worker.php <url>\n";
	echo "Example: php test-worker.php https://example.com\n";
	exit( 1 );
}

$url = $argv[1];

// Add https:// if protocol is omitted.
if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
	$url = 'https://' . $url;
}

// Load environment from .env file.
$env_file = __DIR__ . '/.env';
if ( file_exists( $env_file ) ) {
	$lines = file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		// Skip comments.
		if ( strpos( trim( $line ), '#' ) === 0 ) {
			continue;
		}
		// Parse KEY=value.
		if ( strpos( $line, '=' ) !== false ) {
			list( $key, $value ) = explode( '=', $line, 2 );
			$_ENV[ trim( $key ) ] = trim( $value );
		}
	}
}

// Worker configuration from environment.
$worker_url = $_ENV['WORKER_URL'] ?? '';
$api_key    = $_ENV['WORKER_API_KEY'] ?? '';

if ( empty( $worker_url ) ) {
	echo "Error: WORKER_URL not set.\n";
	echo "Create a .env file with WORKER_URL=https://cache-checker.your-subdomain.workers.dev\n";
	exit( 1 );
}

// Simulate WordPress functions for standalone usage.
if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Make a POST request using cURL.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 *
	 * @return array|StandaloneError Response or error.
	 */
	function wp_remote_post( string $url, array $args = [] ) {
		$ch = curl_init();

		$headers = [];
		foreach ( $args['headers'] ?? [] as $key => $value ) {
			$headers[] = "{$key}: {$value}";
		}

		curl_setopt_array(
			$ch,
			[
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $args['body'] ?? '',
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => $args['timeout'] ?? 60,
				CURLOPT_SSL_VERIFYPEER => true,
			]
		);

		$response = curl_exec( $ch );

		if ( curl_errno( $ch ) ) {
			$error = new StandaloneError( 'http_request_failed', curl_error( $ch ) );
			curl_close( $ch );
			return $error;
		}

		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		return [
			'body'     => $response,
			'response' => [
				'code' => $status_code,
			],
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof StandaloneError;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

// Load classes.
require_once __DIR__ . '/src/WorkerClient.php';
require_once __DIR__ . '/src/Report.php';

// Also need StandaloneError from standalone.php.
if ( ! class_exists( __NAMESPACE__ . '\\StandaloneError' ) ) {
	class StandaloneError {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

// Run test.
echo "Testing WorkerClient integration\n";
echo "================================\n\n";
echo "Worker URL: {$worker_url}\n";
echo "Target URL: {$url}\n";
echo "API Key: " . ( empty( $api_key ) ? '(none - dev mode)' : '(set)' ) . "\n\n";

$client = new WorkerClient( $worker_url, $api_key );

echo "Checking worker health... ";
$start = microtime( true );

// Simple health check via cURL.
$health_response = file_get_contents( $worker_url . '/health' );
if ( $health_response === false ) {
	echo "FAILED\n";
	echo "Could not connect to worker. Is it deployed?\n";
	exit( 1 );
}

$health = json_decode( $health_response, true );
if ( ( $health['status'] ?? '' ) === 'ok' ) {
	echo "OK\n\n";
} else {
	echo "UNEXPECTED RESPONSE\n";
	print_r( $health );
	exit( 1 );
}

echo "Analyzing {$url}...\n\n";

$result = $client->analyze( $url, true, 20 );
$elapsed = round( microtime( true ) - $start, 2 );

if ( is_wp_error( $result ) || ( isset( $result->message ) && $result instanceof StandaloneError ) ) {
	echo "ERROR: " . $result->get_error_message() . "\n";
	exit( 1 );
}

if ( isset( $result['error'] ) ) {
	echo "Worker Error: " . $result['error'] . "\n";
	exit( 1 );
}

// Display report.
$report = new Report( $result );
echo $report->to_text() . "\n";

echo "\nCompleted in {$elapsed} seconds (via Cloudflare Worker).\n";

// Compare summary.
$summary = $result['summary'];
echo "\n--- Test Summary ---\n";
echo "Total assets checked: {$summary['total_assets']}\n";
echo "Total issues found: {$summary['total_issues']}\n";
echo "  Errors: {$summary['issues_by_level']['error']}\n";
echo "  Warnings: {$summary['issues_by_level']['warning']}\n";
echo "  Info: {$summary['issues_by_level']['info']}\n";

exit( 0 );

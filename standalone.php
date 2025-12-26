<?php
/**
 * Standalone cache checker script (for use without WordPress).
 *
 * Usage: php standalone.php <url>
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
	echo "Usage: php standalone.php <url>\n";
	exit( 1 );
}

$url = $argv[1];

// Add https:// if protocol is omitted.
if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
	$url = 'https://' . $url;
}

// Validate URL.
if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
	echo "Error: Invalid URL provided.\n";
	exit( 1 );
}

// Define WordPress compatibility functions if not available.
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Wrapper for parse_url.
	 *
	 * @param string $url URL to parse.
	 * @param int    $component Component to retrieve.
	 *
	 * @return mixed Parsed URL or component.
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Wrapper for json_encode.
	 *
	 * @param mixed $data Data to encode.
	 * @param int   $options JSON options.
	 *
	 * @return string|false JSON string.
	 */
	function wp_json_encode( $data, int $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Check if value is a WP_Error.
	 *
	 * @param mixed $thing Value to check.
	 *
	 * @return bool Whether it's an error.
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof StandaloneError;
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * Make an HTTP request using cURL.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 *
	 * @return array|StandaloneError Response or error.
	 */
	function wp_remote_request( string $url, array $args = [] ) {
		$ch = curl_init();

		curl_setopt_array(
			$ch,
			[
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_TIMEOUT        => $args['timeout'] ?? 30,
				CURLOPT_FOLLOWLOCATION => ( $args['redirection'] ?? 5 ) > 0,
				CURLOPT_MAXREDIRS      => $args['redirection'] ?? 5,
				CURLOPT_USERAGENT      => $args['user-agent'] ?? 'CacheChecker/1.0',
				CURLOPT_SSL_VERIFYPEER => $args['sslverify'] ?? true,
			]
		);

		if ( ( $args['method'] ?? 'GET' ) === 'HEAD' ) {
			curl_setopt( $ch, CURLOPT_NOBODY, true );
		}

		$response = curl_exec( $ch );

		if ( curl_errno( $ch ) ) {
			$error = new StandaloneError( 'http_request_failed', curl_error( $ch ) );
			curl_close( $ch );
			return $error;
		}

		$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		curl_close( $ch );

		$header_str = substr( $response, 0, $header_size );
		$body       = substr( $response, $header_size );

		// Parse headers.
		$headers = [];
		foreach ( explode( "\r\n", $header_str ) as $line ) {
			if ( strpos( $line, ':' ) !== false ) {
				list( $key, $value ) = explode( ':', $line, 2 );
				$headers[ strtolower( trim( $key ) ) ] = trim( $value );
			}
		}

		return [
			'headers'  => $headers,
			'body'     => $body,
			'response' => [
				'code' => $status_code,
			],
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	/**
	 * Retrieve headers from response.
	 *
	 * @param array $response Response array.
	 *
	 * @return array Headers.
	 */
	function wp_remote_retrieve_headers( array $response ): array {
		return $response['headers'] ?? [];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Retrieve body from response.
	 *
	 * @param array $response Response array.
	 *
	 * @return string Body content.
	 */
	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Retrieve status code from response.
	 *
	 * @param array $response Response array.
	 *
	 * @return int Status code.
	 */
	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['response']['code'] ?? 0;
	}
}

/**
 * Simple error class for standalone mode.
 */
class StandaloneError {
	/**
	 * Error code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code, string $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Get error message.
	 *
	 * @return string Error message.
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

// Load classes.
require_once __DIR__ . '/src/ResourceType.php';
require_once __DIR__ . '/src/HeaderParser.php';
require_once __DIR__ . '/src/AssetExtractor.php';
require_once __DIR__ . '/src/RuleEngine.php';
require_once __DIR__ . '/src/CacheChecker.php';
require_once __DIR__ . '/src/Report.php';

// Run analysis.
echo "Analyzing: {$url}\n\n";

$checker = new CacheChecker(
	[
		'timeout'      => 30,
		'max_assets'   => 50,
		'check_assets' => true,
	]
);

$start  = microtime( true );
$result = $checker->analyze( $url );
$elapsed = round( microtime( true ) - $start, 2 );

$report = new Report( $result );

// Output.
if ( in_array( '--json', $argv, true ) ) {
	echo $report->to_json() . "\n";
} else {
	echo $report->to_text() . "\n";
}

echo "\nCompleted in {$elapsed} seconds.\n";

// Exit code.
$summary = $result['summary'];
if ( $summary['issues_by_level']['error'] > 0 ) {
	exit( 1 );
}
exit( 0 );

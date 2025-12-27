<?php
/**
 * Client for calling the Cloudflare Worker API.
 *
 * @package CacheChecker
 */

namespace CacheChecker;

/**
 * Offloads cache checking to a Cloudflare Worker.
 */
class WorkerClient {

	/**
	 * Worker URL endpoint.
	 *
	 * @var string
	 */
	private string $worker_url;

	/**
	 * API key for authentication.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $worker_url Base URL of the Cloudflare Worker.
	 * @param string $api_key    API key for authentication.
	 * @param int    $timeout    Request timeout in seconds.
	 */
	public function __construct( string $worker_url, string $api_key, int $timeout = 60 ) {
		$this->worker_url = rtrim( $worker_url, '/' );
		$this->api_key    = $api_key;
		$this->timeout    = $timeout;
	}

	/**
	 * Analyze a URL using the Cloudflare Worker.
	 *
	 * @param string $url          URL to analyze.
	 * @param bool   $check_assets Whether to check assets.
	 * @param int    $max_assets   Maximum number of assets to check.
	 *
	 * @return array|WP_Error Analysis result or error.
	 */
	public function analyze( string $url, bool $check_assets = true, int $max_assets = 50 ) {
		$endpoint = $this->worker_url . '/analyze';

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => $this->timeout,
				'headers' => [
					'Content-Type' => 'application/json',
					'X-API-Key'    => $this->api_key,
				],
				'body'    => wp_json_encode(
					[
						'url'          => $url,
						'check_assets' => $check_assets,
						'max_assets'   => $max_assets,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code !== 200 ) {
			$error_data = json_decode( $body, true );
			$error_msg  = $error_data['error'] ?? "Worker returned status {$status_code}";
			return new \WP_Error( 'worker_error', $error_msg );
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'json_error', 'Failed to parse worker response' );
		}

		return $data;
	}

	/**
	 * Check if the worker is healthy.
	 *
	 * @return bool Whether the worker is responding.
	 */
	public function health_check(): bool {
		$endpoint = $this->worker_url . '/health';

		$response = wp_remote_get(
			$endpoint,
			[
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return $status_code === 200;
	}
}

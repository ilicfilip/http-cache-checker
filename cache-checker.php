<?php
/**
 * Plugin Name: HTTP Cache Header Checker
 * Plugin URI: https://github.com/progressplanner/cache-checker
 * Description: Analyzes website HTTP caching headers and flags misconfigurations based on best practices.
 * Version: 0.1
 * Author: Progress Planner
 * Author URI: https://progressplanner.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cache-checker
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package CacheChecker
 */

namespace CacheChecker;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'CACHE_CHECKER_VERSION', '0.1' );
define( 'CACHE_CHECKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CACHE_CHECKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes.
spl_autoload_register(
	function ( $class ) {
		// Only autoload classes from this namespace.
		if ( strpos( $class, 'CacheChecker\\' ) !== 0 ) {
			return;
		}

		$class_name = str_replace( 'CacheChecker\\', '', $class );
		$class_path = str_replace( '\\', DIRECTORY_SEPARATOR, $class_name );
		$file       = CACHE_CHECKER_PLUGIN_DIR . 'src' . DIRECTORY_SEPARATOR . $class_path . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init() {
	// Register WP-CLI command if available.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'cache-check', __NAMESPACE__ . '\\CLI\\CacheCheckCommand' );
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Quick access function to run a cache check.
 *
 * @param string $url     The URL to check.
 * @param array  $options Optional configuration options.
 *
 * @return array The analysis report.
 */
function check_url( string $url, array $options = [] ): array {
	$checker = new CacheChecker( $options );
	return $checker->analyze( $url );
}

<?php
/**
 * Asset extractor for parsing HTML and finding assets.
 *
 * @package CacheChecker
 */

namespace CacheChecker;

/**
 * Extracts asset URLs from HTML content.
 */
class AssetExtractor {

	/**
	 * Regex pattern for fingerprinted assets.
	 * Matches hashes like: app.9f2d1a3b.js, style-abc123.css, bundle.HASH.js
	 *
	 * @var string
	 */
	private const FINGERPRINT_PATTERN = '/[.\-_][a-f0-9]{6,32}\.(js|css|woff2?|ttf|eot)$/i';

	/**
	 * Image file extensions.
	 *
	 * @var array
	 */
	private const IMAGE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico' ];

	/**
	 * Font file extensions.
	 *
	 * @var array
	 */
	private const FONT_EXTENSIONS = [ 'woff', 'woff2', 'ttf', 'eot', 'otf' ];

	/**
	 * Extract assets from HTML content.
	 *
	 * @param string $html     HTML content to parse.
	 * @param string $base_url Base URL for resolving relative URLs.
	 *
	 * @return array List of asset information.
	 */
	public function extract( string $html, string $base_url ): array {
		$assets = [];
		$seen   = [];

		// Parse base URL components.
		$base_parts = wp_parse_url( $base_url );
		$base_host  = $base_parts['host'] ?? '';

		// Extract scripts.
		$scripts = $this->extract_scripts( $html );
		foreach ( $scripts as $src ) {
			$url = $this->resolve_url( $src, $base_url );
			if ( $url && ! isset( $seen[ $url ] ) ) {
				$seen[ $url ]  = true;
				$assets[]      = $this->build_asset_info( $url, ResourceType::JS, $base_host );
			}
		}

		// Extract stylesheets.
		$styles = $this->extract_stylesheets( $html );
		foreach ( $styles as $href ) {
			$url = $this->resolve_url( $href, $base_url );
			if ( $url && ! isset( $seen[ $url ] ) ) {
				$seen[ $url ]  = true;
				$assets[]      = $this->build_asset_info( $url, ResourceType::CSS, $base_host );
			}
		}

		// Extract images.
		$images = $this->extract_images( $html );
		foreach ( $images as $src ) {
			$url = $this->resolve_url( $src, $base_url );
			if ( $url && ! isset( $seen[ $url ] ) ) {
				$seen[ $url ]  = true;
				$assets[]      = $this->build_asset_info( $url, ResourceType::IMAGE, $base_host );
			}
		}

		// Extract fonts from preload links.
		$fonts = $this->extract_preload_fonts( $html );
		foreach ( $fonts as $href ) {
			$url = $this->resolve_url( $href, $base_url );
			if ( $url && ! isset( $seen[ $url ] ) ) {
				$seen[ $url ]  = true;
				$assets[]      = $this->build_asset_info( $url, ResourceType::FONT, $base_host );
			}
		}

		return $assets;
	}

	/**
	 * Extract script sources from HTML.
	 *
	 * @param string $html HTML content.
	 *
	 * @return array Script source URLs.
	 */
	private function extract_scripts( string $html ): array {
		$sources = [];

		// Match <script src="..."> tags.
		if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			$sources = array_merge( $sources, $matches[1] );
		}

		return $sources;
	}

	/**
	 * Extract stylesheet URLs from HTML.
	 *
	 * @param string $html HTML content.
	 *
	 * @return array Stylesheet URLs.
	 */
	private function extract_stylesheets( string $html ): array {
		$urls = [];

		// Match <link rel="stylesheet" href="..."> tags.
		if ( preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// Also match <link href="..." rel="stylesheet"> (different order).
		if ( preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		return array_unique( $urls );
	}

	/**
	 * Extract image sources from HTML.
	 *
	 * @param string $html HTML content.
	 *
	 * @return array Image source URLs.
	 */
	private function extract_images( string $html ): array {
		$sources = [];

		// Remove <script> and <style> blocks to avoid extracting URLs from JS/CSS code.
		$clean_html = $this->strip_script_and_style_content( $html );

		// Match <img src="..."> tags.
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $clean_html, $matches ) ) {
			$sources = array_merge( $sources, $matches[1] );
		}

		// Match srcset images (get the first/main image from each srcset).
		if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/', $clean_html, $matches ) ) {
			foreach ( $matches[1] as $srcset ) {
				$first = explode( ',', $srcset )[0];
				$url   = trim( explode( ' ', trim( $first ) )[0] );
				if ( ! empty( $url ) ) {
					$sources[] = $url;
				}
			}
		}

		return array_unique( $sources );
	}

	/**
	 * Tags to strip when extracting images to avoid false positives.
	 *
	 * @var array
	 */
	private const TAGS_TO_STRIP = [ 'script', 'style' ];

	/**
	 * Strip content from specified tags to avoid false positives.
	 *
	 * @param string $html HTML content.
	 *
	 * @return string HTML with tag content removed.
	 */
	private function strip_script_and_style_content( string $html ): string {
		foreach ( self::TAGS_TO_STRIP as $tag ) {
			$html = preg_replace( '/<' . $tag . '\b[^>]*>[\s\S]*?<\/' . $tag . '>/i', '', $html );
		}

		return $html;
	}

	/**
	 * Extract preloaded font URLs.
	 *
	 * @param string $html HTML content.
	 *
	 * @return array Font URLs.
	 */
	private function extract_preload_fonts( string $html ): array {
		$fonts = [];

		// Match <link rel="preload" as="font" href="..."> tags.
		if ( preg_match_all( '/<link[^>]+rel=["\']preload["\'][^>]+as=["\']font["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			$fonts = array_merge( $fonts, $matches[1] );
		}

		// Also check for font files in regular link tags with type.
		if ( preg_match_all( '/<link[^>]+href=["\']([^"\']+\.(?:woff2?|ttf|eot|otf))["\'][^>]*>/i', $html, $matches ) ) {
			$fonts = array_merge( $fonts, $matches[1] );
		}

		return array_unique( $fonts );
	}

	/**
	 * Resolve a relative URL against a base URL.
	 *
	 * @param string $url      The URL to resolve.
	 * @param string $base_url The base URL.
	 *
	 * @return string|null Resolved absolute URL or null if invalid.
	 */
	private function resolve_url( string $url, string $base_url ): ?string {
		// Skip data URIs and empty URLs.
		if ( empty( $url ) || strpos( $url, 'data:' ) === 0 ) {
			return null;
		}

		// Already absolute URL.
		if ( preg_match( '/^https?:\/\//i', $url ) ) {
			return $url;
		}

		// Protocol-relative URL.
		if ( strpos( $url, '//' ) === 0 ) {
			$base_parts = wp_parse_url( $base_url );
			return ( $base_parts['scheme'] ?? 'https' ) . ':' . $url;
		}

		// Relative URL - resolve against base.
		$base_parts = wp_parse_url( $base_url );

		if ( strpos( $url, '/' ) === 0 ) {
			// Absolute path.
			return sprintf(
				'%s://%s%s',
				$base_parts['scheme'] ?? 'https',
				$base_parts['host'] ?? '',
				$url
			);
		}

		// Relative path - combine with base path.
		$base_path = dirname( $base_parts['path'] ?? '/' );
		$full_path = rtrim( $base_path, '/' ) . '/' . $url;

		// Normalize path (handle ../).
		$full_path = $this->normalize_path( $full_path );

		return sprintf(
			'%s://%s%s',
			$base_parts['scheme'] ?? 'https',
			$base_parts['host'] ?? '',
			$full_path
		);
	}

	/**
	 * Normalize a path by resolving . and .. segments.
	 *
	 * @param string $path Path to normalize.
	 *
	 * @return string Normalized path.
	 */
	private function normalize_path( string $path ): string {
		$parts  = explode( '/', $path );
		$result = [];

		foreach ( $parts as $part ) {
			if ( $part === '..' && ! empty( $result ) ) {
				array_pop( $result );
			} elseif ( $part !== '.' && $part !== '' ) {
				$result[] = $part;
			}
		}

		return '/' . implode( '/', $result );
	}

	/**
	 * Build asset information array.
	 *
	 * @param string $url       Asset URL.
	 * @param string $type      Resource type.
	 * @param string $base_host Base host for external detection.
	 *
	 * @return array Asset information.
	 */
	private function build_asset_info( string $url, string $type, string $base_host ): array {
		$url_parts = wp_parse_url( $url );
		$host      = $url_parts['host'] ?? '';

		return [
			'url'          => $url,
			'type'         => $type,
			'is_external'  => $this->is_external( $host, $base_host ),
			'fingerprinted' => $this->is_fingerprinted( $url ),
		];
	}

	/**
	 * Check if a URL is from an external host.
	 *
	 * @param string $host      The asset's host.
	 * @param string $base_host The page's host.
	 *
	 * @return bool Whether the asset is external.
	 */
	private function is_external( string $host, string $base_host ): bool {
		if ( empty( $host ) || empty( $base_host ) ) {
			return false;
		}

		// Strip www. for comparison.
		$host      = preg_replace( '/^www\./', '', $host );
		$base_host = preg_replace( '/^www\./', '', $base_host );

		return $host !== $base_host;
	}

	/**
	 * Check if a URL appears to be fingerprinted (contains hash in filename).
	 *
	 * @param string $url URL to check.
	 *
	 * @return bool Whether the URL appears fingerprinted.
	 */
	private function is_fingerprinted( string $url ): bool {
		// Remove query string for pattern matching.
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?? '';

		return (bool) preg_match( self::FINGERPRINT_PATTERN, $path );
	}

	/**
	 * Detect resource type from URL.
	 *
	 * @param string $url URL to analyze.
	 *
	 * @return string Resource type constant.
	 */
	public function detect_type( string $url ): string {
		$path      = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( $extension === 'js' ) {
			return ResourceType::JS;
		}

		if ( $extension === 'css' ) {
			return ResourceType::CSS;
		}

		if ( in_array( $extension, self::IMAGE_EXTENSIONS, true ) ) {
			return ResourceType::IMAGE;
		}

		if ( in_array( $extension, self::FONT_EXTENSIONS, true ) ) {
			return ResourceType::FONT;
		}

		if ( $extension === 'html' || $extension === 'htm' ) {
			return ResourceType::HTML;
		}

		if ( $extension === 'json' || strpos( $path, '/api/' ) !== false ) {
			return ResourceType::API;
		}

		return ResourceType::UNKNOWN;
	}
}

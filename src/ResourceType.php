<?php
/**
 * Resource type constants.
 *
 * @package CacheChecker
 */

namespace CacheChecker;

/**
 * Defines resource types for classification.
 */
class ResourceType {

	/**
	 * HTML document.
	 */
	public const HTML = 'html';

	/**
	 * JavaScript file.
	 */
	public const JS = 'js';

	/**
	 * CSS stylesheet.
	 */
	public const CSS = 'css';

	/**
	 * Image file.
	 */
	public const IMAGE = 'image';

	/**
	 * Font file.
	 */
	public const FONT = 'font';

	/**
	 * API endpoint.
	 */
	public const API = 'api';

	/**
	 * Unknown resource type.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Static asset types.
	 *
	 * @var array
	 */
	public const STATIC_TYPES = [
		self::JS,
		self::CSS,
		self::FONT,
	];

	/**
	 * All valid types.
	 *
	 * @var array
	 */
	public const ALL_TYPES = [
		self::HTML,
		self::JS,
		self::CSS,
		self::IMAGE,
		self::FONT,
		self::API,
		self::UNKNOWN,
	];

	/**
	 * Check if a type is a static asset.
	 *
	 * @param string $type Resource type.
	 *
	 * @return bool Whether it's a static asset type.
	 */
	public static function is_static( string $type ): bool {
		return in_array( $type, self::STATIC_TYPES, true );
	}
}

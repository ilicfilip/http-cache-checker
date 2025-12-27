/**
 * Rule engine for analyzing cache headers against best practices.
 */

import { ResourceType } from './resource-type.js';

const LEVEL_ERROR = 'error';
const LEVEL_WARNING = 'warning';
const LEVEL_INFO = 'info';

const MIN_STATIC_MAX_AGE = 3600; // 1 hour.

export class RuleEngine {
	/**
	 * Analyze headers for a resource and return issues.
	 */
	analyze(headers, resourceType, url, fingerprinted = false) {
		let issues = [];

		// Common issues.
		issues.push(...this.checkCommonIssues(headers));

		// Static asset issues.
		if (ResourceType.isStatic(resourceType)) {
			issues.push(...this.checkStaticAssetIssues(headers, resourceType, fingerprinted));
		}

		// HTML issues.
		if (resourceType === ResourceType.HTML) {
			issues.push(...this.checkHtmlIssues(headers));
		}

		// Image issues.
		if (resourceType === ResourceType.IMAGE) {
			issues.push(...this.checkImageIssues(headers));
		}

		// API issues.
		if (resourceType === ResourceType.API) {
			issues.push(...this.checkApiIssues(headers));
		}

		// Font issues.
		if (resourceType === ResourceType.FONT) {
			issues.push(...this.checkFontIssues(headers));
		}

		// CDN issues.
		if (headers.cdn && headers.cdn.detected) {
			issues.push(...this.checkCdnIssues(headers, resourceType));
		}

		return issues;
	}

	/**
	 * Check for common cache header issues.
	 */
	checkCommonIssues(headers) {
		const issues = [];
		const cc = headers.cache_control;

		// Conflicting directives.
		if (cc.no_store && cc.max_age !== null && cc.max_age > 0) {
			issues.push(
				this.createIssue(
					'conflicting_directives',
					'no-store conflicts with max-age',
					'Cache-Control contains both no-store and max-age, which is contradictory.',
					LEVEL_ERROR
				)
			);
		}

		if (cc.public && cc.private) {
			issues.push(
				this.createIssue(
					'conflicting_directives',
					'public and private directives conflict',
					'Cache-Control cannot be both public and private.',
					LEVEL_ERROR
				)
			);
		}

		// Vary: * makes resource uncacheable.
		if (headers.vary && headers.vary.has_wildcard) {
			issues.push(
				this.createIssue(
					'vary_wildcard',
					'Vary: * makes resource effectively uncacheable',
					'Using Vary: * means each request is treated as unique, defeating caching.',
					LEVEL_ERROR
				)
			);
		}

		// Vary: User-Agent causes cache fragmentation.
		if (headers.vary && headers.vary.has_user_agent) {
			issues.push(
				this.createIssue(
					'vary_user_agent',
					'Vary: User-Agent causes severe cache fragmentation',
					'User-Agent has thousands of variants. Consider normalizing at CDN level instead.',
					LEVEL_WARNING
				)
			);
		}

		// Vary: Cookie can cause fragmentation.
		if (headers.vary && headers.vary.has_cookie) {
			issues.push(
				this.createIssue(
					'vary_cookie',
					'Vary: Cookie may cause cache fragmentation',
					'Cookie values vary per user. Consider bypassing cache for authenticated users instead.',
					LEVEL_WARNING
				)
			);
		}

		return issues;
	}

	/**
	 * Check issues specific to static assets.
	 */
	checkStaticAssetIssues(headers, resourceType, fingerprinted) {
		const issues = [];
		const cc = headers.cache_control;

		// no-store on static assets.
		if (cc.no_store) {
			issues.push(
				this.createIssue(
					'no_store_static',
					'no-store on static asset is wasteful',
					'Static assets should be cached. no-store prevents all caching.',
					LEVEL_ERROR
				)
			);
		}

		// no-cache on fingerprinted assets.
		if (fingerprinted && cc.no_cache) {
			issues.push(
				this.createIssue(
					'no_cache_fingerprinted',
					'no-cache on fingerprinted asset causes unnecessary revalidation',
					'Fingerprinted assets have unique URLs and can be cached immutably.',
					LEVEL_WARNING
				)
			);
		}

		// Missing immutable on fingerprinted assets.
		if (fingerprinted && !cc.immutable) {
			issues.push(
				this.createIssue(
					'missing_immutable',
					'Missing immutable directive on fingerprinted asset',
					'Fingerprinted assets should use immutable to prevent revalidation on reload.',
					LEVEL_WARNING
				)
			);
		}

		// Check max-age for fingerprinted assets.
		if (fingerprinted) {
			if (cc.max_age === null) {
				issues.push(
					this.createIssue(
						'missing_max_age',
						'Missing max-age on fingerprinted static asset',
						'Fingerprinted assets should have max-age=31536000 (1 year).',
						LEVEL_ERROR
					)
				);
			} else if (cc.max_age < 86400) {
				issues.push(
					this.createIssue(
						'short_max_age_fingerprinted',
						'Short max-age on fingerprinted asset',
						`Fingerprinted assets should have max-age=31536000. Current: ${cc.max_age} seconds.`,
						LEVEL_WARNING
					)
				);
			}
		} else {
			// Non-fingerprinted static assets.
			if (cc.max_age !== null && cc.max_age < MIN_STATIC_MAX_AGE) {
				issues.push(
					this.createIssue(
						'short_max_age',
						'Short max-age on static asset',
						`Static assets should have at least 1 hour caching. Current: ${cc.max_age} seconds.`,
						LEVEL_WARNING
					)
				);
			}

			// Non-fingerprinted should have ETag for revalidation.
			if (headers.etag === null && headers.last_modified === null) {
				issues.push(
					this.createIssue(
						'missing_validators',
						'Missing ETag and Last-Modified on non-fingerprinted asset',
						'Without fingerprinting, assets need validators for efficient revalidation.',
						LEVEL_INFO
					)
				);
			}
		}

		// private on public static assets.
		if (cc.private && !cc.no_store) {
			issues.push(
				this.createIssue(
					'private_static',
					'private directive on static asset',
					'Static assets should be public to allow CDN caching.',
					LEVEL_WARNING
				)
			);
		}

		return issues;
	}

	/**
	 * Check issues specific to HTML documents.
	 */
	checkHtmlIssues(headers) {
		const issues = [];
		const cc = headers.cache_control;

		// Missing stale-while-revalidate.
		if (cc.stale_while_revalidate === null && !cc.no_store && !cc.private) {
			issues.push(
				this.createIssue(
					'missing_swr',
					'Missing stale-while-revalidate on HTML',
					'stale-while-revalidate improves perceived performance during revalidation.',
					LEVEL_INFO
				)
			);
		}

		// Missing validators.
		if (headers.etag === null && headers.last_modified === null) {
			if (!cc.no_store) {
				issues.push(
					this.createIssue(
						'missing_validators_html',
						'Missing ETag or Last-Modified on HTML',
						'Validators enable cheap 304 revalidation responses.',
						LEVEL_WARNING
					)
				);
			}
		}

		// immutable on HTML (bad practice).
		if (cc.immutable) {
			issues.push(
				this.createIssue(
					'immutable_html',
					'immutable directive on HTML document',
					'HTML content changes; immutable locks users into stale versions.',
					LEVEL_ERROR
				)
			);
		}

		// Very long max-age without s-maxage.
		if (cc.max_age !== null && cc.max_age > 3600 && cc.s_maxage === null) {
			issues.push(
				this.createIssue(
					'long_browser_cache_html',
					'Long browser cache for HTML without CDN differentiation',
					`max-age of ${cc.max_age} seconds is long for HTML. Consider using shorter max-age with longer s-maxage.`,
					LEVEL_INFO
				)
			);
		}

		return issues;
	}

	/**
	 * Check issues specific to images.
	 */
	checkImageIssues(headers) {
		const issues = [];
		const cc = headers.cache_control;

		// no-store on images.
		if (cc.no_store) {
			issues.push(
				this.createIssue(
					'no_store_image',
					'no-store on image is wasteful',
					'Images should be cached for at least one day.',
					LEVEL_WARNING
				)
			);
		}

		// Short max-age on images.
		if (cc.max_age !== null && cc.max_age < 3600) {
			issues.push(
				this.createIssue(
					'short_max_age_image',
					'Short max-age on image',
					`Images should typically be cached for at least 1 day. Current: ${cc.max_age} seconds.`,
					LEVEL_INFO
				)
			);
		}

		return issues;
	}

	/**
	 * Check issues specific to API endpoints.
	 */
	checkApiIssues(headers) {
		const issues = [];
		const cc = headers.cache_control;

		// APIs should generally use s-maxage for CDN caching.
		if (cc.max_age !== null && cc.s_maxage === null && !cc.private) {
			issues.push(
				this.createIssue(
					'missing_s_maxage_api',
					'API using max-age without s-maxage',
					'Consider using s-maxage to differentiate CDN caching from browser caching.',
					LEVEL_INFO
				)
			);
		}

		// stale-if-error on APIs.
		if (cc.stale_if_error === null && !cc.no_store && !cc.private) {
			issues.push(
				this.createIssue(
					'missing_stale_if_error',
					'Missing stale-if-error on API',
					'stale-if-error provides resilience during origin outages.',
					LEVEL_INFO
				)
			);
		}

		return issues;
	}

	/**
	 * Check issues specific to font files.
	 */
	checkFontIssues(headers) {
		const issues = [];

		// Fonts need CORS headers.
		if (!headers.cors || !headers.cors.has_cors) {
			issues.push(
				this.createIssue(
					'missing_cors_font',
					'Missing CORS header on font',
					'Fonts need Access-Control-Allow-Origin header for preload to work correctly.',
					LEVEL_WARNING
				)
			);
		}

		return issues;
	}

	/**
	 * Check CDN-specific issues.
	 */
	checkCdnIssues(headers, resourceType) {
		const issues = [];
		const cc = headers.cache_control;

		// Behind CDN but no s-maxage for public resources.
		if (!cc.private && !cc.no_store && cc.s_maxage === null) {
			if (resourceType === ResourceType.HTML || resourceType === ResourceType.API) {
				issues.push(
					this.createIssue(
						'missing_s_maxage_cdn',
						'Behind CDN but missing s-maxage',
						'Use s-maxage to set different cache duration for CDN vs browser.',
						LEVEL_INFO
					)
				);
			}
		}

		// Check CDN cache status.
		const cacheStatus = (headers.cdn.cache_status || '').toUpperCase();
		if (['BYPASS', 'DYNAMIC', 'MISS'].includes(cacheStatus)) {
			if (ResourceType.isStatic(resourceType)) {
				issues.push(
					this.createIssue(
						'cdn_not_caching',
						`CDN reports ${cacheStatus} for static asset`,
						'Static assets should be cached at the CDN level.',
						LEVEL_WARNING
					)
				);
			}
		}

		return issues;
	}

	/**
	 * Create an issue object.
	 */
	createIssue(code, title, description, level) {
		return {
			code: code,
			title: title,
			description: description,
			level: level,
		};
	}
}

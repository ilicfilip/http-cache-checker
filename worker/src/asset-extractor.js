/**
 * Asset extractor for parsing HTML and extracting asset URLs.
 */

import { ResourceType } from './resource-type.js';

/**
 * Fingerprint detection regex patterns.
 */
const FINGERPRINT_PATTERNS = [
	/[.\-_][a-f0-9]{8,32}\.(js|css|woff2?|ttf|eot|otf|png|jpe?g|gif|svg|webp|avif)$/i,
	/[.\-_]v?\d+\.\d+\.\d+[.\-_]?\.(js|css)$/i,
	/\.(chunk|bundle)\.[a-f0-9]+\.(js|css)$/i,
	/[?&]v(er)?=[a-f0-9]{6,}$/i,
	/[?&]ver=\d+\.\d+/i,
];

/**
 * Image extensions to detect.
 */
const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico'];

/**
 * Font extensions to detect.
 */
const FONT_EXTENSIONS = ['woff', 'woff2', 'ttf', 'eot', 'otf'];

/**
 * Tags to strip content from before extracting images.
 */
const TAGS_TO_STRIP = ['script', 'style', 'noscript'];

export class AssetExtractor {
	/**
	 * Extract assets from HTML content.
	 */
	extract(html, baseUrl) {
		const assets = [];
		const seen = new Set();
		const baseUrlObj = new URL(baseUrl);

		// Extract scripts.
		assets.push(...this.extractScripts(html, baseUrl, seen));

		// Extract stylesheets.
		assets.push(...this.extractStylesheets(html, baseUrl, seen));

		// Extract images (after stripping script/style content).
		const cleanHtml = this.stripTagContent(html, TAGS_TO_STRIP);
		assets.push(...this.extractImages(cleanHtml, baseUrl, seen));

		// Extract fonts from inline styles and link preloads.
		assets.push(...this.extractFonts(html, baseUrl, seen));

		return assets;
	}

	/**
	 * Strip content from specified tags.
	 */
	stripTagContent(html, tags) {
		let result = html;
		for (const tag of tags) {
			const regex = new RegExp(`<${tag}[^>]*>[\\s\\S]*?</${tag}>`, 'gi');
			result = result.replace(regex, '');
		}
		return result;
	}

	/**
	 * Extract script sources.
	 */
	extractScripts(html, baseUrl, seen) {
		const assets = [];
		const regex = /<script[^>]+src=["']([^"']+)["'][^>]*>/gi;
		let match;

		while ((match = regex.exec(html)) !== null) {
			const src = match[1];
			const asset = this.createAsset(src, baseUrl, ResourceType.JS, seen);
			if (asset) {
				assets.push(asset);
			}
		}

		return assets;
	}

	/**
	 * Extract stylesheet links.
	 */
	extractStylesheets(html, baseUrl, seen) {
		const assets = [];
		const regex = /<link[^>]+rel=["']stylesheet["'][^>]+href=["']([^"']+)["'][^>]*>/gi;
		const regex2 = /<link[^>]+href=["']([^"']+)["'][^>]+rel=["']stylesheet["'][^>]*>/gi;
		let match;

		while ((match = regex.exec(html)) !== null) {
			const href = match[1];
			const asset = this.createAsset(href, baseUrl, ResourceType.CSS, seen);
			if (asset) {
				assets.push(asset);
			}
		}

		while ((match = regex2.exec(html)) !== null) {
			const href = match[1];
			const asset = this.createAsset(href, baseUrl, ResourceType.CSS, seen);
			if (asset) {
				assets.push(asset);
			}
		}

		return assets;
	}

	/**
	 * Extract image sources.
	 */
	extractImages(html, baseUrl, seen) {
		const assets = [];

		// <img src="...">
		const imgRegex = /<img[^>]+src=["']([^"']+)["'][^>]*>/gi;
		let match;

		while ((match = imgRegex.exec(html)) !== null) {
			const src = match[1];
			if (this.isImageUrl(src)) {
				const asset = this.createAsset(src, baseUrl, ResourceType.IMAGE, seen);
				if (asset) {
					assets.push(asset);
				}
			}
		}

		// srcset attribute.
		const srcsetRegex = /srcset=["']([^"']+)["']/gi;
		while ((match = srcsetRegex.exec(html)) !== null) {
			const srcset = match[1];
			const urls = srcset.split(',').map((s) => s.trim().split(/\s+/)[0]);

			for (const src of urls) {
				if (this.isImageUrl(src)) {
					const asset = this.createAsset(src, baseUrl, ResourceType.IMAGE, seen);
					if (asset) {
						assets.push(asset);
					}
				}
			}
		}

		return assets;
	}

	/**
	 * Extract font URLs.
	 */
	extractFonts(html, baseUrl, seen) {
		const assets = [];

		// Preload fonts.
		const preloadRegex = /<link[^>]+rel=["']preload["'][^>]+as=["']font["'][^>]+href=["']([^"']+)["'][^>]*>/gi;
		const preloadRegex2 = /<link[^>]+href=["']([^"']+)["'][^>]+rel=["']preload["'][^>]+as=["']font["'][^>]*>/gi;
		let match;

		while ((match = preloadRegex.exec(html)) !== null) {
			const href = match[1];
			const asset = this.createAsset(href, baseUrl, ResourceType.FONT, seen);
			if (asset) {
				assets.push(asset);
			}
		}

		while ((match = preloadRegex2.exec(html)) !== null) {
			const href = match[1];
			const asset = this.createAsset(href, baseUrl, ResourceType.FONT, seen);
			if (asset) {
				assets.push(asset);
			}
		}

		// @font-face urls in inline styles.
		const fontFaceRegex = /url\(["']?([^"')]+\.(?:woff2?|ttf|eot|otf))["']?\)/gi;
		while ((match = fontFaceRegex.exec(html)) !== null) {
			const url = match[1];
			const asset = this.createAsset(url, baseUrl, ResourceType.FONT, seen);
			if (asset) {
				assets.push(asset);
			}
		}

		return assets;
	}

	/**
	 * Create an asset object.
	 */
	createAsset(url, baseUrl, type, seen) {
		// Skip data URIs and empty URLs.
		if (!url || url.startsWith('data:') || url.startsWith('blob:') || url.startsWith('#')) {
			return null;
		}

		// Resolve relative URLs.
		let absoluteUrl;
		try {
			absoluteUrl = new URL(url, baseUrl).href;
		} catch (e) {
			return null;
		}

		// Skip duplicates.
		if (seen.has(absoluteUrl)) {
			return null;
		}
		seen.add(absoluteUrl);

		// Check if external.
		const baseHost = new URL(baseUrl).hostname;
		const assetHost = new URL(absoluteUrl).hostname;
		const isExternal = baseHost !== assetHost;

		return {
			url: absoluteUrl,
			type: type,
			is_external: isExternal,
			fingerprinted: this.isFingerprinted(absoluteUrl),
		};
	}

	/**
	 * Check if URL is an image.
	 */
	isImageUrl(url) {
		const ext = url.split('?')[0].split('.').pop().toLowerCase();
		return IMAGE_EXTENSIONS.includes(ext);
	}

	/**
	 * Check if URL appears to be fingerprinted.
	 */
	isFingerprinted(url) {
		for (const pattern of FINGERPRINT_PATTERNS) {
			if (pattern.test(url)) {
				return true;
			}
		}
		return false;
	}
}

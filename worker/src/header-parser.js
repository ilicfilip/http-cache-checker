/**
 * Header parser for HTTP caching headers.
 */

const CDN_HEADERS = [
	'cf-cache-status',
	'x-cache',
	'x-vercel-cache',
	'x-fastly-request-id',
	'x-served-by',
	'x-cdn',
	'x-edge-location',
	'x-akamai-request-id',
	'x-cache-hits',
];

export class HeaderParser {
	/**
	 * Parse HTTP headers into structured format.
	 */
	parse(headers) {
		// Normalize header keys to lowercase.
		const normalized = {};
		for (const [key, value] of Object.entries(headers)) {
			normalized[key.toLowerCase()] = Array.isArray(value) ? value[0] : value;
		}

		return {
			cache_control: this.parseCacheControl(normalized['cache-control'] || ''),
			expires: this.parseExpires(normalized['expires'] || ''),
			etag: this.parseEtag(normalized['etag'] || ''),
			last_modified: normalized['last-modified'] || null,
			vary: this.parseVary(normalized['vary'] || ''),
			age: normalized['age'] ? parseInt(normalized['age']) : null,
			cdn: this.detectCdn(normalized),
			cors: this.parseCors(normalized),
			content_type: normalized['content-type'] || null,
			raw: normalized,
		};
	}

	/**
	 * Parse Cache-Control header.
	 */
	parseCacheControl(header) {
		if (!header) {
			return {
				raw: '',
				directives: {},
				max_age: null,
				s_maxage: null,
				public: false,
				private: false,
				no_cache: false,
				no_store: false,
				immutable: false,
				must_revalidate: false,
				stale_while_revalidate: null,
				stale_if_error: null,
			};
		}

		const directives = {};
		const parts = header.split(',').map((p) => p.trim());

		for (const part of parts) {
			if (part.includes('=')) {
				const [key, value] = part.split('=', 2);
				directives[key.toLowerCase().trim()] = value.replace(/['"]/g, '').trim();
			} else {
				directives[part.toLowerCase().trim()] = true;
			}
		}

		return {
			raw: header,
			directives: directives,
			max_age: directives['max-age'] !== undefined ? parseInt(directives['max-age']) : null,
			s_maxage: directives['s-maxage'] !== undefined ? parseInt(directives['s-maxage']) : null,
			public: 'public' in directives,
			private: 'private' in directives,
			no_cache: 'no-cache' in directives,
			no_store: 'no-store' in directives,
			immutable: 'immutable' in directives,
			must_revalidate: 'must-revalidate' in directives,
			stale_while_revalidate:
				directives['stale-while-revalidate'] !== undefined
					? parseInt(directives['stale-while-revalidate'])
					: null,
			stale_if_error:
				directives['stale-if-error'] !== undefined ? parseInt(directives['stale-if-error']) : null,
		};
	}

	/**
	 * Parse Expires header.
	 */
	parseExpires(header) {
		if (!header) {
			return null;
		}

		const timestamp = Date.parse(header);

		if (isNaN(timestamp)) {
			return {
				raw: header,
				timestamp: null,
				valid: false,
			};
		}

		const now = Date.now();

		return {
			raw: header,
			timestamp: Math.floor(timestamp / 1000),
			valid: true,
			is_expired: timestamp < now,
			seconds: Math.max(0, Math.floor((timestamp - now) / 1000)),
		};
	}

	/**
	 * Parse ETag header.
	 */
	parseEtag(header) {
		if (!header) {
			return null;
		}

		const isWeak = header.startsWith('W/');
		let value = isWeak ? header.substring(2) : header;
		value = value.replace(/^"|"$/g, '');

		return {
			raw: header,
			value: value,
			is_weak: isWeak,
		};
	}

	/**
	 * Parse Vary header.
	 */
	parseVary(header) {
		if (!header) {
			return {
				raw: '',
				headers: [],
			};
		}

		const headers = header.split(',').map((h) => h.trim().toLowerCase());

		return {
			raw: header,
			headers: headers,
			has_accept_encoding: headers.includes('accept-encoding'),
			has_cookie: headers.includes('cookie'),
			has_user_agent: headers.includes('user-agent'),
			has_wildcard: headers.includes('*'),
		};
	}

	/**
	 * Detect CDN from headers.
	 */
	detectCdn(headers) {
		const result = {
			detected: false,
			provider: null,
			cache_status: null,
			indicators: {},
		};

		for (const cdnHeader of CDN_HEADERS) {
			if (headers[cdnHeader]) {
				result.detected = true;
				result.indicators[cdnHeader] = headers[cdnHeader];
			}
		}

		// Identify specific provider.
		if (headers['cf-cache-status']) {
			result.provider = 'Cloudflare';
			result.cache_status = headers['cf-cache-status'];
		} else if (headers['x-vercel-cache']) {
			result.provider = 'Vercel';
			result.cache_status = headers['x-vercel-cache'];
		} else if (headers['x-fastly-request-id']) {
			result.provider = 'Fastly';
			result.cache_status = headers['x-cache'] || null;
		} else if (headers['x-akamai-request-id']) {
			result.provider = 'Akamai';
			result.cache_status = headers['x-cache'] || null;
		} else if (headers['x-cache']) {
			result.provider = 'Unknown CDN';
			result.cache_status = headers['x-cache'];
		}

		return result;
	}

	/**
	 * Parse CORS headers.
	 */
	parseCors(headers) {
		const acao = headers['access-control-allow-origin'] || null;

		return {
			allow_origin: acao,
			has_cors: acao !== null,
			allows_all: acao === '*',
			allow_credentials:
				headers['access-control-allow-credentials'] &&
				headers['access-control-allow-credentials'].toLowerCase() === 'true',
		};
	}
}

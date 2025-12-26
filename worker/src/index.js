/**
 * Cache Checker Cloudflare Worker
 *
 * Analyzes HTTP cache headers for a URL and its assets.
 * Offloads the checking from your origin server to Cloudflare's edge.
 */

import { HeaderParser } from './header-parser.js';
import { AssetExtractor } from './asset-extractor.js';
import { RuleEngine } from './rule-engine.js';
import { ResourceType } from './resource-type.js';

export default {
	async fetch(request, env, ctx) {
		// Handle CORS preflight.
		if (request.method === 'OPTIONS') {
			return handleCors();
		}

		const url = new URL(request.url);

		// Health check endpoint (no auth required).
		if (url.pathname === '/health') {
			return jsonResponse({ status: 'ok', timestamp: new Date().toISOString() });
		}

		// All other endpoints require API key authentication.
		const authError = validateApiKey(request, env);
		if (authError) {
			return authError;
		}

		// Main analysis endpoint.
		if (url.pathname === '/analyze') {
			return handleAnalyze(request, env);
		}

		// API documentation.
		if (url.pathname === '/' || url.pathname === '') {
			return jsonResponse({
				name: 'Cache Checker Worker',
				version: '0.1.0',
				authentication: 'Required via X-API-Key header or ?api_key= parameter',
				endpoints: {
					'/analyze': {
						method: 'GET or POST',
						params: {
							url: 'URL to analyze (required)',
							check_assets: 'Whether to check assets (default: true)',
							max_assets: 'Maximum assets to check (default: 50)',
						},
					},
					'/health': {
						method: 'GET',
						description: 'Health check endpoint (no auth required)',
					},
				},
			});
		}

		return jsonResponse({ error: 'Not found' }, 404);
	},
};

/**
 * Validate API key from request.
 * Accepts key via X-API-Key header or api_key query parameter.
 */
function validateApiKey(request, env) {
	// Check if API_KEY secret is configured.
	if (!env.API_KEY) {
		// No API key configured - allow all requests (development mode).
		return null;
	}

	const url = new URL(request.url);

	// Check X-API-Key header first.
	let providedKey = request.headers.get('X-API-Key');

	// Fall back to query parameter.
	if (!providedKey) {
		providedKey = url.searchParams.get('api_key');
	}

	if (!providedKey) {
		return jsonResponse(
			{ error: 'Missing API key. Provide via X-API-Key header or api_key parameter.' },
			401
		);
	}

	// Constant-time comparison to prevent timing attacks.
	if (!secureCompare(providedKey, env.API_KEY)) {
		return jsonResponse({ error: 'Invalid API key' }, 403);
	}

	return null; // Auth successful.
}

/**
 * Constant-time string comparison.
 */
function secureCompare(a, b) {
	if (a.length !== b.length) {
		return false;
	}

	let result = 0;
	for (let i = 0; i < a.length; i++) {
		result |= a.charCodeAt(i) ^ b.charCodeAt(i);
	}
	return result === 0;
}

/**
 * Handle the analyze request.
 */
async function handleAnalyze(request, env) {
	let targetUrl;
	let checkAssets = true;
	let maxAssets = parseInt(env.MAX_ASSETS) || 50;

	// Parse parameters from GET or POST.
	if (request.method === 'GET') {
		const url = new URL(request.url);
		targetUrl = url.searchParams.get('url');
		checkAssets = url.searchParams.get('check_assets') !== 'false';
		if (url.searchParams.get('max_assets')) {
			maxAssets = parseInt(url.searchParams.get('max_assets'));
		}
	} else if (request.method === 'POST') {
		try {
			const body = await request.json();
			targetUrl = body.url;
			checkAssets = body.check_assets !== false;
			if (body.max_assets) {
				maxAssets = parseInt(body.max_assets);
			}
		} catch (e) {
			return jsonResponse({ error: 'Invalid JSON body' }, 400);
		}
	} else {
		return jsonResponse({ error: 'Method not allowed' }, 405);
	}

	if (!targetUrl) {
		return jsonResponse({ error: 'Missing required parameter: url' }, 400);
	}

	// Add https:// if protocol is omitted.
	if (!targetUrl.match(/^https?:\/\//i)) {
		targetUrl = 'https://' + targetUrl;
	}

	// Validate URL.
	try {
		new URL(targetUrl);
	} catch (e) {
		return jsonResponse({ error: 'Invalid URL provided' }, 400);
	}

	try {
		const result = await analyzeUrl(targetUrl, { checkAssets, maxAssets, env });
		return jsonResponse(result);
	} catch (e) {
		return jsonResponse({ error: e.message }, 500);
	}
}

/**
 * Main analysis function.
 */
async function analyzeUrl(url, options) {
	const headerParser = new HeaderParser();
	const assetExtractor = new AssetExtractor();
	const ruleEngine = new RuleEngine();

	const report = {
		url: url,
		timestamp: new Date().toISOString(),
		redirect: null,
		main_page: null,
		assets: [],
		summary: {
			total_assets: 0,
			total_issues: 0,
			issues_by_level: {
				error: 0,
				warning: 0,
				info: 0,
			},
		},
	};

	// Check for redirect first.
	const redirectInfo = await checkRedirect(url, headerParser, ruleEngine);
	let finalUrl = url;

	if (redirectInfo) {
		report.redirect = redirectInfo;
		finalUrl = redirectInfo.final_url;

		// Count redirect issues.
		for (const issue of redirectInfo.issues) {
			report.summary.total_issues++;
			report.summary.issues_by_level[issue.level]++;
		}
	}

	// Fetch main page.
	let mainResponse;
	try {
		mainResponse = await fetch(finalUrl, {
			headers: {
				'User-Agent': 'CacheChecker/1.0 (Cloudflare Worker)',
			},
			redirect: 'follow',
		});
	} catch (e) {
		report.error = `Failed to fetch URL: ${e.message}`;
		return report;
	}

	const mainHeaders = Object.fromEntries(mainResponse.headers.entries());
	const mainBody = await mainResponse.text();
	const parsedHeaders = headerParser.parse(mainHeaders);

	// Analyze main page.
	const mainIssues = ruleEngine.analyze(parsedHeaders, ResourceType.HTML, finalUrl);

	report.main_page = {
		url: finalUrl,
		status_code: mainResponse.status,
		resource_type: ResourceType.HTML,
		headers: parsedHeaders,
		issues: mainIssues,
	};

	// Count main page issues.
	for (const issue of mainIssues) {
		report.summary.total_issues++;
		report.summary.issues_by_level[issue.level]++;
	}

	// Extract and analyze assets.
	if (options.checkAssets && mainBody) {
		const assets = assetExtractor.extract(mainBody, finalUrl);
		const limitedAssets = assets.slice(0, options.maxAssets);

		report.summary.total_assets = limitedAssets.length;

		// Fetch assets in parallel with concurrency limit.
		const assetReports = await Promise.all(
			limitedAssets.map((asset) => analyzeAsset(asset, headerParser, ruleEngine))
		);

		for (const assetReport of assetReports) {
			if (assetReport) {
				report.assets.push(assetReport);

				for (const issue of assetReport.issues) {
					report.summary.total_issues++;
					report.summary.issues_by_level[issue.level]++;
				}
			}
		}
	}

	return report;
}

/**
 * Check if URL redirects.
 */
async function checkRedirect(url, headerParser, ruleEngine) {
	try {
		const response = await fetch(url, {
			headers: {
				'User-Agent': 'CacheChecker/1.0 (Cloudflare Worker)',
			},
			redirect: 'manual', // Don't follow redirects.
		});

		const statusCode = response.status;

		// Not a redirect.
		if (statusCode < 300 || statusCode >= 400) {
			return null;
		}

		const location = response.headers.get('location');
		if (!location) {
			return null;
		}

		// Resolve relative redirect URL.
		let finalUrl = location;
		if (!location.match(/^https?:\/\//i)) {
			const urlObj = new URL(url);
			finalUrl = urlObj.origin + location;
		}

		const headers = Object.fromEntries(response.headers.entries());
		const parsedHeaders = headerParser.parse(headers);

		// Check for redirect-specific issues.
		const issues = [];
		const cc = parsedHeaders.cache_control;

		if (statusCode === 301) {
			if (cc.max_age === null && cc.s_maxage === null && !cc.no_store) {
				issues.push({
					code: 'uncached_permanent_redirect',
					title: 'Permanent redirect (301) is not cached',
					description: '301 redirects should be cached to avoid repeated redirect lookups.',
					level: 'warning',
				});
			}
		} else if (statusCode === 302 || statusCode === 307) {
			if (cc.max_age === null && cc.s_maxage === null) {
				issues.push({
					code: 'uncached_temporary_redirect',
					title: `Temporary redirect (${statusCode}) is not cached`,
					description: 'Consider caching temporary redirects briefly to reduce origin load.',
					level: 'info',
				});
			}
		}

		return {
			original_url: url,
			final_url: finalUrl,
			status_code: statusCode,
			headers: parsedHeaders,
			issues: issues,
		};
	} catch (e) {
		return null;
	}
}

/**
 * Analyze a single asset.
 */
async function analyzeAsset(asset, headerParser, ruleEngine) {
	try {
		const response = await fetch(asset.url, {
			method: 'HEAD',
			headers: {
				'User-Agent': 'CacheChecker/1.0 (Cloudflare Worker)',
			},
		});

		const headers = Object.fromEntries(response.headers.entries());
		const parsedHeaders = headerParser.parse(headers);

		const issues = ruleEngine.analyze(parsedHeaders, asset.type, asset.url, asset.fingerprinted);

		return {
			url: asset.url,
			resource_type: asset.type,
			is_external: asset.is_external,
			fingerprinted: asset.fingerprinted,
			status_code: response.status,
			headers: parsedHeaders,
			issues: issues,
		};
	} catch (e) {
		return {
			url: asset.url,
			resource_type: asset.type,
			is_external: asset.is_external,
			fingerprinted: asset.fingerprinted,
			error: e.message,
			headers: {},
			issues: [],
		};
	}
}

/**
 * Return a JSON response with CORS headers.
 */
function jsonResponse(data, status = 200) {
	return new Response(JSON.stringify(data, null, 2), {
		status: status,
		headers: {
			'Content-Type': 'application/json',
			'Access-Control-Allow-Origin': '*',
			'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
			'Access-Control-Allow-Headers': 'Content-Type, X-API-Key',
		},
	});
}

/**
 * Handle CORS preflight requests.
 */
function handleCors() {
	return new Response(null, {
		headers: {
			'Access-Control-Allow-Origin': '*',
			'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
			'Access-Control-Allow-Headers': 'Content-Type, X-API-Key',
			'Access-Control-Max-Age': '86400',
		},
	});
}

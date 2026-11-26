# Cache Checker

HTTP Cache Header Analyzer - checks caching headers for a URL and its assets against best practices.

Based on [Jono Alderson's HTTP Caching Guide](https://www.jonoalderson.com/performance/http-caching/).

Write-up: [I built a tool to audit HTTP cache headers](https://filip.ilic.sh/blog/http-cache-checker/) — why it exists and what it catches.

## Features

- Analyzes Cache-Control, Expires, ETag, Last-Modified, Vary, Age headers
- Extracts and checks JS, CSS, images, and fonts from HTML
- Detects fingerprinted assets and CDN usage
- Flags common misconfigurations (conflicting directives, missing immutable, etc.)
- Multiple output formats: text, JSON, HTML table

## Installation

### As WordPress Plugin

1. Upload the `cache-checker` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress

### Standalone (no WordPress)

Just clone/copy the files - no installation needed.

---

## Usage

### 1. WP-CLI Command

The most common way to use the tool within WordPress:

```bash
# Basic usage
wp cache-check https://example.com

# JSON output
wp cache-check https://example.com --format=json

# Skip asset checking (faster, main page only)
wp cache-check https://example.com --no-assets

# Limit number of assets
wp cache-check https://example.com --max-assets=20

# Filter by issue level
wp cache-check https://example.com --level=warning

# Protocol can be omitted (defaults to https)
wp cache-check example.com
```

**Options:**

| Option | Description |
|--------|-------------|
| `--format=<format>` | Output format: `text` (default), `json`, `table` |
| `--no-assets` | Skip checking assets, analyze main page only |
| `--max-assets=<num>` | Maximum assets to check (default: 100) |
| `--level=<level>` | Minimum issue level: `error`, `warning`, `info` |

### 2. WordPress Plugin (PHP)

Use the `CacheChecker` class directly in your code:

```php
use CacheChecker\CacheChecker;
use CacheChecker\Report;

// Create checker with options
$checker = new CacheChecker([
    'timeout'      => 30,      // Request timeout in seconds
    'max_assets'   => 50,      // Maximum assets to check
    'check_assets' => true,    // Whether to check assets
]);

// Analyze a URL
$result = $checker->analyze('https://example.com');

// Create a report
$report = new Report($result);

// Output formats
echo $report->to_text();           // Human-readable text
echo $report->to_json();           // JSON
echo $report->to_html();           // HTML table

// Check for issues
if ($report->has_issues('error')) {
    // Has errors
}
if ($report->has_issues('warning')) {
    // Has warnings or errors
}

// Get raw data
$data = $report->to_array();
```

### 3. Standalone Script (no WordPress)

For use outside WordPress:

```bash
# Basic usage
php standalone.php https://example.com

# JSON output
php standalone.php https://example.com --json

# Protocol can be omitted
php standalone.php example.com
```

**Exit codes:**
- `0` - No errors found
- `1` - Errors found (or invalid URL)

---

## Cloudflare Worker Mode

For high-volume checking or to avoid IP-based rate limiting, offload the analysis to a Cloudflare Worker.

### Benefits

- **Distributed IPs**: Requests come from Cloudflare's IP ranges
- **No origin load**: Your server doesn't perform HTTP requests
- **Global edge**: Checks run from nearest Cloudflare location
- **Scalable**: Handle many concurrent checks

### Setup

```bash
cd worker
npm install
npm run login
npm run deploy
```

After deployment, you'll get a URL like:
```
https://cache-checker.your-subdomain.workers.dev
```

### Protect with API Key (recommended)

```bash
npx wrangler secret put API_KEY
# Enter a strong random key when prompted
```

### Using the Worker

**Via curl:**
```bash
# Without API key (dev mode)
curl "https://cache-checker.your-subdomain.workers.dev/analyze?url=example.com"

# With API key (header)
curl -H "X-API-Key: your-key" \
  "https://cache-checker.your-subdomain.workers.dev/analyze?url=example.com"

# With API key (query param)
curl "https://cache-checker.your-subdomain.workers.dev/analyze?url=example.com&api_key=your-key"
```

**Via PHP (WorkerClient):**
```php
use CacheChecker\WorkerClient;
use CacheChecker\Report;

$client = new WorkerClient(
    'https://cache-checker.your-subdomain.workers.dev',
    'your-api-key'  // Leave empty if no API_KEY secret is set
);

// Check worker health
if ($client->health_check()) {
    // Analyze URL
    $result = $client->analyze('https://example.com', true, 50);

    if (!is_wp_error($result)) {
        $report = new Report($result);
        echo $report->to_text();
    }
}
```

**Health check (no auth required):**
```bash
curl https://cache-checker.your-subdomain.workers.dev/health
```

### Worker NPM Scripts

```bash
npm run dev          # Local dev server
npm run deploy       # Deploy to Cloudflare
npm run tail         # Stream real-time logs
npm run list         # List deployments
npm run rollback     # Rollback to previous version
```

See [worker/README.md](worker/README.md) for full Wrangler CLI documentation.

---

## Architecture

### PHP Classes

| Class | Description |
|-------|-------------|
| `CacheChecker` | Main orchestrator - fetches URL/assets and runs analysis |
| `HeaderParser` | Parses HTTP headers into structured format |
| `AssetExtractor` | Extracts JS/CSS/image/font URLs from HTML |
| `RuleEngine` | Checks headers against best practice rules |
| `Report` | Formats results for output |
| `ResourceType` | Resource type constants (HTML, JS, CSS, etc.) |
| `WorkerClient` | Client for calling Cloudflare Worker API |

### JavaScript (Worker)

The worker contains JavaScript ports of the PHP classes:
- `header-parser.js`
- `asset-extractor.js`
- `rule-engine.js`
- `resource-type.js`

---

## Issues Detected

### Errors (critical)
- `no-store` on static assets
- `no-store` with `max-age` (conflicting)
- `public` and `private` together
- `Vary: *` (uncacheable)
- `immutable` on HTML
- Missing `max-age` on fingerprinted assets

### Warnings
- Missing `immutable` on fingerprinted assets
- Short `max-age` on fingerprinted assets
- `Vary: User-Agent` (cache fragmentation)
- `Vary: Cookie` (cache fragmentation)
- Missing validators (ETag/Last-Modified) on HTML
- `private` on static assets
- Missing CORS on fonts
- Uncached 301 redirects
- CDN not caching static assets

### Info
- Missing `stale-while-revalidate` on HTML
- Missing `stale-if-error` on API
- Missing `s-maxage` behind CDN
- Uncached temporary redirects

---

## Output Example

```
=== HTTP Cache Header Analysis ===
URL: https://example.com
Analyzed: 2024-01-15T10:30:00+00:00

--- Summary ---
Total assets checked: 25
Issues found: 3 (Errors: 0, Warnings: 2, Info: 1)

--- Main Page ---
[HTML] https://example.com
  Cache-Control: max-age=60, stale-while-revalidate=86400
  CDN: Cloudflare (status: HIT)

--- Assets with Issues ---
[JS] [fingerprinted] https://example.com/app.a1b2c3d4.js
  Cache-Control: max-age=3600
  Issues:
    ⚠ Missing immutable directive on fingerprinted asset
      Fingerprinted assets should use immutable to prevent revalidation on reload.

--- Assets OK (24) ---
  ✓ [CSS] https://example.com/style.min.css
  ✓ [JS] https://example.com/vendor.js
  ...
```

---

## Testing

### Test PHP Implementation
```bash
# Via standalone script
php standalone.php https://example.com

# Via WP-CLI
wp cache-check https://example.com
```

### Test Cloudflare Worker
```bash
# Health check
curl https://cache-checker.your-subdomain.workers.dev/health

# Analyze endpoint
curl "https://cache-checker.your-subdomain.workers.dev/analyze?url=example.com"

# Or use the test script
php test-worker.php https://example.com
```

---

## License

GPL v2 or later

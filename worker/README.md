# Cache Checker Cloudflare Worker

Offloads HTTP cache header analysis to Cloudflare's edge network.

## Benefits

- **Distributed IPs**: Requests come from Cloudflare's IP ranges, not your server
- **No origin load**: Your server doesn't perform the HTTP requests
- **Global edge**: Checks run from Cloudflare's nearest edge location
- **Scalable**: Handle many concurrent checks without server strain

## NPM Scripts

Wrangler is installed as a local dependency. Use these npm scripts:

```bash
# First, install dependencies
npm install

# Development
npm run dev              # Start local dev server (hot reload)
npm run dev:remote       # Dev with remote Cloudflare resources

# Deployment
npm run deploy           # Deploy to Cloudflare
npm run deploy:prod      # Deploy to production environment

# Monitoring & Logs
npm run tail             # Stream real-time logs
npm run tail:errors      # Stream only error logs
npm run logs             # Pretty-formatted logs

# Management
npm run list             # List recent deployments
npm run rollback         # Rollback to previous version
npm run delete           # Delete the worker

# Authentication
npm run login            # Login to Cloudflare
npm run whoami           # Check current auth

# Secrets
npm run secret:list      # List secrets
```

## Wrangler CLI Reference

For commands not covered by npm scripts, use `npx wrangler`:

```bash
# Logout
npx wrangler logout

# Set a secret (interactive)
npx wrangler secret put MY_SECRET

# Delete a secret
npx wrangler secret delete MY_SECRET

# View deployment details
npx wrangler deployments view <deployment-id>

# Filter logs by search term
npx wrangler tail --search "error"

# Sampling for high-traffic workers
npx wrangler tail --sampling-rate 0.1

# Get worker route information
npx wrangler route list
```

## Configuration (wrangler.toml)

```toml
name = "cache-checker"
main = "src/index.js"
compatibility_date = "2024-01-01"

# Environment variables
[vars]
MAX_ASSETS = "50"
TIMEOUT_MS = "10000"

# Custom routes (optional)
# routes = [
#   { pattern = "cache-checker.yourdomain.com/*", zone_name = "yourdomain.com" }
# ]

# Production environment overrides
[env.production]
vars = { MAX_ASSETS = "100" }
```

## API Usage

### Analyze a URL

**GET** `/analyze?url=https://example.com`

**POST** `/analyze`
```json
{
  "url": "https://example.com",
  "check_assets": true,
  "max_assets": 50
}
```

### Response

```json
{
  "url": "https://example.com",
  "timestamp": "2024-01-15T10:30:00.000Z",
  "redirect": null,
  "main_page": {
    "url": "https://example.com",
    "status_code": 200,
    "resource_type": "html",
    "headers": { ... },
    "issues": [ ... ]
  },
  "assets": [ ... ],
  "summary": {
    "total_assets": 25,
    "total_issues": 3,
    "issues_by_level": {
      "error": 0,
      "warning": 2,
      "info": 1
    }
  }
}
```

### Health Check

**GET** `/health`

```json
{
  "status": "ok",
  "timestamp": "2024-01-15T10:30:00.000Z"
}
```

## Calling from PHP

```php
use CacheChecker\WorkerClient;

$client = new WorkerClient('https://cache-checker.your-subdomain.workers.dev');

// Check if worker is healthy
if ($client->health_check()) {
    // Analyze a URL
    $result = $client->analyze('https://example.com', true, 50);

    if (!is_wp_error($result)) {
        // Use the result
        $report = new Report($result);
        echo $report->to_text();
    }
}
```

## Quick Start

```bash
# 1. Navigate to worker directory
cd worker

# 2. Install dependencies
npm install

# 3. Login to Cloudflare
npm run login

# 4. Test locally
npm run dev

# 5. Deploy
npm run deploy
```

After deployment, Wrangler outputs your worker URL:
```
Published cache-checker (1.0.0)
  https://cache-checker.your-subdomain.workers.dev
```

Use this URL with the PHP `WorkerClient` class.

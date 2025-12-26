# Cache Checker Cloudflare Worker

Offloads HTTP cache header analysis to Cloudflare's edge network.

## Benefits

- **Distributed IPs**: Requests come from Cloudflare's IP ranges, not your server
- **No origin load**: Your server doesn't perform the HTTP requests
- **Global edge**: Checks run from Cloudflare's nearest edge location
- **Scalable**: Handle many concurrent checks without server strain

## Wrangler CLI

Wrangler is Cloudflare's CLI for managing Workers.

### Installation

```bash
# Install globally via npm
npm install -g wrangler

# Or use npx (no installation needed)
npx wrangler <command>
```

### Authentication

```bash
# Login via browser (OAuth)
wrangler login

# Check who you're authenticated as
wrangler whoami

# Logout
wrangler logout
```

### Project Setup

```bash
# Initialize a new worker project
wrangler init my-worker

# Or clone this and install dependencies
cd worker
npm install
```

### Development

```bash
# Start local dev server (hot reload)
wrangler dev

# Start dev server on specific port
wrangler dev --port 8787

# Dev server with remote resources (uses your Cloudflare account)
wrangler dev --remote
```

### Deployment

```bash
# Deploy to Cloudflare
wrangler deploy

# Deploy to a specific environment
wrangler deploy --env production

# Deploy and get the URL
wrangler deploy 2>&1 | grep "https://"
```

### Monitoring & Logs

```bash
# Stream real-time logs from deployed worker
wrangler tail

# Filter logs by status
wrangler tail --status error

# Filter logs by search term
wrangler tail --search "error"

# Get sampling rate (useful for high-traffic workers)
wrangler tail --sampling-rate 0.1
```

### Managing Deployments

```bash
# List recent deployments
wrangler deployments list

# View deployment details
wrangler deployments view <deployment-id>

# Rollback to previous deployment
wrangler rollback
```

### Secrets & Environment Variables

```bash
# Set a secret (interactive, hidden input)
wrangler secret put MY_SECRET

# Set secret from value
echo "my-value" | wrangler secret put MY_SECRET

# List secrets
wrangler secret list

# Delete a secret
wrangler secret delete MY_SECRET
```

### Worker Information

```bash
# List all your workers
wrangler deployments list

# Delete a worker
wrangler delete

# Get worker route information
wrangler route list
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
npx wrangler login

# 4. Test locally
npx wrangler dev

# 5. Deploy
npx wrangler deploy
```

After deployment, Wrangler outputs your worker URL:
```
Published cache-checker (1.0.0)
  https://cache-checker.your-subdomain.workers.dev
```

Use this URL with the PHP `WorkerClient` class.

# Cache Checker Alfred Workflow

Check HTTP cache headers for any URL directly from Alfred. Self-contained - no PHP or plugin installation required.

## Installation

1. Double-click `Cache Checker.alfredworkflow` to import
2. Configure your Worker URL (see Setup below)

## Setup

After importing:

1. Open Alfred Preferences → Workflows → Cache Checker
2. Click **[x]** in the top-right to open workflow variables
3. Set `worker_url` to your Cache Checker Worker URL
4. Optionally set `api_key` if your worker requires authentication

## Usage

1. Trigger Alfred (⌘ + Space)
2. Type `cache` followed by a URL
3. Press Enter

```
cache example.com
cache wordpress.org
cache https://jonoalderson.com
```

## Output

```
🟡 wordpress.org

Assets: 20
Issues: 0 errors, 13 warnings, 2 info
CDN: Cloudflare (DYNAMIC)

Top Issues:
  ⚠️ Missing ETag or Last-Modified on HTML
  ⚠️ Missing immutable directive on fingerprinted asset
  ℹ️ Behind CDN but missing s-maxage
  ... and 10 more
```

**Status indicators:**
- 🟢 All good - no errors or warnings
- 🟡 Has warnings - review recommended
- 🔴 Has errors - needs attention

## Requirements

- Alfred 5 with Powerpack
- A deployed Cache Checker Cloudflare Worker

## How It Works

The workflow calls your Cloudflare Worker directly via curl. No local PHP or plugin installation needed - just the Worker URL.

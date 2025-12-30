#!/bin/bash
# Cache Checker Alfred Workflow
# Calls Cloudflare Worker and formats output for Alfred Large Type

url="$1"

# Add https:// if no protocol
if [[ ! "$url" =~ ^https?:// ]]; then
    url="https://$url"
fi

# Check worker_url is set
if [[ -z "$worker_url" ]]; then
    echo "Error: worker_url not configured"
    echo ""
    echo "Open Alfred Preferences → Workflows → Cache Checker"
    echo "Click [x] and set worker_url"
    exit 1
fi

# Build curl command
curl_args=(-s -X POST "${worker_url}/analyze")
curl_args+=(-H "Content-Type: application/json")

if [[ -n "$api_key" ]]; then
    curl_args+=(-H "X-API-Key: $api_key")
fi

curl_args+=(-d "{\"url\":\"$url\",\"check_assets\":true,\"max_assets\":20}")

# Call worker
response=$(curl "${curl_args[@]}" 2>/dev/null)

# Check for curl error
if [[ $? -ne 0 ]] || [[ -z "$response" ]]; then
    echo "Error: Could not connect to worker"
    exit 1
fi

# Check for error in response
if echo "$response" | grep -q '"error"[[:space:]]*:'; then
    error=$(echo "$response" | sed -n 's/.*"error"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
    if [[ -n "$error" ]]; then
        echo "Error: $error"
        exit 1
    fi
fi

# Parse JSON - handle pretty-printed format
# Extract from issues_by_level section
total_assets=$(echo "$response" | sed -n 's/.*"total_assets"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' | head -1)
errors=$(echo "$response" | sed -n '/"issues_by_level"/,/}/s/.*"error"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' | head -1)
warnings=$(echo "$response" | sed -n '/"issues_by_level"/,/}/s/.*"warning"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' | head -1)
infos=$(echo "$response" | sed -n '/"issues_by_level"/,/}/s/.*"info"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' | head -1)

# Default to 0 if not found
total_assets=${total_assets:-0}
errors=${errors:-0}
warnings=${warnings:-0}
infos=${infos:-0}

# Status emoji
if [[ $errors -gt 0 ]]; then
    status="🔴"
elif [[ $warnings -gt 0 ]]; then
    status="🟡"
else
    status="🟢"
fi

# Extract domain
domain=$(echo "$url" | sed -E 's|https?://([^/]+).*|\1|')

# Extract CDN info (from main_page.headers.cdn section)
cdn_provider=$(echo "$response" | sed -n 's/.*"provider"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
cdn_status=$(echo "$response" | sed -n 's/.*"cache_status"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)

# Build output
echo "$status $domain"
echo ""
echo "Assets: $total_assets"
echo "Issues: $errors errors, $warnings warnings, $infos info"

if [[ -n "$cdn_provider" ]]; then
    if [[ -n "$cdn_status" ]]; then
        echo "CDN: $cdn_provider ($cdn_status)"
    else
        echo "CDN: $cdn_provider"
    fi
fi

# Extract and display top 5 issues
# Use awk to parse title/level pairs
total_issues=$((errors + warnings + infos))

if [[ $total_issues -gt 0 ]]; then
    echo ""
    echo "Top Issues:"

    echo "$response" | awk '
    /"title"/ {
        gsub(/.*"title"[[:space:]]*:[[:space:]]*"/, "");
        gsub(/".*/, "");
        title = $0
    }
    /"level"/ {
        gsub(/.*"level"[[:space:]]*:[[:space:]]*"/, "");
        gsub(/".*/, "");
        level = $0;
        if (title != "" && count < 5) {
            if (level == "error") icon = "❌"
            else if (level == "warning") icon = "⚠️"
            else icon = "ℹ️"
            print "  " icon " " title
            count++
        }
        title = ""
    }
    '

    if [[ $total_issues -gt 5 ]]; then
        remaining=$((total_issues - 5))
        echo "  ... and $remaining more"
    fi
fi

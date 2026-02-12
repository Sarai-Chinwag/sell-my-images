#!/usr/bin/env bash
#
# Sell My Images - Deploy Smoke Test
#
# Verifies key REST API endpoints are responding correctly.
# Does NOT hit create-checkout (creates real Stripe sessions).
#
# Usage: ./tests/smoke-test.sh https://saraichinwag.com
#

set -uo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

SITE_URL="${1:?Usage: $0 <site-url>}"
SITE_URL="${SITE_URL%/}" # Strip trailing slash

PASS=0
FAIL=0

pass() {
	echo -e "  ${GREEN}✓${NC} $1"
	((PASS++))
}

fail() {
	echo -e "  ${RED}✗${NC} $1"
	((FAIL++))
}

section() {
	echo -e "\n${CYAN}▸ $1${NC}"
}

# ---------------------------------------------------------------------------
section "POST /wp-json/smi/v1/calculate-all-prices"
# ---------------------------------------------------------------------------

# Use a known attachment_id, or override via SMOKE_ATTACHMENT_ID env var.
ATTACHMENT_ID="${SMOKE_ATTACHMENT_ID:-4404}"
POST_ID="${SMOKE_POST_ID:-4403}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST \
	"${SITE_URL}/wp-json/smi/v1/calculate-all-prices" \
	-d "attachment_id=${ATTACHMENT_ID}&post_id=${POST_ID}" 2>/dev/null || true)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [[ "$HTTP_CODE" =~ ^(2|4) ]]; then
	pass "Endpoint reachable (HTTP ${HTTP_CODE})"
else
	fail "HTTP ${HTTP_CODE} (expected 2xx or 4xx)"
fi

if [[ "$HTTP_CODE" =~ ^2 ]]; then
	# Check for expected pricing keys in response body.
	for key in customer_price cost_usd credits; do
		if echo "$BODY" | grep -q "\"${key}\""; then
			pass "Response contains '${key}'"
		else
			fail "Response missing '${key}'"
		fi
	done
else
	echo -e "  ${YELLOW}⚠${NC} Skipping body checks (HTTP ${HTTP_CODE} — may need a valid attachment_id)"
fi

# ---------------------------------------------------------------------------
section "POST /wp-json/smi/v1/track-button-click"
# ---------------------------------------------------------------------------

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST \
	"${SITE_URL}/wp-json/smi/v1/track-button-click" \
	-d "attachment_id=${ATTACHMENT_ID}&post_id=${POST_ID}" 2>/dev/null || true)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [[ "$HTTP_CODE" =~ ^2 ]]; then
	pass "HTTP ${HTTP_CODE}"
else
	fail "HTTP ${HTTP_CODE} (expected 2xx)"
fi

if echo "$BODY" | grep -qi "success\|tracked\|true"; then
	pass "Response indicates success"
else
	fail "Response does not indicate success: ${BODY}"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

TOTAL=$((PASS + FAIL))
echo ""
if [ "$FAIL" -eq 0 ]; then
	echo -e "${GREEN}All ${TOTAL} checks passed.${NC}"
	exit 0
else
	echo -e "${RED}${FAIL}/${TOTAL} checks failed.${NC}"
	exit 1
fi

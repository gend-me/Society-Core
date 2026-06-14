#!/bin/bash
# Phase 29 Plan 05 — live curl UAT for the public booking page + ICS feed.
#
# Run INSIDE the wp-hub pod via:
#   kubectl exec -n wp-hub <pod> -c wordpress -- bash /var/www/html/wp-content/plugins/gend-society/handoff/29-05-uat-curl-live-page.sh
#
# Validates the DEPLOYED surface (cannot run from the Windows dev box — needs the live pod + DB).
# Read-only: discovers a real share_token from the DB and curls the live routes. Emits no data, no mail.

set -u
PASS=0; FAIL=0; SKIP=0
pass() { echo "  PASS  $1"; PASS=$((PASS+1)); }
fail() { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
skip() { echo "  SKIP  $1"; SKIP=$((SKIP+1)); }

WP="wp --allow-root --path=/var/www/html"
HOST_HDR="Host: gend.me"

echo
echo "=== Phase 29 live curl UAT (public booking page + ICS feed) ==="
echo

# Discover an active host with a non-empty 43-char share_token from the DB.
PFX=$($WP eval 'global $wpdb; echo $wpdb->prefix;' 2>/dev/null)
SHARE_TOKEN=$($WP db query \
  "SELECT share_token FROM ${PFX}gs_member_availability WHERE LENGTH(share_token) = 43 LIMIT 1" \
  --skip-column-names 2>/dev/null | head -1 | tr -d '[:space:]')

if [ -z "${SHARE_TOKEN:-}" ] || [ ${#SHARE_TOKEN} -ne 43 ]; then
  skip "No 43-char share_token in DB — seed availability first (PUT /gs/v1/calendar/availability or POST /share-token/rotate)"
  echo
  echo "=== RESULT: $PASS passed, $FAIL failed, $SKIP skipped ==="
  exit 0
fi
echo "  (using share_token ${SHARE_TOKEN:0:8}...)"

# --- Assertion 1: public page returns HTTP 200 + text/html ---
HTTP_INFO=$(curl -sSL -o /tmp/gs_page.html -w "%{http_code}|%{content_type}" "http://localhost/calendar-book/${SHARE_TOKEN}/" -H "$HOST_HDR")
CODE=${HTTP_INFO%%|*}
TYPE=${HTTP_INFO##*|}
if [ "$CODE" = "200" ] && [[ "$TYPE" == text/html* ]]; then
  pass "GET /calendar-book/{token}/ -> 200 text/html"
else
  fail "GET /calendar-book/{token}/ -> $CODE $TYPE (want 200 text/html)"
fi

# --- Assertion 2: page body contains expected scaffolding ---
grep -q "window.gsBookingData" /tmp/gs_page.html && pass "Body contains window.gsBookingData payload"  || fail "Body missing window.gsBookingData"
grep -q "booking-public.js"   /tmp/gs_page.html && pass "Body references booking-public.js asset"     || fail "Body missing booking-public.js"
grep -q "booking-public.css"  /tmp/gs_page.html && pass "Body references booking-public.css asset"    || fail "Body missing booking-public.css"

# --- Assertion 3: ICS subscribable feed returns text/calendar ---
HTTP_INFO=$(curl -sSL -o /tmp/gs_feed.ics -w "%{http_code}|%{content_type}" "http://localhost/wp-json/gs/v1/calendar/ics/${SHARE_TOKEN}" -H "$HOST_HDR")
CODE=${HTTP_INFO%%|*}
TYPE=${HTTP_INFO##*|}
if [ "$CODE" = "200" ] && [[ "$TYPE" == text/calendar* ]]; then
  pass "GET /wp-json/gs/v1/calendar/ics/{token} -> 200 text/calendar"
else
  fail "ICS feed -> $CODE $TYPE (want 200 text/calendar)"
fi

# --- Assertion 4: feed body starts with BEGIN:VCALENDAR and ends with END:VCALENDAR ---
head -1 /tmp/gs_feed.ics | grep -q "BEGIN:VCALENDAR" && pass "ICS feed begins with BEGIN:VCALENDAR" || fail "ICS feed missing BEGIN:VCALENDAR"
grep -q "END:VCALENDAR" /tmp/gs_feed.ics            && pass "ICS feed ends with END:VCALENDAR"     || fail "ICS feed missing END:VCALENDAR"

# --- Assertion 5: feed has VERSION:2.0 + PRODID ---
grep -q "VERSION:2.0" /tmp/gs_feed.ics && pass "ICS feed has VERSION:2.0" || fail "ICS feed missing VERSION:2.0"
grep -q "PRODID:"     /tmp/gs_feed.ics && pass "ICS feed has PRODID"     || fail "ICS feed missing PRODID"

# --- Assertion 6: unknown share_token returns 404 ---
HTTP_INFO=$(curl -sSL -o /dev/null -w "%{http_code}" "http://localhost/wp-json/gs/v1/calendar/ics/notarealtoken12345678901234567890123notreal12" -H "$HOST_HDR")
[ "$HTTP_INFO" = "404" ] && pass "Unknown ICS token -> 404" || fail "Unknown ICS token -> $HTTP_INFO (want 404)"

# --- Assertion 7: public booking REST route registered (logged-out, unknown token -> 404) ---
HTTP_INFO=$(curl -sSL -o /dev/null -w "%{http_code}" "http://localhost/wp-json/gs/v1/calendar/public/badbadbadbadbadbadbadbadbadbadbadbadbadbadba/slots?from=2026-06-20T00:00:00Z&to=2026-06-21T00:00:00Z&duration_min=30" -H "$HOST_HDR")
[ "$HTTP_INFO" = "404" ] && pass "Public /slots unknown token -> 404" || fail "Public /slots unknown token -> $HTTP_INFO (want 404)"

# --- Assertion 8: authed /meetings route returns 401 logged-out ---
HTTP_INFO=$(curl -sSL -o /dev/null -w "%{http_code}" "http://localhost/wp-json/gs/v1/calendar/meetings" -H "$HOST_HDR")
[ "$HTTP_INFO" = "401" ] && pass "Authed /meetings logged-out -> 401" || fail "Authed /meetings logged-out -> $HTTP_INFO (want 401)"

rm -f /tmp/gs_page.html /tmp/gs_feed.ics

echo
echo "=== RESULT: $PASS passed, $FAIL failed, $SKIP skipped ==="
[ $FAIL -eq 0 ] && exit 0 || exit 1

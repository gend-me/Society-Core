# Phase 28 Hub Deploy Runbook

**Owner:** operator (you)
**Scope:** Ships ALL THREE Phase 28 plans (28-01 schema + 28-02 REST + 28-03 settings UI/overlays) to the live wp-hub pod via kubectl cp.
**Time:** ~10-15 min including UATs.
**Acceptance gate:** 5 Phase 28 UATs PASS + recently_activated empty + Phase 27 UATs still green.

## Inheritance

This runbook inherits the 7-step `.no-plugin-sync`-aware structure from `handoff/27-02-deploy-runbook.md`. Read that runbook first if you haven't — Phase 28 follows identical ordering with Phase 28-specific files.

**Critical (Pitfall 1):** kubectl cp NEW class files + assets FIRST, the plugin entrypoint LAST. Image-baked files do NOT propagate to the hub PVC because of the `.no-plugin-sync` sentinel; updating the entrypoint with new require_once lines BEFORE the include files exist on the PVC fatals every request (memory: project_hub_plugin_sync_gotcha).

## Files to deploy

### New files (cp FIRST — order independent within this group)

1. `inc/class-availability-schema.php`     (Plan 28-01)
2. `inc/class-availability-rest.php`       (Plan 28-02 + Plan 28-03 /overlays extension)
3. `assets/availability-settings.js`        (Plan 28-03)
4. `assets/availability-settings.css`       (Plan 28-03)

### Modified files (cp AFTER new files, BEFORE the entrypoint)

5. `inc/calendar-events-rest.php`           (Plan 28-02 — adds get_member_timezone + member_tz envelope key)
6. `inc/member-calendar.php`                (Plan 28-03 — enqueue + mount + data-tz from member tz)
7. `assets/member-calendar.js`              (Plan 28-03 — renderOverlays + listener)

### Plugin entrypoint (cp LAST — every above file MUST already be on the PVC)

8. `gend-society.php`                       (Plan 28-01 + 28-02 entrypoint blocks)

### Handoff scripts (cp AFTER entrypoint — non-blocking)

9. `handoff/28-01-uat-schema-install.php`
10. `handoff/28-01-uat-new-subsite-install.php`
11. `handoff/28-02-uat-availability-rest.php`
12. `handoff/28-02-uat-member-tz-integration.php`
13. `handoff/28-03-uat-overlays-rest.php`
14. `handoff/28-03-uat-dst-overlay-expansion.php`

## Step 1 — Pre-flight

```sh
export KCTL="..." # your kubectl wrapper / cluster context
"$KCTL" config current-context # confirm gend-me cluster
WP_HUB_POD=$( "$KCTL" -n wp-hub get pod -l app=wordpress -o jsonpath='{.items[0].metadata.name}' )
echo "Targeting pod: $WP_HUB_POD"

# Sentinel + PVC vs local inventory
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- ls -la /var/www/html/wp-content/.no-plugin-sync 2>/dev/null
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- ls /var/www/html/wp-content/plugins/gend-society/inc/ > /tmp/pvc-inc.txt
ls build/wp-content-template/plugins/gend-society/inc/ > /tmp/local-inc.txt
diff /tmp/pvc-inc.txt /tmp/local-inc.txt
```

## Step 2 — kubectl cp NEW class files + assets FIRST

```sh
# git-bash on Windows: prefix with MSYS_NO_PATHCONV=1 to avoid /var/www/... mangling.
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/inc/class-availability-schema.php wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/inc/class-availability-schema.php -c wordpress
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/inc/class-availability-rest.php   wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/inc/class-availability-rest.php   -c wordpress
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/assets/availability-settings.js   wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/assets/availability-settings.js   -c wordpress
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/assets/availability-settings.css  wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/assets/availability-settings.css  -c wordpress

"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- chown www-data:www-data \
  /var/www/html/wp-content/plugins/gend-society/inc/class-availability-schema.php \
  /var/www/html/wp-content/plugins/gend-society/inc/class-availability-rest.php \
  /var/www/html/wp-content/plugins/gend-society/assets/availability-settings.js \
  /var/www/html/wp-content/plugins/gend-society/assets/availability-settings.css

# Lint PHP only (CSS/JS validated locally via earlier task verify)
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- php -l /var/www/html/wp-content/plugins/gend-society/inc/class-availability-schema.php
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- php -l /var/www/html/wp-content/plugins/gend-society/inc/class-availability-rest.php
```

## Step 3 — kubectl cp MODIFIED includes + assets (NOT entrypoint yet)

```sh
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/inc/calendar-events-rest.php wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php -c wordpress
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/inc/member-calendar.php       wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/inc/member-calendar.php       -c wordpress
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/assets/member-calendar.js     wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/assets/member-calendar.js     -c wordpress

"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- chown www-data:www-data \
  /var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php \
  /var/www/html/wp-content/plugins/gend-society/inc/member-calendar.php \
  /var/www/html/wp-content/plugins/gend-society/assets/member-calendar.js
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- php -l /var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- php -l /var/www/html/wp-content/plugins/gend-society/inc/member-calendar.php
```

## Step 4 — kubectl cp gend-society.php LAST

```sh
MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/gend-society.php wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/gend-society.php -c wordpress
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- chown www-data:www-data /var/www/html/wp-content/plugins/gend-society/gend-society.php
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- php -l /var/www/html/wp-content/plugins/gend-society/gend-society.php
```

## Step 5 — kubectl cp handoff UAT scripts

```sh
for f in 28-01-uat-schema-install.php 28-01-uat-new-subsite-install.php 28-02-uat-availability-rest.php 28-02-uat-member-tz-integration.php 28-03-uat-overlays-rest.php 28-03-uat-dst-overlay-expansion.php; do
  MSYS_NO_PATHCONV=1 "$KCTL" cp build/wp-content-template/plugins/gend-society/handoff/$f wp-hub/$WP_HUB_POD:/var/www/html/wp-content/plugins/gend-society/handoff/$f -c wordpress
done
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- chown -R www-data:www-data /var/www/html/wp-content/plugins/gend-society/handoff/
```

## Step 6 — HTTP smoke + activation guard

```sh
# Homepage must NOT 500
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- curl -s -o /dev/null -w "%{http_code}\n" -H "Host: gend.me" http://localhost/
# Calendar REST must return 401 logged-out (NOT 404 — 404 = registration failed)
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- curl -s -o /dev/null -w "%{http_code}\n" -H "Host: gend.me" "http://localhost/wp-json/gs/v1/calendar/availability"
# Pitfall 2: recently_activated MUST be empty (gend-society must NOT appear)
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp option get recently_activated --path=/var/www/html --allow-root
# gend-society MUST be active network-wide
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp plugin list --network --status=active --path=/var/www/html --allow-root | grep gend-society
```

## Step 7 — Run 5 Phase 28 UATs + 3 Phase 27 regression UATs (ZERO FAIL acceptance gate)

```sh
# Plan 28-01
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-01-uat-schema-install.php --path=/var/www/html --allow-root
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-01-uat-new-subsite-install.php --path=/var/www/html --allow-root

# Plan 28-02
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-02-uat-availability-rest.php --path=/var/www/html --allow-root
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-02-uat-member-tz-integration.php --path=/var/www/html --allow-root

# Plan 28-03
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-03-uat-overlays-rest.php --path=/var/www/html --allow-root
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/28-03-uat-dst-overlay-expansion.php --path=/var/www/html --allow-root

# Phase 27 regression — must still PASS
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/27-02-uat-bm-adapter.php --path=/var/www/html --allow-root
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/27-02-uat-cross-member-leak.php --path=/var/www/html --allow-root
"$KCTL" -n wp-hub exec "$WP_HUB_POD" -c wordpress -- wp eval-file /var/www/html/wp-content/plugins/gend-society/handoff/27-02-uat-dst-boundary.php --path=/var/www/html --allow-root
```

## FAIL-mode triage

| UAT | Common cause | Fix |
|-----|--------------|-----|
| 28-01 schema-install | dbDelta ENUM whitespace; PVC missing class file | Step 2 cp order; re-cp + retry |
| 28-01 new-subsite | wp_initialize_site hook not wired | grep gend-society.php for Gend_GS_Availability_Schema::init() call |
| 28-02 availability-rest | route 404 = registration failed | Step 4 (gend-society.php has require + rest_api_init hook) |
| 28-02 member-tz | cache key mismatch | Verify both classes use `tz_cache_key($user_id)` |
| 28-03 overlays | DST drift = strtotime+offset bug | grep `DateTime::createFromFormat` (must exist) |
| 28-03 DST | EST Mon at 13:00 UTC = naive bug | Re-cp class-availability-rest.php; check for typo |
| 27-02 regression | aggregator envelope key removed | Verify `sources_available` + `events` keys preserved |

## Rollback

If any UAT FAILs and root cause is non-trivial:

1. Re-cp the ORIGINAL `gend-society.php` from git: `git show HEAD~1:build/wp-content-template/plugins/gend-society/gend-society.php > /tmp/orig.php` then cp to PVC.
2. Or: remove the new files from PVC; the file_exists() guards in `gend-society.php` will silently skip.
3. `wp plugin activate --network gend-society --allow-root` to restore activation if Pitfall 2 fired.

## Post-deploy

- [ ] All 5 Phase 28 UATs PASS (zero FAIL)
- [ ] All 3 Phase 27 regression UATs PASS
- [ ] `wp option get recently_activated` does NOT list gend-society
- [ ] `wp plugin list --network --status=active | grep gend-society` returns 1 row
- [ ] Homepage HTTP 200
- [ ] /wp-json/gs/v1/calendar/availability returns 401 logged-out (NOT 404)
- [ ] Calendar tab loads in browser; settings panel visible
- [ ] Save tz + working hours; overlays appear on Week/Day grid; member_tz applied

After all green: type `approved` to the orchestrator to complete the Plan 28-03 checkpoint and close Phase 28.

---
*Phase 28 deploy runbook — inherits from 27-02-deploy-runbook.md*

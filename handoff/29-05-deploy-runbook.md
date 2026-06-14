# Phase 29 — Canonical Hub Deploy Runbook (Sharing + Booking + Meeting Types + Notifications)

**Plan:** 29-05
**What this deploys:** the full Phase 29 booking surface into the live `wp-hub` pod on the production gend.me hub —
5 NEW classes + 3 MODIFIED includes + 4 NEW assets + 11 NEW handoff scripts + the updated `gend-society.php` entrypoint.
**Prerequisite:** Plan 28-03 deploy is already green (schema tables `wp_gs_member_availability` + `wp_gs_member_meetings` installed by Plan 28-01; share_token member-stable from Plan 28-02; overlays REST live).
**Inherits from:** `handoff/27-02-deploy-runbook.md` + `handoff/28-03-deploy-runbook.md` (the same 7-step .no-plugin-sync-aware canonical structure). This runbook extends those with the Phase 29 file list + the 9 Phase 29 UATs + the 8 Phase 27/28 regression UATs + the live curl public-page/ICS probe + the **schema-column backfill** (Pitfall: `booking_settings_json` + `guest_phone` columns are referenced by Plans 29-01/02/03 but were NOT installed by Plan 28-01).

> **LOAD-BEARING ORDER (Pitfall 1 + `project_hub_plugin_sync_gotcha`):** the hub PVC carries a `.no-plugin-sync` sentinel, so image-baked files do NOT auto-propagate. You MUST `kubectl cp` every NEW class file to the PVC **FIRST**, verify them live, and only **THEN** `kubectl cp` the modified includes and finally the `gend-society.php` entrypoint **LAST**. If the entrypoint reaches the PVC before any new class file, every request fatals at `require_once` (the gend.me 500 incident on 2026-06-13 with contracts-and-payments).

> **Windows git-bash note:** prefix every `kubectl cp` / `kubectl exec` path command with `MSYS_NO_PATHCONV=1` to stop MSYS from mangling the `/var/www/...` container paths into `C:\...`.

---

## Section 1 — Pre-flight

```bash
# Cluster context + KCTL alias + pod resolution (resolve the CURRENT pod — never assume an old name)
KCTL=kubectl
CTX=gke_gend-me_us-central1_gend-prod
NS=wp-hub
$KCTL config use-context $CTX
POD=$($KCTL get pod -n $NS -l app=wordpress -o jsonpath='{.items[0].metadata.name}')
echo "Pod: $POD"
PLUG=/var/www/html/wp-content/plugins/gend-society

# Sentinel verification — .no-plugin-sync MUST exist (else auto-sync would already have copied files)
$KCTL exec -n $NS $POD -c wordpress -- ls /var/www/html/wp-content/.no-plugin-sync

# Inventory diff — files on PVC vs local
$KCTL exec -n $NS $POD -c wordpress -- ls $PLUG/inc/ | sort > /tmp/pvc.txt
ls build/wp-content-template/plugins/gend-society/inc/ | sort > /tmp/local.txt
diff /tmp/pvc.txt /tmp/local.txt || echo "(diff expected — new class files not yet on PVC)"
```

Expected: the 5 new `class-booking-*.php` files appear in `/tmp/local.txt` but NOT yet in `/tmp/pvc.txt`.

---

## Section 2 — cp NEW class files FIRST (Pitfall 1 — LOAD-BEARING ORDER)

```bash
# Windows git-bash: MSYS_NO_PATHCONV=1 prefix prevents path mangling
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/class-booking-public-rest.php    $NS/$POD:$PLUG/inc/class-booking-public-rest.php -c wordpress
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/class-booking-meetings-rest.php  $NS/$POD:$PLUG/inc/class-booking-meetings-rest.php -c wordpress
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/class-booking-notifications.php  $NS/$POD:$PLUG/inc/class-booking-notifications.php -c wordpress
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/class-booking-ics.php            $NS/$POD:$PLUG/inc/class-booking-ics.php -c wordpress
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/class-booking-public-page.php    $NS/$POD:$PLUG/inc/class-booking-public-page.php -c wordpress

# chown + php -l per NEW class file — verify on the PVC (NOT the local repo — submodule rsync addendum)
for f in class-booking-public-rest class-booking-meetings-rest class-booking-notifications class-booking-ics class-booking-public-page; do
  $KCTL exec -n $NS $POD -c wordpress -- chown www-data:www-data $PLUG/inc/${f}.php
  $KCTL exec -n $NS $POD -c wordpress -- php -l $PLUG/inc/${f}.php
done

# VERIFY the 5 new classes are live on the PVC before continuing
$KCTL exec -n $NS $POD -c wordpress -- ls -la $PLUG/inc/ | grep class-booking-
$KCTL exec -n $NS $POD -c wordpress -- grep -l "class Gend_GS_Booking_Public_REST"    $PLUG/inc/class-booking-public-rest.php
$KCTL exec -n $NS $POD -c wordpress -- grep -l "class Gend_GS_Booking_Meetings_REST"  $PLUG/inc/class-booking-meetings-rest.php
$KCTL exec -n $NS $POD -c wordpress -- grep -l "class Gend_GS_Booking_Notifications"  $PLUG/inc/class-booking-notifications.php
$KCTL exec -n $NS $POD -c wordpress -- grep -l "class Gend_GS_Booking_ICS"            $PLUG/inc/class-booking-ics.php
$KCTL exec -n $NS $POD -c wordpress -- grep -l "class Gend_GS_Booking_Public_Page"    $PLUG/inc/class-booking-public-page.php
```

All 5 `php -l` outputs must read `No syntax errors detected`. STOP if any fails (see Section 12 triage).

---

## Section 3 — cp MODIFIED include files

These existed before Phase 29 but were edited. cp AFTER the new classes, BEFORE the entrypoint.

```bash
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/calendar-events-rest.php    $NS/$POD:$PLUG/inc/calendar-events-rest.php -c wordpress
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/class-availability-rest.php $NS/$POD:$PLUG/inc/class-availability-rest.php -c wordpress
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/inc/member-calendar.php         $NS/$POD:$PLUG/inc/member-calendar.php -c wordpress

for f in calendar-events-rest class-availability-rest member-calendar; do
  $KCTL exec -n $NS $POD -c wordpress -- chown www-data:www-data $PLUG/inc/${f}.php
  $KCTL exec -n $NS $POD -c wordpress -- php -l $PLUG/inc/${f}.php
done
```

- `calendar-events-rest.php` — Plan 29-01 Task 2 Meetings adapter rewrite (was Plan 27-01 stub).
- `class-availability-rest.php` — Plan 29-02 Task 2 `booking_settings_json` accept/persist + URL allowlist funnel.
- `member-calendar.php` — Plan 29-02 enqueue of `schedule-meeting.{js,css}` + `SHOW COLUMNS` guard + `wp_localize_script gsScheduleData`.

---

## Section 4 — cp NEW assets

```bash
for f in schedule-meeting.js schedule-meeting.css booking-public.js booking-public.css; do
  MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/assets/$f $NS/$POD:$PLUG/assets/$f -c wordpress
  $KCTL exec -n $NS $POD -c wordpress -- chown www-data:www-data $PLUG/assets/$f
done

# verify assets live
$KCTL exec -n $NS $POD -c wordpress -- ls -la $PLUG/assets/ | grep -E 'schedule-meeting|booking-public'
```

---

## Section 5 — cp NEW handoff scripts (UATs + this runbook for reference)

```bash
for f in 29-01-uat-public-booking-rest.php 29-01-uat-atomic-booking-race.php 29-01-uat-honeypot-ratelimit.php \
         29-02-uat-meetings-rest.php 29-02-uat-url-allowlist.php \
         29-03-uat-notifications.php \
         29-04-uat-ics-rfc5545.php 29-04-uat-public-page.php \
         29-05-uat-end-to-end.php 29-05-uat-curl-live-page.sh 29-05-deploy-runbook.md; do
  MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/handoff/$f $NS/$POD:$PLUG/handoff/$f -c wordpress
done
$KCTL exec -n $NS $POD -c wordpress -- chown -R www-data:www-data $PLUG/handoff/

# php -l on every .php handoff script
for f in 29-01-uat-public-booking-rest 29-01-uat-atomic-booking-race 29-01-uat-honeypot-ratelimit \
         29-02-uat-meetings-rest 29-02-uat-url-allowlist 29-03-uat-notifications \
         29-04-uat-ics-rfc5545 29-04-uat-public-page 29-05-uat-end-to-end; do
  $KCTL exec -n $NS $POD -c wordpress -- php -l $PLUG/handoff/${f}.php
done
```

---

## Section 5.5 — Schema-column backfill (REQUIRED — Pitfall: 29-01/02/03 assume two columns Plan 28-01 never installed)

Plan 29-01 `get_booking_settings`, Plan 29-02 `sanitize_booking_settings`, and Plans 29-01/02 booking INSERTs reference
`wp_gs_member_availability.booking_settings_json` and `wp_gs_member_meetings.guest_phone`. The read paths degrade
gracefully (`SHOW COLUMNS` / `array_key_exists` guards), but to make the full booking flow + Schedule Meeting modal
durations work, backfill both columns network-wide BEFORE the entrypoint goes live.

```bash
# Add booking_settings_json (availability) + guest_phone (meetings) on EVERY blog in the network.
$KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html site list --field=url | while read -r SITE; do
  PFX=$($KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html --url="$SITE" eval 'global $wpdb; echo $wpdb->prefix;')
  # availability.booking_settings_json LONGTEXT NULL — idempotent (IF NOT EXISTS on MySQL 8 / MariaDB 10.0.2+)
  $KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html --url="$SITE" db query \
    "ALTER TABLE ${PFX}gs_member_availability ADD COLUMN IF NOT EXISTS booking_settings_json LONGTEXT NULL" 2>/dev/null || echo "  $SITE availability column present/skip"
  # meetings.guest_phone VARCHAR(40) NULL — idempotent
  $KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html --url="$SITE" db query \
    "ALTER TABLE ${PFX}gs_member_meetings ADD COLUMN IF NOT EXISTS guest_phone VARCHAR(40) NULL" 2>/dev/null || echo "  $SITE meetings column present/skip"
done
```

> If the MySQL/MariaDB version doesn't support `ADD COLUMN IF NOT EXISTS`, guard each ALTER with a `SHOW COLUMNS LIKE` check first; a duplicate-column error is harmless and means the column is already present.

Verify on the hub blog the seed UAT runs against:

```bash
$KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html db query \
  "SHOW COLUMNS FROM $(wp_pfx)gs_member_availability LIKE 'booking_settings_json'"
$KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html db query \
  "SHOW COLUMNS FROM $(wp_pfx)gs_member_meetings LIKE 'guest_phone'"
```

---

## Section 6 — cp `gend-society.php` ENTRYPOINT LAST (Pitfall 1)

Only now that all 5 new classes + 3 modified includes + 4 assets are verified live on the PVC do we land the entrypoint
that `require_once`s them (4 new Phase 29 bootstrap blocks: 29-01 public REST + 29-02 meetings REST + 29-03 notifications + 29-04 ICS + public page).

```bash
MSYS_NO_PATHCONV=1 $KCTL cp build/wp-content-template/plugins/gend-society/gend-society.php $NS/$POD:$PLUG/gend-society.php -c wordpress
$KCTL exec -n $NS $POD -c wordpress -- chown www-data:www-data $PLUG/gend-society.php
$KCTL exec -n $NS $POD -c wordpress -- php -l $PLUG/gend-society.php

# One opcache flush MAX (hub cold-start probe-timeout discipline — do NOT loop this)
$KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html eval 'opcache_reset();'
```

---

## Section 7 — HTTP smoke

```bash
# Homepage — expect 200 or 301 (HTTP→HTTPS redirect)
$KCTL exec -n $NS $POD -c wordpress -- curl -sSL -o /dev/null -w "%{http_code}\n" http://localhost/ -H 'Host: gend.me'

# Public booking slots route registered (logged-out, unknown 43-char token → 404)
$KCTL exec -n $NS $POD -c wordpress -- curl -sSL -o /dev/null -w "%{http_code}\n" "http://localhost/wp-json/gs/v1/calendar/public/badbadbadbadbadbadbadbadbadbadbadbadbadbadba/slots" -H 'Host: gend.me'   # expect 404

# Authed meetings route registered (logged-out → 401)
$KCTL exec -n $NS $POD -c wordpress -- curl -sSL -o /dev/null -w "%{http_code}\n" "http://localhost/wp-json/gs/v1/calendar/meetings" -H 'Host: gend.me'   # expect 401
```

---

## Section 8 — Run Phase 29 UATs (acceptance gate — 9 UATs)

Each UAT runs via `wp eval-file` inside the pod (full form: `wp --allow-root --path=/var/www/html eval-file <path>`):

```bash
for f in 29-01-uat-public-booking-rest 29-01-uat-atomic-booking-race 29-01-uat-honeypot-ratelimit \
         29-02-uat-meetings-rest 29-02-uat-url-allowlist 29-03-uat-notifications \
         29-04-uat-ics-rfc5545 29-04-uat-public-page 29-05-uat-end-to-end; do
  echo "=== $f ==="
  $KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html eval-file $PLUG/handoff/${f}.php
done
```

Acceptance gate: ALL 9 print `=== RESULT: N passed, 0 failed, K skipped ===` with ZERO `FAIL` lines.

| # | UAT | Covers |
|---|-----|--------|
| 1 | 29-01-uat-public-booking-rest | BOOK-01..06 + MEET-05 public flow |
| 2 | 29-01-uat-atomic-booking-race | BOOK-04 atomic FOR UPDATE 409 |
| 3 | 29-01-uat-honeypot-ratelimit | BOOK-09 honeypot + rate limit |
| 4 | 29-02-uat-meetings-rest | BOOK-07/08/10 + MEET-01/02/04 authed + 401/403 |
| 5 | 29-02-uat-url-allowlist | MEET-03 URL allowlist matrix (Pitfall 16) |
| 6 | 29-03-uat-notifications | NOTIF-01/02 emails + reminder cron |
| 7 | 29-04-uat-ics-rfc5545 | NOTIF-03/04 RFC 5545 ICS |
| 8 | 29-04-uat-public-page | public booking page wiring |
| 9 | 29-05-uat-end-to-end | full lifecycle: slots→book→email→cron→aggregator→cancel |

---

## Section 9 — Phase 27 + 28 regression UATs (8 UATs)

Same `wp eval-file` driver as Section 8, pointed at the Phase 27/28 handoff probes:

```bash
for f in 27-01-uat-rest-contract 27-02-uat-bm-adapter 27-02-uat-cross-member-leak 27-02-uat-dst-boundary \
         28-01-uat-schema-install 28-02-uat-availability-rest 28-02-uat-member-tz-integration 28-03-uat-overlays-rest; do
  echo "=== regression $f ==="
  $KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html eval-file $PLUG/handoff/${f}.php
done
```

All 8 must still print PASS (SKIP-as-PASS allowed for `27-02-uat-bm-adapter` per its design). NO new regressions introduced by Phase 29.

---

## Section 10 — Live curl-live-page UAT

```bash
$KCTL exec -n $NS $POD -c wordpress -- bash $PLUG/handoff/29-05-uat-curl-live-page.sh
```

Acceptance: `=== RESULT: N passed, 0 failed, K skipped ===`. (SKIP only if no `share_token` exists yet — seed one first via
`PUT /gs/v1/calendar/availability` with a token, or rotate via `POST /gs/v1/calendar/share-token/rotate`.)

This validates against the deployed pod:
- `GET /calendar-book/{token}/` → 200 `text/html` + body contains `window.gsBookingData` + references `booking-public.js`/`booking-public.css`
- `GET /wp-json/gs/v1/calendar/ics/{token}` → 200 `text/calendar` + body `BEGIN:VCALENDAR`…`END:VCALENDAR` + `VERSION:2.0` + `PRODID:`
- unknown ICS token → 404; public `/slots` unknown token → 404; authed `/meetings` logged-out → 401

---

## Section 11 — Post-deploy

```bash
# Pitfall 2 — gend-society MUST NOT appear in recently_activated (no auto-deactivation on fatal)
$KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html option get recently_activated
#   expect empty array or OTHER plugins — NEVER gend-society. If gend-society is listed:
#   $KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html plugin activate --network gend-society
#   then investigate the fatal in the logs and DO NOT proceed.

# Plugin must be active network-wide
$KCTL exec -n $NS $POD -c wordpress -- wp --allow-root --path=/var/www/html plugin list --name=gend-society
#   expect status=active-network

# Log grep clean of fatals related to our surface
$KCTL logs -n $NS $POD -c wordpress --since=2m | grep -E 'Fatal|Failed opening' | grep -i 'gend-society\|booking\|calendar' || echo "LOG_CLEAN"
```

---

## Section 12 — FAIL-mode triage table

| Failure mode | Likely cause | Action |
|--------------|--------------|--------|
| `php -l` fails on a new class (Section 2) | corrupt cp / partial transfer | re-cp the single file; re-lint; do NOT cp the entrypoint until clean |
| HTTP 500 on homepage after Section 6 | entrypoint `require_once` of a class not on PVC (Pitfall 1 order violated) | re-run Section 2 (cp + verify ALL 5 classes), re-cp entrypoint LAST, `opcache_reset` once |
| `recently_activated` lists gend-society | a Phase 29 file fataled during an admin request (Pitfall 2 auto-deactivation) | `wp plugin activate --network gend-society`; grep logs; fix file; re-cp; do NOT proceed |
| UAT 29-02 booking_settings round-trip FAIL | `booking_settings_json` column missing | run Section 5.5 backfill; re-run UAT |
| UAT 29-05 end-to-end guest_phone FAIL | `guest_phone` column missing | run Section 5.5 backfill; re-run UAT |
| curl `/calendar-book/{token}/` → 404 | `template_redirect` handler not registered OR token unknown | confirm `class-booking-public-page.php` live + entrypoint block present; confirm a valid 43-char token exists in DB |
| ICS feed → wrong Content-Type | `emit_and_exit` output-buffer teardown not reached | re-cp `class-booking-ics.php`; `opcache_reset` once; re-curl |
| `/slots` unknown token → 500 not 404 | Meetings adapter or compute_open_slots fatal | grep logs for the throwable; this is a Pitfall 2 wrap miss — investigate before proceeding |

---

## Section 13 — Rollback options

1. **Revert a single file from git:** `kubectl cp` the prior submodule HEAD's version of the offending file back to the PVC, `opcache_reset` once.
2. **Disable an include via guard:** comment out the offending `require_once` line in `gend-society.php` and re-cp it. Each Phase 29 bootstrap block is a self-contained `if ( file_exists( ... ) ) { ... }` — removing one leaves the other class files orphaned but harmless.
3. **Deactivate the whole plugin (last resort):** `wp plugin deactivate --network gend-society` — kills the entire plugin including Phase 26 calendar + Phase 27/28 surfaces. Only if the hub is actively 500ing and cannot be triaged inline.

---

## Section 14 — Phase 30 inheritance notes

This 7-step structure (pre-flight → cp NEW classes FIRST → cp MODIFIED → cp ASSETS → cp HANDOFF → cp ENTRYPOINT LAST → smoke → UATs → regression → live curl → post-deploy → triage → rollback) is **canonical for Phase 30 (Native gend Video — Jitsi on GKE)**:

- Phase 30 will add NEW class files (the JWT minter bound to `meeting_meta_json.jitsi_room`, allocated by Plan 29-02's `generate_jitsi_room`) + a modified entrypoint — same `cp-NEW-FIRST` / `entrypoint-LAST` discipline applies.
- Phase 30 also ships its OWN infra deployment (a Helm chart for jitsi-meet: JVB UDP/L4 LB + advertise-IP + coturn + prosody/jicofo token config + iframe CSP) which is OUT of scope for this WordPress-plugin runbook structure — that's a different cluster surface and gets its own infra runbook (flagged for deeper research per ROADMAP §"v7.0 Per-Phase Research Flags").
- The ICS `meet.gend.me/{jitsi_room}` LOCATION URLs already emitted for `provider='gend'` meetings remain stable; Phase 30 only adds JWT minting at join time, so external calendars don't need to re-render.

---

*Phase: 29-sharing-booking-meeting-types-notifications · Plan 29-05 · canonical hub deploy runbook*

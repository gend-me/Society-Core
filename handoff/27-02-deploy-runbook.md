# Phase 27 Hub Deploy Runbook

**Memory reference:** `[[project_hub_plugin_sync_gotcha]]` — gend.me wp-hub PVC has a `.no-plugin-sync` sentinel; the plugin-sync init container exits 0 immediately, so new include files MUST be `kubectl cp`'d explicitly to the live PVC **BEFORE** the entrypoint references them, or every request fatals on `require_once: Failed to open stream: No such file or directory` and gend.me returns HTTP 500. This already happened with C&P Phase 22 (2026-06-13) and again on a near-miss caught by the runbook in Phase 26 — the cp ORDER below is load-bearing.

**Inheritance:** This runbook canonicalizes the Phase 26 Plan 03 deploy pattern (memory: Plan 26-03 SUMMARY) and applies it to Phase 27. Phases 28-30 will inherit verbatim.

---

## Pre-flight (always)

```bash
# 1) Confirm correct cluster
kubectl config current-context
# expect: gke_gend-me_*

# 2) Confirm wp-hub pod is Running
kubectl get pod -n wp-hub -l app=wordpress -o wide
# expect: at least 1 pod in Running state

# 3) Resolve the pod name once (reuse below)
POD=$(kubectl get pod -n wp-hub -l app=wordpress -o jsonpath='{.items[0].metadata.name}')
echo "POD=$POD"

# 4) Confirm sentinel still present
kubectl exec -n wp-hub "$POD" -c wordpress -- ls -la /var/www/html/wp-content/.no-plugin-sync
# expect: file exists (memory: hub plugin-sync gotcha)

# 5) Inventory diff: PVC vs local gend-society
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  ls /var/www/html/wp-content/plugins/gend-society/inc/ > /tmp/pvc-inc.txt
ls build/wp-content-template/plugins/gend-society/inc/ > /tmp/local-inc.txt
diff /tmp/pvc-inc.txt /tmp/local-inc.txt
# expect: no NEW file delta if Phase 26-03 + Plan 27-01 already deployed;
#         if calendar-events-rest.php is "Only in local" — Step 1 below is the NEW-file deploy.
```

**Windows / git-bash note:** prefix every `kubectl cp` with `MSYS_NO_PATHCONV=1` (memory: C&P live deploy). Example:
```bash
MSYS_NO_PATHCONV=1 kubectl cp <src> wp-hub/$POD:<dst> -c wordpress
```

---

## Step 1 — cp the NEW include file FIRST (NEVER skip)

The Plan 27-02 commit adds the `Gend_GS_Calendar_Source_BlogManager` class to the **existing** `inc/calendar-events-rest.php` (it was created in Plan 27-01). So no NEW file is introduced by Plan 27-02 — but the **existing** file's class roster changed, so the file MUST be cp'd before the entrypoint is touched.

```bash
MSYS_NO_PATHCONV=1 kubectl cp \
  build/wp-content-template/plugins/gend-society/inc/calendar-events-rest.php \
  wp-hub/$POD:/var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php \
  -c wordpress

# Chown + verify
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  chown www-data:www-data /var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php

kubectl exec -n wp-hub "$POD" -c wordpress -- \
  php -l /var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php
# expect: "No syntax errors detected"
```

---

## Step 2 — cp the updated entrypoint LAST (only if changed)

Plan 27-02 does NOT modify `gend-society.php` — the bootstrap block from Plan 27-01 (lines 80-82) already wires `Gend_GS_Calendar_Events_REST::register_routes` on `rest_api_init`, and the BlogManager class is loaded by `inc/calendar-events-rest.php` itself. Skip Step 2 unless the inventory diff in Pre-flight shows `gend-society.php` differs.

If it DOES differ, cp the entrypoint LAST (after Step 1's include cp):
```bash
# Single-line form for run-it-as-is:
MSYS_NO_PATHCONV=1 kubectl cp build/wp-content-template/plugins/gend-society/gend-society.php wp-hub/$POD:/var/www/html/wp-content/plugins/gend-society/gend-society.php -c wordpress

kubectl exec -n wp-hub "$POD" -c wordpress -- \
  chown www-data:www-data /var/www/html/wp-content/plugins/gend-society/gend-society.php

kubectl exec -n wp-hub "$POD" -c wordpress -- \
  php -l /var/www/html/wp-content/plugins/gend-society/gend-society.php
# expect: "No syntax errors detected"
```

---

## Step 3 — cp ALL handoff scripts (probe + 27-01 UATs + 27-02 UATs)

The acceptance gate runs 9 scripts on the hub: 1 storage-truth probe + 3 Plan 27-01 UATs + 5 Plan 27-02 UATs. Cp them all up.

```bash
HANDOFF_DST=/var/www/html/wp-content/plugins/gend-society/handoff
HANDOFF_SRC=build/wp-content-template/plugins/gend-society/handoff

# Ensure handoff/ dir exists on PVC (gend-society may not have shipped one yet)
kubectl exec -n wp-hub "$POD" -c wordpress -- mkdir -p "$HANDOFF_DST"

for f in \
  27-storage-tz-probe.php \
  27-01-uat-rest-contract.php \
  27-01-uat-projects-adapter.php \
  27-01-uat-graceful-degradation.php \
  27-02-uat-bm-adapter.php \
  27-02-uat-cross-member-leak.php \
  27-02-uat-dst-boundary.php \
  27-02-uat-n1-budget.php \
  27-02-uat-perf-21day-window.php
do
  MSYS_NO_PATHCONV=1 kubectl cp "$HANDOFF_SRC/$f" "wp-hub/$POD:$HANDOFF_DST/$f" -c wordpress
done

kubectl exec -n wp-hub "$POD" -c wordpress -- \
  chown -R www-data:www-data "$HANDOFF_DST"

# php -l every UAT (catch typos before runtime)
for f in 27-storage-tz-probe 27-01-uat-rest-contract 27-01-uat-projects-adapter \
         27-01-uat-graceful-degradation 27-02-uat-bm-adapter 27-02-uat-cross-member-leak \
         27-02-uat-dst-boundary 27-02-uat-n1-budget 27-02-uat-perf-21day-window; do
  echo "--- $f ---"
  kubectl exec -n wp-hub "$POD" -c wordpress -- php -l "$HANDOFF_DST/$f.php"
done
# expect: 9 × "No syntax errors detected"
```

---

## Step 4 — Smoke test HTTP 200 on hub

```bash
# Homepage parses (catches require_once fatals immediately)
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  curl -sS -o /dev/null -w "homepage=%{http_code}\n" \
       http://localhost/ -H 'Host: gend.me'
# expect: homepage=200 (or 301 → HTTPS redirect, also acceptable)

# REST route registered (logged-out: 401 = route exists, 404 = route MISSING)
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  curl -sS -o /dev/null -w "rest=%{http_code}\n" \
       "http://localhost/wp-json/gs/v1/calendar/events?from=2026-06-01T00:00:00Z&to=2026-06-30T00:00:00Z" \
       -H 'Host: gend.me'
# expect: rest=401 (logged-out) — 404 means register_rest_route didn't fire (FATAL: investigate)
```

If homepage returns 500, IMMEDIATELY:
```bash
kubectl logs -n wp-hub deploy/wordpress -c wordpress --tail=80 \
  | grep -E 'Fatal|Failed opening'
```
Most likely cause: a class file referenced in `gend-society.php` was not cp'd in Step 1. Rollback per Step 7 then re-execute Steps 1-2 in order.

---

## Step 5 — Run the storage-truth probe FIRST

Confirm the RESEARCH-locked storage-tz assumptions still hold on the live hub before trusting the UAT results below.

```bash
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  wp eval-file "$HANDOFF_DST/27-storage-tz-probe.php" --allow-root --path=/var/www/html
```

**Expected output:**
- `MySQL @@session.time_zone` is `+00:00` (or UTC) — if it's `-05:00` or similar, hub clock is misconfigured (memory: oauth timezone bug).
- Sample `pm_tasks.due_date` rows show `YYYY-MM-DD 00:00:00` shape (date-only naive — Pitfall A).
- Sample `bm_social_schedule_v2.scheduled_at` rows show plausible UTC times (compare to wall-clock: if rows are clustered around server-local times rather than UTC, RESEARCH §Timezone Normalization is invalidated — HALT and re-research).

**HALT if storage-truth differs from RESEARCH.** Do NOT proceed to Step 6.

---

## Step 6 — Run all 8 UATs

```bash
# Plan 27-01 UATs (re-run to confirm bm registration didn't regress 27-01)
for f in 27-01-uat-rest-contract 27-01-uat-projects-adapter 27-01-uat-graceful-degradation; do
  echo "=== $f ==="
  kubectl exec -n wp-hub "$POD" -c wordpress -- \
    wp eval-file "$HANDOFF_DST/$f.php" --allow-root --path=/var/www/html
done

# Plan 27-02 UATs (the new acceptance gate)
for f in 27-02-uat-bm-adapter 27-02-uat-cross-member-leak 27-02-uat-dst-boundary \
         27-02-uat-n1-budget 27-02-uat-perf-21day-window; do
  echo "=== $f ==="
  kubectl exec -n wp-hub "$POD" -c wordpress -- \
    wp eval-file "$HANDOFF_DST/$f.php" --allow-root --path=/var/www/html
done
```

**Acceptance:** ZERO `FAIL` lines across all 8 UATs. `SKIP` lines are acceptable (typically when no seed data exists for a particular probe; document each SKIP in the Plan 27-02 SUMMARY).

**Common FAIL modes + remediation:**

| FAIL signal | Root cause | Fix |
|-------------|-----------|-----|
| `data_count > 6` | Projects adapter Q2 split into per-row queries | Refactor to IN(...) batch or UNION ALL per RESEARCH §N+1 fallback |
| `?user=B param NOT ignored` | route_events() reading `$req->get_param('user')` | Verify Plan 27-01 Task 1 — must use `get_current_user_id()` only |
| `bm probe start='...' (expected '2026-11-01T06:00:00Z')` | utc_to_z() doing strtotime+gmdate (Pitfall 5) | Confirm direct string transform: `str_replace(' ','T',$dt) . 'Z'` |
| `pm probe all_day=false` | Projects adapter not using `to_event_all_day()` | Verify Pitfall A mitigation in Plan 27-01 Task 2 |
| `route not registered (404)` | Step 1 cp'd file but Step 2 entrypoint was stale | Re-cp entrypoint (Step 2); restart pod if opcache cached the old version |

---

## Step 7 — Post-deploy checks

```bash
# 1) gend-society NOT auto-deactivated (Pitfall 2)
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  wp option get recently_activated --allow-root --path=/var/www/html
# expect: empty array OR list of OTHER plugins, NEVER 'gend-society/gend-society.php'

# 2) gend-society active network-wide
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  wp plugin list --name=gend-society --allow-root --path=/var/www/html
# expect: status=active-network

# 3) NO calendar-events-rest fatals in last 100 log lines
kubectl logs -n wp-hub deploy/wordpress -c wordpress --tail=100 \
  | grep -E 'Fatal|Failed opening' \
  | grep -E 'calendar-events-rest|Gend_GS_Calendar' \
  | head -10
# expect: NO output (empty)
```

---

## Rollback

**Option A — Revert the file (preferred, surgical):**
```bash
# Check out the pre-Plan-27-02 file from git and cp it back
git show HEAD~1:build/wp-content-template/plugins/gend-society/inc/calendar-events-rest.php \
  > /tmp/calendar-events-rest.php.prev
MSYS_NO_PATHCONV=1 kubectl cp /tmp/calendar-events-rest.php.prev \
  wp-hub/$POD:/var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php \
  -c wordpress
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  chown www-data:www-data /var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php
```

**Option B — Remove the file entirely:**
The `file_exists` guard in `gend-society.php:80` renders the bootstrap block a no-op if the include is absent. The calendar REST endpoint disappears, but gend-society itself stays healthy.
```bash
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  rm /var/www/html/wp-content/plugins/gend-society/inc/calendar-events-rest.php
```

**Option C — Restore plugin activation (if Pitfall 2 fired):**
```bash
kubectl exec -n wp-hub "$POD" -c wordpress -- \
  wp plugin activate --network gend-society --allow-root --path=/var/www/html
```

---

## Post-deploy (parent repo)

After Steps 1-7 are GREEN on the hub:

1. Bump the gend-society submodule pointer in the parent repo.
2. Mark `AGG-01`, `AGG-03`, `AGG-06`, `AGG-07` as `Complete` in `.planning/REQUIREMENTS.md` (and update the traceability table).
3. Flip Phase 27 to ✅ in `.planning/ROADMAP.md` (`roadmap update-plan-progress 27 27-02 complete` then `phase complete 27`).
4. Advance `STATE.md` current focus to Phase 28.

---

## Inheritance notes for Phases 28-30

When Phase 28 adds `wp_gs_member_meetings` table + activation hook:
- Pre-flight inventory diff catches the new include file.
- Step 1 cp's the new include FIRST.
- Step 7's `recently_activated` check is THE early-warning for activation fatals (Pitfall 2).
- If Phase 28's `dbDelta` runs into trouble on new subsites, the `wp_initialize_site` self-heal pattern is the cure (Pitfall 3).

When Phase 30 adds the Jitsi room provisioning module:
- Same cp ORDER discipline; the Jitsi web/prosody/jicofo/jvb image versions follow the BTCPay pin-and-bump-together rule.
- `frame-ancestors https://*.gend.me` CSP on the meet.gend.me ingress mirrors the BTCPay X-Frame relaxation pattern.

---

*Runbook for: Phase 27 BlogManager adapter + cross-cutting UATs*
*Memory: `[[project_hub_plugin_sync_gotcha]]`, `[[project_plugin_sync_quirk]]`, `[[project_image_build]]`*
*Inherits from: Plan 26-03 SUMMARY (Phase 26 deploy runbook canonicalization)*

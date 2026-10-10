# Deploying gend-society to the gend.me hub

This is the canonical procedure for changing the `gend-society` plugin (and its bundled
`gend-society-theme`) on the live gend.me hub. Plugin sync is disabled on the hub
(`.no-plugin-sync`), so nothing reaches live unless it is copied by hand, and more than one
person or session may be deploying at the same time. This runbook exists so a deploy never
overwrites somebody else's live change.

**Command form.** Every live command below is written in full in the Git Bash form:

    MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub <verb> ...

`MSYS_NO_PATHCONV=1` stops Git Bash from rewriting `/var/www/...` and `/tmp/...` arguments into
Windows paths. In PowerShell, write the same command without the `MSYS_NO_PATHCONV=1 ` prefix.

**Paths.** Always write the literal plugin path `/var/www/html/wp-content/plugins/gend-society`
in every command. Never use a shell variable for it: inside `sh -c '...'` a variable is expanded
on the pod, where it is unset, and files would land at `/<rel>` in the container root.

In the commands, `<rel>` is a file path relative to the plugin root (for example
`inc/messages-tabs.php`) and `<repo>` is your local Society-Core checkout.

---

## 0. Preconditions

- The change is merged to `main`, or is on a pushed branch whose base is a fresh `origin/main`
  (`git fetch origin && git rebase origin/main`).
- `bash bin/stamp-version.sh check` passes.
- If any asset (CSS/JS) changed, `GS_VERSION` has been bumped with `bash bin/stamp-version.sh set <x.y.z>`.
  The version on the hub must never be lower than the version in `main`.
- If content pulled from live is being committed to this public repo, it has passed a secret scan
  (keys, tokens, passwords, private hostnames) before the push.
- gend-society >= 1.1.6 reads its runtime mode from the hub Deployment env
  `GEND_SOCIETY_RUNTIME=hub`. Check it on the pod before any swap:

      MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- printenv GEND_SOCIETY_RUNTIME

  If it does not print `hub`, stop. Do not deploy 1.1.6+ until the env is set.

## 1. MANDATORY: pull live and diff immediately before swap

Do not skip this step, and do not reuse an older pull. Pull within minutes of the swap.

1. For every file in the deploy set, pull the live copy fresh:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- cat /var/www/html/wp-content/plugins/gend-society/<rel> > live/<rel>

   If the file does not exist on live, record it as "absent".

2. Record each file's live md5 into a `pre-swap.md5` list ("absent" for new files):

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- md5sum /var/www/html/wp-content/plugins/gend-society/<rel>

3. Diff each file, ignoring CR:

       diff -u --strip-trailing-cr live/<rel> <repo>/<rel>

   **Every REMOVED (`-`) line must be one this change intends to remove.** If any removed line is
   somebody else's (another session deployed after your base), STOP. Rebase your change onto the
   live file, re-commit, and pull again.

4. Run the drift compare for the whole plugin. Either stream the manifest script:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec -i deploy/wordpress -c wordpress -- sh -s -- /var/www/html/wp-content/plugins/gend-society < "C:/Desktop Web App Builder/gend-web-builder/scripts/gs-drift-manifest.sh" > live.md5

   (The script lives in the gend-web-builder repo, not in Society-Core.) Then run
   `gs-drift-compare.sh` against your tree. Or take a full pull of the plugin directory and run
   `diff -rq --strip-trailing-cr` against your tree, minus `.drift-ignore`.
   STOP if live has changes outside your deploy set that are not in `main`; fold them into the
   repo first.

5. Keep the pulled copies. They are the rollback set.

## 2. Work out the deploy set

The deploy set is the files that differ between the fresh live tree and the repo tree, minus the
paths in `.drift-ignore`.

## 3. Upload each file as `.new`

    MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec -i deploy/wordpress -c wordpress -- sh -c 'cat > /var/www/html/wp-content/plugins/gend-society/<rel>.new' < <repo>/<rel>

Check that the pod copy matches the local one (`md5sum <repo>/<rel>` locally):

    MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- md5sum /var/www/html/wp-content/plugins/gend-society/<rel>.new

## 4. Lint every `.new` PHP file on the pod

    MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- php -l /var/www/html/wp-content/plugins/gend-society/<rel>.new

## 5. Per-file re-check, then swap

One file at a time, in this order: assets, theme and text files first, then `inc/` PHP, and
`gend-society.php` LAST (it carries the version).

1. Re-read the live target's md5 immediately before its swap:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- sh -c 'md5sum /var/www/html/wp-content/plugins/gend-society/<rel> 2>/dev/null || echo absent'

2. Compare it with that file's entry in `pre-swap.md5`. **On any mismatch, abort:** remove the
   remaining `.new` files and restart from step 1. Somebody changed live after your diff.

3. If it matches, swap:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- sh -c 'chown www-data:www-data /var/www/html/wp-content/plugins/gend-society/<rel>.new && mv -f /var/www/html/wp-content/plugins/gend-society/<rel>.new /var/www/html/wp-content/plugins/gend-society/<rel>'

**Dependency-ordered swap.** When changed `inc/` files call functions that only the NEW
entrypoint's bootstrap defines (for example `gend_society_is_hub()` from `inc/bootstrap/context.php`),
the order above changes to: new files (absent on live) -> `gend-society.php` -> the changed `inc/`
files. Otherwise a changed `inc/` file could run under the old entrypoint and fatal on an undefined
function. Rollback in reverse: the changed `inc/` files first, then `gend-society.php`, then remove
the new files.

## 6. Verify

- The md5 on live equals the repo copy for each deployed file.
- The plugin version:

      MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- wp --allow-root --path=/var/www/html --url=gend.me plugin get gend-society --field=version

- The phase UAT: stream it to `/tmp` on the pod (same stdin method as step 3, target
  `/tmp/<uat>.php`), then run it and remove it:

      MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- wp --allow-root --path=/var/www/html --url=gend.me eval-file /tmp/<uat>.php
      MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- rm -f /tmp/<uat>.php

- `curl -s -o /dev/null -w '%{http_code}' https://gend.me/` returns 200.
- The homepage HTML shows gend-society asset URLs with the new `ver=`.
- No new fatals in the pod log:

      MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub logs deploy/wordpress -c wordpress --tail=300 | grep -E "PHP (Fatal|Parse)|Uncaught"

  Never use `kubectl logs --since`; it hangs on this cluster.

## 7. Rollback

Stream the step-1 pulled copies back as `.new` (same command as step 3) and `mv -f` them into
place (same command as step 5.3). For a file that was "absent" before the deploy:

    MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- rm -f /var/www/html/wp-content/plugins/gend-society/<rel>

## 8. Never

- `kubectl cp` straight over a live file (always `.new` + `mv -f`).
- Deploy from a stale local checkout.
- Edit image-owned loose mu-plugins; they are overwritten on every pod start.

## 9. After the deploy, live must equal main

Merge the PR if it is not merged yet, and confirm the drift check (the `gs-live-drift-check`
workflow) is green.

## 10. Image-owned loose mu-plugins on the hub

The hub's WordPress image owns the loose files in `wp-content/mu-plugins/` (for example
`gdc-iframe-embed.php`, `zzz-gend-local-native-login.php`, `gdc-local-content-endpoint.php`).
The image entrypoint copies them back over the persistent volume on every pod start, so an
edit made only on the volume is silently undone by the next restart. A volume copy is
therefore allowed ONLY when it is byte-identical to a file that is merged to gend-web-builder
`master` AND already baked into a successfully built base image. This is the one exception to
"Edit image-owned loose mu-plugins" in section 8. Never put a volume-only edit into an
image-owned file; if a change cannot go through the image, use a new file name or a subfolder.

In the commands, `<name>` is the mu-plugin file name (for example `gdc-iframe-embed.php`) and
`<gwb>` is your local gend-web-builder checkout, freshly fetched (`git -C <gwb> fetch origin`).

1. The change is merged to gend-web-builder `master`. Note the merge commit sha.

2. The "Build WordPress Base Image" run for that merge commit succeeded:

       "/c/Program Files/GitHub CLI/gh.exe" run list -R gend-me/gend-web-builder --workflow build-wp-base-image.yml --limit 5

   The run whose head sha is the merge commit must show `completed` / `success`. If it failed
   or is still running, stop and wait.

3. Pull the live file and diff it against the PREVIOUS `master` blob (the first parent of the
   merge):

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- cat /var/www/html/wp-content/mu-plugins/<name> > live-<name>
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- md5sum /var/www/html/wp-content/mu-plugins/<name>
       git -C <gwb> show <merge-sha>^1:build/wp-content-template/mu-plugins/<name> | diff -u --strip-trailing-cr live-<name> -

   **STOP if live differs from the previous `master` blob.** Somebody hand-edited the live file;
   fold their change into the repo first. Keep `live-<name>` as the rollback copy.

4. Upload the new `master` blob as `.new`, check it and lint it:

       git -C <gwb> show origin/master:build/wp-content-template/mu-plugins/<name> > new-<name>
       git -C <gwb> show origin/master:build/wp-content-template/mu-plugins/<name> | md5sum
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec -i deploy/wordpress -c wordpress -- sh -c 'cat > /var/www/html/wp-content/mu-plugins/<name>.new' < new-<name>
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- md5sum /var/www/html/wp-content/mu-plugins/<name>.new
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- php -l /var/www/html/wp-content/mu-plugins/<name>.new

   The `.new` md5 must equal the `git show ... | md5sum` value (the blob itself, not a
   CRLF-converted working-tree copy). If not, remove the `.new` file and stop.

5. Re-check the live file's md5 against step 3, then swap:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- md5sum /var/www/html/wp-content/mu-plugins/<name>
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- sh -c 'chown www-data:www-data /var/www/html/wp-content/mu-plugins/<name>.new && mv -f /var/www/html/wp-content/mu-plugins/<name>.new /var/www/html/wp-content/mu-plugins/<name>'

   On a mismatch, remove the `.new` file and restart from step 3.

Rollback: stream `live-<name>` back as `.new` and `mv -f` it into place the same way. The next
pod start restores the image copy (the new version) anyway, so a lasting rollback needs a
revert merged to `master` and a new image build.

## 11. Whole-directory swap (prefix rename, Phase 105)

Use this instead of the per-file swap (sections 3-5) when a change touches nearly every file of
the plugin, so that no request ever runs a mix of old and new files. Section 0 (preconditions,
including the runtime env gate) and section 6 (verify) still apply unchanged.

In the commands, `<oldver>` is the version currently on live (for example `1.1.6`), `<branch>`
is the rename branch, and `<stage>` is a local, empty staging directory.

### 11.1 Drift and provenance (replaces section 1 for this deploy)

1. Whole-plugin drift compare, live vs `origin/main` (section 1 step 4). It must report **0
   non-ignored differences**. Keep `live.md5` from this run; it is the pre-swap manifest.
2. Prove the branch is `bin/rename.php` applied to `origin/main` plus a listed set of
   hand-written files: check out a fresh `origin/main` into a scratch directory, run the renamer
   on it, and `diff -r --strip-trailing-cr` the result against the branch. Every difference must
   be in the hand-written list recorded in the PR. Anything else: STOP.
3. List the paths that exist only on live and are covered by `.drift-ignore` (for example
   `handoff/` files deployed ad hoc). Pull them from live; they are carried into the staged tree
   so the swap does not delete them.

### 11.2 Build and stage the new tree

1. Build the deployable tree locally:

       git -C <repo> archive --format=tar --prefix=gend-society/ <branch> | tar -C <stage> -xf -

   Delete from `<stage>/gend-society` every path matched by `.drift-ignore`, then copy in the
   only-live paths pulled in 11.1 step 3. Write the local manifest:

       sh "C:/Desktop Web App Builder/gend-web-builder/scripts/gs-drift-manifest.sh" <stage>/gend-society > staged-local.md5

2. Confirm the private area is not web-reachable:

       curl -s -o /dev/null -w '%{http_code}' https://gend.me/wp-content/gdc-private/

   It must print `403`. Otherwise stage somewhere else outside the docroot (on the same volume)
   and adjust every path below accordingly.

3. Same-filesystem check (run it now AND again immediately before the swap):

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- stat -c %d /var/www/html/wp-content/gdc-private /var/www/html/wp-content/plugins

   It must print the same device id twice. **STOP if they differ:** `mv` would copy instead of
   rename, and the swap would not be atomic.

4. Stream the tree to the pod and fix ownership:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- mkdir -p /var/www/html/wp-content/gdc-private/stage-105 /var/www/html/wp-content/gdc-private/rollback-105
       tar -C <stage> -cf - gend-society | MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec -i deploy/wordpress -c wordpress -- tar -C /var/www/html/wp-content/gdc-private/stage-105 -xf -
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- chown -R www-data:www-data /var/www/html/wp-content/gdc-private/stage-105/gend-society

5. Lint every staged PHP file:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- sh -c 'find /var/www/html/wp-content/gdc-private/stage-105/gend-society -name "*.php" -exec php -l {} \; | grep -v "^No syntax errors"'

   Any output is a failure: STOP.

6. The staged whole-tree manifest must equal the local one (`diff` prints nothing):

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec -i deploy/wordpress -c wordpress -- sh -s -- /var/www/html/wp-content/gdc-private/stage-105/gend-society < "C:/Desktop Web App Builder/gend-web-builder/scripts/gs-drift-manifest.sh" > staged-pod.md5
       diff staged-local.md5 staged-pod.md5

### 11.3 Pre-swap re-check

1. Re-run the live whole-tree manifest and compare it with `live.md5` from 11.1 step 1:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec -i deploy/wordpress -c wordpress -- sh -s -- /var/www/html/wp-content/plugins/gend-society < "C:/Desktop Web App Builder/gend-web-builder/scripts/gs-drift-manifest.sh" > live-preswap.md5
       diff live.md5 live-preswap.md5

   **Any change = ABORT.** Somebody deployed in between; restart from 11.1.
2. Repeat the same-filesystem check (11.2 step 3).
3. Only if an earlier attempt of this release ran on the hub (its new-name rows are still there):
   repair them first, on the old version, with the staged copy of the fixed migration file. Upload
   `handoff/105-dedupe-new-keys.php` to the pod's `/tmp` (md5-verified), run the dry run, read the
   last line, then apply and check that it prints `DEDUPE: OK` with `old-key rows unchanged` and
   `left to fix: 0`:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- env GS105_KM_FILE=/var/www/html/wp-content/gdc-private/stage-105/gend-society/inc/bootstrap/key-migration.php wp --allow-root --path=/var/www/html --url=gend.me eval-file /tmp/105-dedupe-new-keys.php
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- env GS105_KM_FILE=/var/www/html/wp-content/gdc-private/stage-105/gend-society/inc/bootstrap/key-migration.php GS105_APPLY=1 wp --allow-root --path=/var/www/html --url=gend.me eval-file /tmp/105-dedupe-new-keys.php

   It writes only new `gend_society_` names from the key map (user meta, group meta, network
   options, post meta on every blog): each object's new key ends up with exactly the old key's
   rows. Old keys and other plugins' keys are never written. Delete the `/tmp` copy afterwards.

### 11.4 Swap (one `sh -c`)

    MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- sh -c 'mv /var/www/html/wp-content/plugins/gend-society /var/www/html/wp-content/gdc-private/rollback-105/gend-society-<oldver> && mv /var/www/html/wp-content/gdc-private/stage-105/gend-society /var/www/html/wp-content/plugins/gend-society'

Both renames are on the same volume, so each is atomic; the gap between them is milliseconds.

### 11.5 Immediately after the swap

1. Reset opcache in the web SAPI (a CLI `opcache_reset()` does not touch the web server's
   cache). Locally, pick a random file name `oc-<random>.php` and a random `<token>`, and write
   a file `oc.php` containing:

       <?php if ( ( $_GET['t'] ?? '' ) !== '<token>' ) { http_response_code( 404 ); exit; } var_dump( opcache_reset() );

   Stream it into the image docroot `/var/www/html/` (not the volume), call it from inside the
   pod, then delete it:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec -i deploy/wordpress -c wordpress -- sh -c 'cat > /var/www/html/oc-<random>.php' < oc.php
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- curl -s -H 'Host: gend.me' 'http://localhost/oc-<random>.php?t=<token>'
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- rm -f /var/www/html/oc-<random>.php

   The curl must print `bool(true)`. Confirm the file is gone.
2. Curl the four hub sites; each must return 200.
3. Run the deploy pass once from the main site and read back the flags. It migrates the network
   data and every blog. Web requests that arrive first migrate on load, each scope under an atomic
   lock (one request per scope, the others wait or read through to the old rows), and the deploy
   pass waits up to two minutes per scope while a web request holds a lock:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- wp --allow-root --path=/var/www/html --url=gend.me eval 'echo json_encode( gend_society_migrate_all_blogs() );'

   Every blog id and `network` must report the map version (for example `105.1`). The lock rows
   `gend_society_keys_migrating` (each blog) and `gend_society_network_keys_migrating` (main
   site options table) must be gone afterwards.
4. Section 6 (verify), including the fatal-log check.

### 11.6 Rollback

1. FIRST, while the new code is still in place, move the renamed cron hooks back:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- wp --allow-root --path=/var/www/html --url=gend.me eval 'gend_society_unmigrate_cron_all_blogs();'

   This moves every renamed-hook cron event back to its old hook with the same timestamp,
   schedule and args on every blog. Skipping it loses per-booking `gs_booking_send_reminder`
   events, because the old code does not know the new hook names.
2. Reverse the swap in one `sh -c`, and reset opcache IMMEDIATELY after it (11.5 step 1), before
   anything else. Until the reset, cached new code keeps serving requests (`revalidate_freq` 2 s),
   still sees the flags set and re-schedules its new-hook cron events on `init`:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- sh -c 'mkdir -p /var/www/html/wp-content/gdc-private/stage-105/failed && mv /var/www/html/wp-content/plugins/gend-society /var/www/html/wp-content/gdc-private/stage-105/failed/gend-society && mv /var/www/html/wp-content/gdc-private/rollback-105/gend-society-<oldver> /var/www/html/wp-content/plugins/gend-society'

   Then the opcache reset. On the hub `opcache_reset()` can return `bool(false)` while an OOM
   restart is pending: call it again until it prints `bool(true)`.
3. Re-check cron for new-hook events on every blog and clear them. Upload
   `handoff/105-rollback-cron-check.php` to the pod's `/tmp` (md5-verified) and run it with the
   key map of the failed tree, first as a dry run, then with `GS105_APPLY=1`:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- env GS105_MAP_FILE=/var/www/html/wp-content/gdc-private/stage-105/failed/gend-society/inc/bootstrap/key-map.php wp --allow-root --path=/var/www/html --url=gend.me eval-file /tmp/105-rollback-cron-check.php
       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- env GS105_MAP_FILE=/var/www/html/wp-content/gdc-private/stage-105/failed/gend-society/inc/bootstrap/key-map.php GS105_APPLY=1 wp --allow-root --path=/var/www/html --url=gend.me eval-file /tmp/105-rollback-cron-check.php

   For each new hook and args that also exist on the old hook, the new copies are removed with
   `wp_clear_scheduled_hook( <new hook>, <args> )`. An event with no old twin (for example a
   booking reminder created in the window) is moved back to its old hook first, so nothing is
   lost. The apply run must end with `ROLLBACK CRON: OK (... 0 left, 0 failed)`. Run the dry run
   once more after step 4; it must print `ROLLBACK CRON: OK (0 new-hook events ...)`.
4. Delete the migration flags on every blog and the network flag, so a later retry migrates
   again:

       MSYS_NO_PATHCONV=1 kubectl --context gke_gend-me_us-central1_gend-prod -n wp-hub exec deploy/wordpress -c wordpress -- sh -c 'for u in $(wp --allow-root --path=/var/www/html site list --field=url); do wp --allow-root --path=/var/www/html --url="$u" option delete gend_society_keys_migrated; done; wp --allow-root --path=/var/www/html --url=gend.me site option delete gend_society_network_keys_migrated'

Old option/meta rows are never deleted by this procedure. They are current, because the compat
bridges write through to both keys. But values the new version wrote only to the new keys are
not mirrored back and are lost on rollback; state this in the rollback report.

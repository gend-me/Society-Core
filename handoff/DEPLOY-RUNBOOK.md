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

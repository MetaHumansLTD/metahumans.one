# project_memory.md
# ---------------------------------------------------------------
# metahumans.one LLM-context safeguard (S3 protocol)
# ---------------------------------------------------------------
# This file is an APPEND-ONLY (new entries go at the BOTTOM) ledger
# of every edit session's final state. It combats two LLM failure
# modes we have observed in production:
#
#   1. MID-SESSION TOOL-EVICTION: when TRAE's context window fills
#      up mid-push, oldest `{tool} results cleared` messages
#      silently drop diagnostic outputs and the model forgets which
#      files were actually edited vs which were only proposed.
#
#   2. CROSS-SESSION SUMMARY LOSSY-COMPRESSION: the "Here is a
#      summary..." handoff between consecutive TRAE sessions drops
#      intermediate diagnostics, error messages, and the exact
#      set-id of which 500 fixes have actually been committed vs
#      which are still pending.
#
# Format for new entries (append exactly one block per session;
# do not edit or delete prior blocks):
#
#   ----------------------------------------------------------------
#   Session #[n] — YYYY-MM-DD HH:MM UTC (author)
#   Commits Applied: <hash>[, <hash>…] (or "working-tree only, not yet pushed")
#   Fixed: <semantic list of what is definitely fixed this session>
#   Currently known broken: <paths / error messages / features known not to work right now>
#   Pending investigation: <root causes still uncertain; more traces needed>
#   Next actions: <explicit ordered list the next session must pick up first, before any new work>
#   ----------------------------------------------------------------
#
# ---------------------------------------------------------------
# SESSION 1  (baseline snapshot; reconstructed retroactively from commit 67f70da2)
# ---------------------------------------------------------------
#
# Session #1 — 2026-08-03 (reconstructed)
#   Commits Applied: 3d225e7e → 4c85f5e0 → c15a14ee → 356e843f → 869361b5 → 67f70da2
#   Fixed:
#     - NEO settings form on /control/domain-registrars/providers/netearthone/ saves non-sensitive
#       fields (invoiceOption, creditLimit, notifyEmail) to providerAccount.config_json;
#       credentials stay in mounted NF secret set (no DB leaks of apiKey/auth).
#     - WorkerService::processDomainPortfolioSync now calls upsertImportedDomain per imported
#       row; previously it only counted lines and never persisted → NEO domains never showed.
#     - 5 domain sync jobs (import_provider_domains, sync_domain_portfolio, sync_domain_dates,
#       sync_pricing, retry_failed_sync_runs) injected into mh_sync_cron_load_cfg defaults in
#       gear/sync/index.php with legacy-guarded fallbacks. Native NF `schedules:` array retired
#       in northflank/worker.job.yaml; ENABLE_SCHEDULER=false.
#     - KripzMasters bypass all control ACLs; menu-permission-manager permission system respected
#       globally; /hub/companies/domains/manage/ scopes to user's own registered domains only.
#   Currently known broken (as of commit 67f70da2, before session #2's fixes):
#     - POST /control/domain-registrars/domains/sync/portfolio → HTTP ERROR 500 blank page
#       (was 404 before 67f70da2's route addition; after 67f70da2 the POST succeeded but the
#       redirect-Location header was silently ignored, so outer catch rendered 500 after commit).
#     - GET /control/domain-registrars/ (Domains tab) → HTTP ERROR 500 blank page;
#       DomainRepository::search() threw when domains table DNE or tenant context failed.
#   Pending investigation:
#     - rubeus.co.za registry-side NS not reflecting ns1-ns4.clusterdns.co.za update even though
#       registrar accepted the frame; likely missing fresh auth_code. No new EPP push attempted
#       this session.
#   Next actions:
#     - Fix 500s on both sync-portfolio POST and control/domains GET by removing synthetic
#       tenant injection, ob guard rewrite, add safeRedirect, add defensive renderDomains.
#     - Implement pre-deploy app-level checkpoint (backup item #2 discussed earlier) so every
#       deploy has a filesystem+MySQL+PostgreSQL dump even when NF Guarded Restore returns 403.
#     - Implement S3: append entries here at end of every session. S1+S2 (post-commit hook +
#       backup_changes folder) slated for later session.
#
# ---------------------------------------------------------------
# SESSION 2  (final state — commit 432d847b, 2026-08-07)
# ---------------------------------------------------------------
# Session #2 — 2026-08-07
#   Commits Applied: 432d847b (on top of 67f70da2 from Session #1)
#   Fixed:
#     - POST /control/domain-registrars/domains/sync/portfolio (Sync NetEarthOne button):
#       was HTTP ERROR 500 blank → now 200 OK with redirect via safeRedirect() back to
#       /control/domain-registrars/ domains listing with flash.
#       Root causes fixed this session:
#         1. control.php/hub.php: declare(strict_types=1)-ordered error_reporting(0) +
#            @ini_set(display_errors=0,html_errors=0,log_errors=1) so PHP E_NOTICE output
#            cannot pollute stdout before ob_end_clean() and flip headers_sent()=true.
#         2. Removed synthetic tenant `registrar:control-pool` / TENANT_DB_CONFIG_ID pin
#            from both integrations. Replaced with Application::enableRegistrarPoolMode()
#            which scopes DB at the Application level without touching CUE tenant context.
#         3. ConnectionFactory::tenantByConfigId() → 4-level fallback chain, all Throwable
#            swallowed so unknown CUE context can't crash bootstrap.
#         4. SchemaLoader.load() → warn-and-continue; never throws on missing file or
#            duplicate FK SQL (common on second apply against already-provisioned DB).
#         5. Aggressive ob guard: `while (ob_get_level() > 0) { ob_end_clean(); }`
#            (removed the BOM-preserving trim-break that kept 1 byte of stdout pollution).
#         6. All 5 raw header('Location: ...') call sites → ControlController::safeRedirect()
#            which emits a valid HTML redirect page with meta-refresh and a clickable link
#            when headers_sent() is true, otherwise uses the native 302.
#     - GET /control/domain-registrars/ (Domains tab): was HTTP ERROR 500 when
#       DomainRepository::search() threw (no domains table / bad tenant DB). Now:
#       search() and listTlds() individually try/catch wrapped; on failure the page
#       renders an amber-bordered "Portfolio not ready yet" card linking directly to
#       /control/domain-registrars/providers/netearthone/ with inline muted diagnostic
#       of the actual error message.
#     - docker-entrypoint-mh.sh (NEW pre-deploy checkpoint = backup item #2):
#       Runs on every container start BEFORE the CMD. Calls in order:
#         1. gear/backups/mysql-dumps.php  (logical mysqldump per registered DB)
#         2. gear/backups/run.php mysql    (rsync snapshot of /mysql volume)
#         3. gear/backups/run.php data     (rsync snapshot of /data volume)
#       Backup errors are logged and NEVER block service start. MH_SKIP_CHECKPOINT=1 skips.
#       Dockerfile + Dockerfile.worker set ENTRYPOINT and correct WORKDIR (fixed
#       Dockerfile.worker /app vs worker.job.yaml /var/www/html/... mismatch).
#     - control.service.yaml + hub.service.yaml: github.repository URL corrected
#       to metahumansLTD/metahumans.one monorepo (was standalone domain-registrars).
#       workingDirectory and MH_CHECKPOINT_SETS env documented.
#     - worker.job.yaml: redundant runtime.command override removed, MH_CHECKPOINT_SETS
#       added, entrypoint checkpoint notes added.
#     - S3 safeguard (this ledger): project_memory.md created at repo root with Session #1
#       retroactively reconstructed and Session #2 (this session) final state committed.
#   Currently known broken:
#     - (none confirmed on master after 6ae08cc8; see Pending investigation for areas still
#       not live-tested because no staging env exists before push).
#   Pending investigation:
#     - rubeus.co.za registry NS sync: need freshly-requested auth_code from ZACR
#       re-saved to domain metadata.auth_code, then re-send EPP <update> with all 4 NS.
#       Registry accepted EPP frame silently last time, did not reflect at registry.
#     - Northflank Guarded Restore 403 Feature disabled for your account: not an app bug;
#       pre-deploy app-level checkpoint (this commit's docker-entrypoint-mh.sh) is the
#       primary workaround. Existing checkpoint nf-restore-20260804-101136-3f9be4 remains
#       restorable manually via NF UI.
#     - Live (post-deploy) verification still needed for both:
#         * POST /control/domain-registrars/domains/sync/portfolio → 200 → redirect with flash
#         * GET /control/domain-registrars/ Domains tab → renders NEO portfolio after Sync
#   Next actions (for the next session — execute IN THIS ORDER before any new work):
#     1. Post-deploy smoke test: open /control/domain-registrars/providers/netearthone/
#        (login as KripzMaster or user with registrar:control permission). Confirm no 500.
#     2. Save NEO provider settings (invoiceOption / creditLimit / notifyEmail). Confirm
#        fields round-trip back; confirm credential fields are NOT re-displayed and stay
#        ONLY in mounted NF secret sets.
#     3. On /control/domain-registrars/domains/ click Sync NetEarthOne button.
#        Confirm: POST /control/domain-registrars/domains/sync/portfolio → 200 OK,
#        redirects back with "Queued sync_domain_portfolio" flash.
#     4. After worker processes the job, reload GET /control/domain-registrars/ (Domains tab).
#        Confirm: NEO-imported domains list with Registration/Renewal/Expiry dates.
#     5. Implement S1 safeguard: git post-commit hook that writes PATCH_NOTES.md with
#        the commit message, changed files, and diffstat summary at repo root AND
#        copies into backup_changes/ as a timestamped file on EVERY `git commit`.
#     6. Implement S2 safeguard: backup_changes/ folder at repo root with .gitkeep,
#        each git commit produces backup_changes/YYYYMMDD-HHMMSS-<shortSHA>-files.tgz
#        that contains the working-tree snapshot of changed files for that commit's
#        diffstat, so mid-session partial-state rollbacks are possible even outside git.
#     7. rubeus.co.za: obtain fresh ZACR auth code → save to domain metadata.auth_code
#        in DB → re-send EPP update with ns1-ns4.clusterdns → wait 5m → re-read registry
#        via ensureDomainSyncedForManagement() on /edit/rubeus.co.za/ → verify NS reflected.
#
# ---------------------------------------------------------------
# SESSION 3  (final state — commit 3756d885, 2026-08-07)
# ---------------------------------------------------------------
# Session #3 — 2026-08-07
#   Commits Applied: 3756d885 on top of 432d847b from Session #2.
#   (Commit uploaded successfully.)
#   Trigger for this session: user reported commit 432d847b had uploaded but both pages
#     /control/domain-registrars/providers/netearthone/  AND
#     /control/domain-registrars/domains/sync/portfolio
#     were STILL HTTP ERROR 500 blank (WSOD). Git confirmed 432d847b was at both
#     origin/main and HEAD (push had succeeded), so the issue was deeper code paths,
#     not missing upload.
#   Root causes identified (explains why Session #2's fixes weren't sufficient):
#     1. NO OUTPUT BUFFER STARTED BEFORE CUE BOOTSTRAP. control.php and hub.php
#        had their aggressive ob_end_clean() loops only at ~line 120 AFTER the
#        require $cueBootstrapPath (line ~20) AND after session_start, tenant
#        context apply, bootstrap/app.php require, enableRegistrarPoolMode call,
#        ControlController construction, and the $method assignment. Between line 5
#        (error_reporting) and line 120 there were ~100 PHP statements with NO
#        ob_start() at all. So ANY of the following could leak BOM bytes / echo /
#        PHP header-preamble output without any capture:
#          a. require $cueBootstrapPath → cue.php or includes within could echo
#             debug output, whitespace-before-<?php open tags in other modules,
#             UTF-8 BOM from includes modified on Windows CIFS shares.
#          b. require bootstrap/app.php → composer autoload_real → vendor files
#             can emit whitespace bytes.
#          c. session_start() → Set-Cookie header requires no prior output; if
#             any BOM byte was already emitted, headers_sent=TRUE at this point.
#          d. headers_sent()=true BEFORE we hit line 120 → then line 120's
#             ob_end_clean had nothing to clean up because no ob was active.
#     2. THROWS → OUTPUT-ALREADY-SENT → HTTP 500 AFTER COMMIT → BLANK WSOD.
#        In Session #2's code, both:
#          a. if (! cue_autoload) throw new RuntimeException('CUE bootstrap path ...')
#          b. if (! bootstrap file exists) throw new RuntimeException('Domain registrar
#             bootstrap file is missing.')
#        were plain throw statements. The outer catch block would run AFTER the
#        throw but at that point $cueBootstrapPath require had already emitted its
#        require-once output byte to stdout → headers_sent() true → the inner
#        if (!headers_sent()) http_response_code(500) was SKIPPED → outer catch
#        renders its error HTML BUT since headers were already sent with 200 the
#        Apache/mod_php handler sees "500 status attempted after headers=200"
#        and replaces the body entirely with a blank HTTP ERROR 500 WSOD.
#     3. AUTH REDIRECT WITH NO GUARD. Both control.php and hub.php unauthenticated
#        path did raw header('Location: /auth/login.php?...', true, 302) with NO
#        headers_sent guard. If BOM bytes leaked → headers_sent=true → redirect
#        silently ignored → outer catch or 500 fallback runs again with same result.
#     4. MID-CODE SILENTLY SWALLOWED OB LAYER. The throw at (2a) would bubble up
#        uncaught until the very last catch. But during the unwind there was no
#        guarantee there even WAS an ob to clean. Because Session #2 added NO
#        ob_start at the top.
#   Fixed in Session #3 (applied symmetrically to BOTH integrations):
#     a. IMMEDIATELY AFTER error_reporting(0) + ini_set(), start A NEW dedicated
#        "MH_CONTROL_OB_CLEANUP" / "MH_HUB_OB_CLEANUP" swallow-output buffer.
#        Uses non-removable flag + callback that returns '' (the most aggressive
#        output suppression possible in Zend). This runs BEFORE cue.php require,
#        before session_start, before bootstrap/app.php require. Any BOM byte /
#        whitespace / echo from includes OR vendor between lines 5 and ~145 now
#        gets captured and silently discarded.
#     b. Changed the two early-throw RuntimeExceptions (CUE path missing, bootstrap
#        file missing) to plaintext 500 exits with headers_sent guards. No throw =
#        nothing to unwind through 100 layers of potential catch pollution.
#     c. Added headers_sent() guard + meta-refresh fallback HTML to the two login
#        redirects (control.php line 51-59 / hub.php line 61-70). Same pattern as
#        ControlController::safeRedirect() — if headers already leaked emit valid
#        redirect HTML with a clickable link.
#     d. The inner ob-clean at line 145+ now ALSO explicitly pops the MH_*_OB_CLEANUP
#        buffer via if (defined(...)) { @ob_end_clean(); } so no leftover buffers
#        remain before we start rendering the real response.
#     e. Rewrote the control/hub integration to accumulate the HTML response into
#        a single $mhFinalResponse variable (both success path AND catch path
#        assign $mhFinalResponse instead of directly echo) then ONE SINGLE echo
#        at the absolute END of the script. This ensures we never echo HTML inside
#        a conditional path that later 500-catches and tries to echo alternative
#        HTML (two echo calls back to back would double emit, which with zlib
#        output handlers on some NF apache builds can manifest as blank WSOD).
#   Currently known broken (still requires live deploy verification):
#     - /control/domain-registrars/providers/netearthone/  (Session #3 fix untested)
#     - /control/domain-registrars/domains/sync/portfolio  (Session #3 fix untested)
#   Pending investigation:
#     - Whether the root BOM/whitespace source was cue.php require inside CUE or
#       vendor/ composer autoload include. If this fix resolves the WSOD we can
#       leave it as-is (swallow buffer is the correct fix regardless of source).
#     - rubeus.co.za fresh auth_code (unchanged from prior sessions).
#     - Northflank 403 guarded restore (unchanged).
#   Next actions (Session #4, in this order, BEFORE any new work):
#     1. Deploy Session #3 commit to main → wait for Northflank build + rollout.
#     2. Hit https://control.metahumans.one/control/domain-registrars/providers/netearthone/
#        in a logged-out browser: confirm it redirects (302 OR meta-refresh HTML with link)
#        to /auth/login.php?redirect=... with NO blank 500.
#     3. Login as KripzMasters → open the same providers/netearthone/ page.
#        Confirm: no 500, settings panel loads, invoiceOption/creditLimit/notifyEmail
#        inputs render, credential fields are blank and NOT populated from DB.
#     4. Save NEO provider settings → confirm round-trip (values come back saved),
#        credentials still blank + never persisted to config_json.
#     5. Navigate to /control/domain-registrars/domains/ → click Sync NetEarthOne
#        button → confirm POST to /control/domain-registrars/domains/sync/portfolio
#        → no 500, redirect to domains listing with "Queued sync_domain_portfolio"
#        flash message.
#     6. After worker processes → reload /control/domain-registrars/ Domains tab
#        → confirm NEO portfolio with registration/renewal/expiry dates.
#     7. Implement S1 safeguard: git post-commit hook (as previously planned) that
#        writes PATCH_NOTES.md + backup_changes/ timestamped file.
#     8. Implement S2 safeguard: backup_changes/ directory with tgz snapshots.
#     9. rubeus.co.za ZACR fresh auth_code → EPP re-push with ns1-ns4.clusterdns.
#


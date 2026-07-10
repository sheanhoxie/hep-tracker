# DEPLOY — going online

Expands TODOS.md Phase 7 (this file owns deploy truth; TODOS.md points here). Stack per `stack-decisions.md`: single VPS, nginx + php-fpm + Postgres + queue + scheduler cron. Reviewed via /plan-eng-review 2026-07-10 — report at end of file.

**What HTTPS actually blocks.** `http://localhost` is a spec-designated secure context, so Phase 6's core mechanics — service worker lifecycle, `Notification.requestPermission()`, `pushManager.subscribe()` with a real VAPID key, and end-to-end VAPID-signed push delivery — are all testable in dev without TLS (and Phase 6 was in fact built and tested that way; TODOS.md marks it DONE 2026-07-09). Production HTTPS is still required, and three things only surface on a real host: TLS/mixed-content correctness, per-origin subscription binding (a subscription made on `http://localhost:8000` does not carry to `https://…`), and **iOS Safari push, which requires an installed PWA on a real HTTPS origin** (confirmed still true in 2026; no iOS release has lifted the iOS 16.4 requirement). That last one is Patient's actual path and is the delivery gap already flagged in `stack-decisions.md` §Failure modes. Note `http://192.168.x.x` is *not* secure-context exempt — only loopback.

---

## A. Prerequisites (do first — everything else blocks on these)

- [ ] **A1. `git init` + first commit.** No repo exists. Deploy scripts, CI, and rollback all assume one. Push to a private GitHub remote. **Fix `.github/workflows/tests.yml` before the first push:** it creates `database/database.sqlite`, but `phpunit.xml` pins `DB_CONNECTION=pgsql` / `DB_DATABASE=video_workout_app_test` with no Postgres service in the workflow — every push/PR to main and develop fails CI from commit one, and C2's low-RAM fallback leans on this pipeline. Add a `postgres:16` service container with matching env.
- [ ] **A2. Buy domain.** Pick the app hostname now — it's baked into `APP_URL`, the PWA manifest `start_url`, the TLS cert, and the push subscription origin. Changing it later invalidates every existing push subscription.
- [ ] **A3. Decide provider.** Hetzner CX22 (~€4/mo, 2 vCPU / 4 GB) or DO 2 GB (~$12/mo). Postgres + php-fpm + node build on one box wants ≥2 GB RAM; 1 GB will OOM during `npm run build`. (Managed alternatives — Forge/Ploi/Coolify — considered and declined 2026-07-10: stack-decisions.md already chose flat-cost VPS, and agent-written provisioning scripts erase the labor argument. Revisit only if ops burden materializes.)
- [ ] **A4. Audit `.env.example` for production.** It still ships `DB_CONNECTION=sqlite`; the app is Postgres. Add the prod-only keys (`VAPID_*`, `AWS_*`, `SESSION_SECURE_COOKIE=true`, `APP_TIMEZONE`) as empty placeholders so nothing is discovered missing at 2am.
- [ ] **A5. Resolve or explicitly accept the PT data gaps before seeding prod.** Phase 1 deferred PT verification of the VERIFY/DATA GAP dosages (exercise-q steps, hip-abduction "up to 20" conflict, exercise-r sets, squat-w/-ball, bridge-walk, exercise-s, exercise-o, exercise-p, inferred forms). Prescriptions are immutable-per-version and `program:revise` is post-v1 — **the day Patient logs her first real set_event, re-seeding stops being an option** and every dosage correction becomes hand-written SQL against production. Verify with the PT now, or record an explicit "shipping with known gaps, corrections will require pulling `program:revise` forward" acceptance. This is the cheap fix before the hard deadline, not after.

## B. Server bring-up

- [ ] **B1. Provision VPS**, Ubuntu 24.04 LTS. Record region (put it near Patient, not near you).
- [ ] **B2. Harden before exposing.** SSH key-only (`PasswordAuthentication no`), non-root deploy user, `ufw allow 22,80,443` + default deny, unattended-upgrades on. Do this before nginx binds :80.
- [ ] **B3. Install runtime.** **php8.4** via `ppa:ondrej/php` (24.04 ships only 8.3; `composer.lock` is resolved on 8.4 and requires ≥8.4.1 — versioned `php8.4-*` package names only, the PPA's unversioned `php-*` metapackages already target 8.5; allow the PPA origin in unattended-upgrades or PHP silently stops getting security patches; exit ramp: `ppa-purge` back to distro PHP on Ubuntu 26.04) + `-pgsql -mbstring -xml -curl -zip -intl -gd -bcmath`, nginx, postgresql-16, redis (optional — `database` driver for cache/queue/session is fine at one user; skip Redis), node 22 (build-only), composer. **Certbot via snap**, not apt — snap ships certbot 4.x with ACME Renewal Information (ARI) support, which matters because Let's Encrypt default lifetimes drop from 90 to 64 days in Feb 2027 and 45 in Feb 2028; ARI lets the CA drive renewal timing so nothing here hardcodes a cadence. Confirm opcache is enabled (Ubuntu default) — it's the single biggest Laravel prod lever.
- [ ] **B4. Postgres setup.** App role with a scoped password, `hep_production` DB owned by it, `listen_addresses = localhost` (never expose 5432). The cluster `TimeZone` does **not** affect the week boundary — `workout_sessions.planned_date` is a timezone-naive `date`, and `date_trunc('week', date)` is pure calendar arithmetic, so `v_weekly_completions` and `ScheduleService` agree on ISO Monday regardless of server zone. `APP_TIMEZONE` *does* matter and is already set to `America/Los_Angeles` in dev (TODOS.md Phase 6 — found when `today()` rolled over at 4-5pm Pacific); carry it into the prod `.env`.
- [ ] **B5. nginx vhost.** Root at `public/`, `try_files $uri /index.php?$query_string`, php-fpm socket, `client_max_body_size` left small for v1 (raise only when the form_check upload pipeline lands). **Use `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name` (not `$document_root`)** — with the C1 symlink layout, `$document_root` lets php-fpm's realpath cache serve the old release after a deploy. Enable HTTP/2 and gzip. Cache headers: `/build/*` gets `Cache-Control: public, max-age=31536000, immutable` (Vite hashes filenames); **`sw.js`, `manifest.webmanifest`, and HTML get `no-cache`** — stale-after-deploy is almost always cached HTML referencing dead hashed filenames, not the assets themselves.
- [ ] **B6. php-fpm pool tuning.** `pm = ondemand`, `pm.max_children` sized to RAM (~5 at 4 GB). Default `dynamic` config will fight Postgres for memory.

## C. Application deploy

- [ ] **C1. Deploy layout.** `/var/www/hep/{releases,shared,current}`; `shared/` holds `.env` + `storage/`; `current` is a symlink. Atomic swap = free rollback — but only if C2 gates the swap (below). Retain the last 3 releases; prune older ones in the deploy script or `releases/` fills the disk.
- [ ] **C2. Deploy script** (`scripts/deploy.sh`, run over SSH): fetch → `composer install --no-dev -o` → `npm ci && npm run build` → `php artisan migrate --force` → `config:cache route:cache view:cache` → symlink swap → `php-fpm reload` → `queue:restart` → prune old releases.
  **Failure semantics are part of the script, not an afterthought:** `set -euo pipefail`; the symlink swap happens only after build and migrate succeed; after the swap, `curl` the `/up` route *through nginx* and revert the symlink if it fails. Note the limit: migrations are not covered by symlink rollback — a failed deploy after `migrate --force` leaves the new schema with old code. The `php-fpm reload` step is **mandatory and load-bearing**: it's what flushes opcache + the realpath cache so fpm stops executing the old release — do not skip it when "nothing PHP changed" and do not replace it with `opcache_reset()` (which does not clear the realpath cache).
  - **Build on the box or in CI?** 4 GB is enough to build in place. If you drop to 2 GB, build the Vite bundle in GitHub Actions and rsync `public/build/` — `npm run build` is the OOM risk, not PHP (A1 must have fixed tests.yml first).
- [ ] **C3. `.env` production.** `APP_ENV=production`, `APP_DEBUG=false`, fresh `APP_KEY` (**never reuse the local one** — it decrypts sessions and cookies), `APP_URL=https://…`, `APP_TIMEZONE=America/Los_Angeles`, `SESSION_SECURE_COOKIE=true`, `LOG_LEVEL=warning`, fresh VAPID keypair (`php artisan webpush:vapid`).
- [ ] **C4. Permissions.** `storage/` and `bootstrap/cache/` owned by the php-fpm user; everything else read-only to it. `shared/.env` is the highest-value file on the box: `chmod 600`, owned by the deploy user, readable by php-fpm via group only if required.
- [ ] **C5. Config-cache trap — read `tests/bootstrap.php` first.** A cached config once pointed `RefreshDatabase` at the dev database and wiped it (2026-07-09). Production caches config by design; the guard exists so tests refuse to run against it. **Never run `php artisan test` or any `RefreshDatabase` suite on the production box.** Smoke-test with real HTTP requests instead.
- [ ] **C6. Seed once.** `HepProgramSeeder` on the empty prod DB (A5 gaps resolved or accepted first), then create Patient's user via artisan/tinker. Verify the 22 exercises + prescriptions landed before pointing anyone at it.
- [ ] **C7. Queue worker under supervisor/systemd.** `queue:work --tries=3 --max-time=3600`, `Restart=always`. Without a running worker, web-push reminders queue silently forever. Liveness monitoring is F4's heartbeat job — process supervision alone can't tell you jobs are actually processing.
- [ ] **C8. Scheduler cron.** `* * * * * cd /var/www/hep/current && php artisan schedule:run >> /dev/null 2>&1` as the deploy user. This is what fires `reminders:send` (Phase 6). **Verify it, don't trust it:** run `php artisan schedule:list` on the box to confirm `reminders:send` is registered at the expected cadence; log cron output to a file (not `/dev/null`) for the first day and confirm `schedule:run` fires; confirm `reminders.last_sent_at` advances after the first scheduled window. A typo'd crontab is invisible otherwise, and this cron is the app's entire notification pipeline.
- [ ] **C9. Close public registration.** `routes/auth.php` exposes GET/POST `/register` to guests — the Laravel starter default. Both real users are seeded/created in C6; remove or comment the register routes. Verified in D3: `/register` returns 404.

## D. TLS / Let's Encrypt

- [ ] **D1. DNS.** A record (+ AAAA if the VPS has IPv6) → server IP. Wait for propagation before requesting a cert — a failed HTTP-01 challenge counts against the rate limit.
- [ ] **D2. Issue cert.** `certbot --nginx -d hep.example.com` (add `-d www.…` only if that hostname is actually used). HTTP-01 needs :80 reachable — open it before running certbot, keep it open for renewals.
- [ ] **D3. Verify secure origin — the go-live checklist.** `https://` loads, HTTP 301s to HTTPS, no mixed content, `navigator.serviceWorker` registers against the real host, `/register` returns 404 (C9). Then re-verify push *on the real origin* — localhost subscriptions prove nothing here (see header). **Install the PWA on Patient's iPhone (Safari → Share → Add to Home Screen → open from icon → enable reminder on Dashboard) and confirm a push actually arrives — this is Phase 6's final acceptance test and exists nowhere else.** Requires C7 worker and C8 cron running first (Order below reflects this).
- [ ] **D4. Renewal.** Snap certbot installs a systemd timer with ARI support; confirm with `certbot renew --dry-run`. Add a deploy hook to reload nginx. Don't hardcode any renewal cadence — lifetimes shrink to 64 days Feb 2027, 45 days Feb 2028, and ARI adapts automatically. A silent renewal failure means the PWA stops working, and a broken service worker fails quieter than a broken page.
- [ ] **D5. HSTS** (`Strict-Transport-Security: max-age=31536000`) once you're confident. Preload only when you're sure you'll never want plain HTTP on that hostname — the preload list is effectively permanent.
- [ ] **D6. Push origin note.** VAPID subscriptions are bound to the exact origin *and keypair*. Cert or hostname change → subscriptions must be re-created; VAPID private-key loss → same (which is why F1 backs up `.env`).

## E. Media / object storage

Currently `FILESYSTEM_DISK=local` serving stock video URLs (wger.de + Wikimedia) and generated SVG thumbs from `public/thumbs/`. **v1 needs no object storage at all** — stock media is remote URLs, thumbs are static assets. Do the swap when the form_check pipeline starts. One consequence worth knowing: stock videos hotlink third-party hosts — playback latency is theirs, offline sessions can't play video, and a health app's page loads ping wger.de/Wikimedia (small privacy leak). Acceptable for v1; the R2 move fixes all three.

- [ ] **E1. Confirm the deferral.** Wiring S3 now buys nothing for v1. Only start here if you want the plumbing proven before recording videos.
- [ ] **E2. Pick host.** Cloudflare R2 (zero egress confirmed 2026; $0.015/GB-mo storage, 10 GB free tier — a single-user HEP library rounds to $0) over Bunny Storage or AWS S3.
- [ ] **E3. Install the driver.** `composer require league/flysystem-aws-s3-v3` (pulls `aws/aws-sdk-php`). Neither is installed — `vendor/league/` holds only `flysystem` and `flysystem-local`, and the lock-file mentions of the S3 adapter live inside laravel/framework's own `require-dev`/`suggest` blocks, not as installed packages. So `config/filesystems.php`'s `s3` disk is a config block with no driver behind it: the first `Storage::disk('s3')` call throws `Error: Class "Aws\S3\S3Client" not found` from `FilesystemManager::createS3Driver()`. That happens during driver *construction*, so the disk's `'throw' => false` flag does not catch it — set `FILESYSTEM_DISK=s3` without this package and the app dies on first storage access. This is the only code change in the whole swap.
- [ ] **E4. Env.** `FILESYSTEM_DISK=s3`, `AWS_*` (R2: `AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com`, `AWS_DEFAULT_REGION=auto`, `AWS_USE_PATH_STYLE_ENDPOINT=true`). Scoped API token, not an account-wide key.
- [ ] **E5. Private by default.** `exercise_media.kind = 'form_check'` is video of a person exercising — the only sensitive data in the app. Bucket blocks public reads; serve via time-limited `temporaryUrl()`. Do not "make it public for now."
- [ ] **E6. Migrate `public/thumbs/`** to the disk, or leave it — 22 SVGs served by nginx is not a problem worth solving.

## F. Operations

- [ ] **F1. Nightly backup, alerted.** `pg_dump | gzip |` upload — pipe straight off-box (R2 bucket or `rsync` elsewhere); don't stage dumps on the local disk. A backup on the same VPS is not a backup. 30-day retention. **Ping a healthchecks.io check only after a successful dump + upload with a minimum-size sanity check** — a cron whose credentials expired or whose disk filled fails silently for weeks otherwise (the same silent-cron failure D4 guards against for certs). **Also back up `shared/.env`, encrypted** (age/gpg or a password-manager entry): it holds the only copies of the prod `APP_KEY` and the VAPID private key — lose the box without it and every push subscription is permanently dead (D6) even after the database restores.
- [ ] **F2. Test the restore.** Restore the dump into a scratch DB and check the exercise count. An untested backup is a hypothesis, and this is the one irreplaceable dataset — the append-only `set_events` log is Patient's actual history and cannot be regenerated from anything.
- [ ] **F3. Log rotation.** `logrotate` on `storage/logs/laravel.log`. `LOG_LEVEL=warning` in prod.
- [ ] **F4. Monitoring that watches the actual failure modes (pre-v1, not post).** Laravel's `/up` is a liveness probe only — it proves nginx + php-fpm boot the framework and checks *nothing* about DB, queue, or cron. The two failures this plan calls silent are invisible to it. Three healthchecks.io checks (free tier):
  1. **Cron liveness:** the scheduler pings a check from `schedule:run` (e.g. a scheduled `Http::get` every 15 min) — dead cron pages within the hour.
  2. **Worker liveness:** a scheduled job that *dispatches a queued heartbeat job* which pings a second check — proves the queue is actually processing, not just that systemd shows a process.
  3. **Disk:** include a `df` threshold check in the cron ping script — a full disk PANICs Postgres and takes the irreplaceable `set_events` log down with the app.
  Keep the plain UptimeRobot `/up` check too — it catches the web tier. F1's backup ping is the fourth check.
- [ ] **F5. Error tracking.** Sentry free tier, or accept tailing the log. At one user, the log is defensible.
- [ ] **F6. Push-delivery gap** (flagged in `stack-decisions.md` §Failure modes): iOS silently drops subscriptions — documented through iOS 18, no fix shipped, and client-side `pushsubscriptionchange` is unreliable. Two server-side mitigations: **wire the webpush channel's report hook to delete subscription rows on `410 Gone`/`404`** (per RFC 8030, that endpoint is permanently dead), and **re-sync the subscription on app launch** (compare the live `pushManager.getSubscription()` against the stored row, re-subscribe if missing) rather than subscribing once at toggle time. Surface `reminders.last_sent_at` somewhere visible so a dead subscription isn't invisible for weeks.

---

## Order

**A → B → C1–C6, C9 → C7–C8 → D → F1–F2, F4.** E and the rest of F are post-v1.

**Sequencing (resolved 2026-07-10 via /plan-eng-review):** The "deploy before or after Phase 6?" question mooted itself — TODOS.md marks Phase 6 DONE 2026-07-09, built and tested against localhost's secure-context exemption (the interleave path, arrived at de facto). Deploy is next. The one remaining Phase 6 item is exactly what only deploy unblocks: D3's installed-PWA push test on Patient's iPhone, which is why C7 (worker) and C8 (cron) now precede D — push delivery cannot be confirmed without them running.

## Post-v1 note — service worker vs deploys

`public/sw.js` cache-first-caches `/build/` assets but never prunes old entries, so every deploy grows client CacheStorage forever; and a SW active mid-deploy can serve HTML referencing dead hashed filenames. B5's cache headers (HTML/sw.js no-cache, /build/ immutable) prevent the breakage; cache pruning in the SW `activate` handler (or moving to vite-plugin-pwa's generated manifest) is cleanup for a Phase 6 follow-up. Also worth evaluating then: **Declarative Web Push** (Safari/iOS 18.4+) — JSON-payload pushes the OS displays without waking the SW, the most reliable delivery path for an iOS-primary user.

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 0 | — | — |
| Codex Review | `/codex review` | Independent 2nd opinion | 0 | — | — |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 1 | CLEAR (PLAN) | 24 issues confirmed (1 P1), 13 refuted by adversarial verification, 0 critical gaps remaining; all 10 decisions resolved (all recommended options accepted 2026-07-10) |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | — | — |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | — | — |

- **CROSS-MODEL:** Outside voice (fresh-context subagent; codex unavailable) raised 7 items: 4 overlapped the review (moot sequencing, /register, secrets backup, /up blindness), 3 were new — PT data gaps vs immutable prescriptions (adopted as A5), managed hosting vs artisanal server (declined, standing decision upheld), tunnel option for iOS testing (superseded by deploy-now resolution).
- **VERDICT:** ENG CLEARED — ready to implement. Deploy is the next phase; execute in §Order sequence.

NO UNRESOLVED DECISIONS

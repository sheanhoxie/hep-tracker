# TODOS

## Roadmap: scaffold → fully functional

Ordered path. Each phase is shippable/testable on its own. Matches v1 sequencing in stack-decisions.md.

### Phase 1 — Real data — DONE 2026-07-09
- [x] Transcribe the real the PT HEP into the seeder — done 2026-07-08 from `program-source-document` Appendix A (22 exercises → `database/seeders/data/hep-exercises.json`)
- [x] Patient's aliases + stock cues seeded
- [x] Stock video URLs seeded as placeholders (2026-07-09): wger.de (CC-BY-SA) + Wikimedia Commons (CC-BY) direct URLs in `database/seeders/data/hep-media.json`, mapped by exercise slug; SVG pictogram thumbnails generated to `public/thumbs/{slug}.svg`
- [ ] DEFERRED: PT verification of VERIFY/DATA GAP items with the PT (spec §5, preserved in `prescriptions.notes`): exercise-q steps per direction, hip-abduction 'up to 20' conflict, exercise-r sets, dosages for squat-w/-ball, bridge-walk, exercise-s, exercise-o, exercise-p; confirm inferred forms (balance swing, toe yoga, heel tap) — user chose to skip and update hardcoded assets later

### Phase 2 — Scheduler (what's due today) — DONE 2026-07-09
- [x] `ScheduleService::dueOn()` — pure read over prescriptions + completion views (app/Services/)
- [x] Week boundary DECIDED: ISO Monday (spec §5 — matches journal grid and `date_trunc('week')`)
- [x] `GET /today` + `Today.vue` (daily / this-week sections with dosage + remaining count + thumbs)
- [x] Pest tests: fresh week, done-today, weekly countdown, Monday reset, pre-effective date, page render
- [x] "Today" link + Dashboard + Library links added to app sidebar nav (AppSidebar.vue: 3 nav items)
- [x] Playwright e2e tests: sidenav buttons visible on all pages, page content changes on navigation

### Phase 3 — Session runtime (server side) — DONE 2026-07-08
- [x] `SessionService::startOrResume()` — materializes queue from due list; `expandSetQueue()` derives per-set queue (per_side doubles, alternating stays single/side-NULL)
- [x] Endpoints: POST /sessions (start/resume), PATCH session (done/abandoned), PATCH item (status/skip_reason), PUT items-order, POST set-events + observations (both idempotent via `client_key` createOrFirst; accept `occurred_at` for offline replay)
- [x] Resume: open same-day session returned by startOrResume; Today shows Resume button
- [x] Interim online-only Session.vue (log sets, skip, complete) — replaced by the offline island in Phase 4
- [x] 13 Pest tests (materialize, resume, drop-done-items, dedupe, authz, expansion)

### Phase 4 — Session player island (client) — DONE 2026-07-09
- [x] Player rewrite of `Session.vue`: one exercise at a time (video + cues + steps + dosage + set buttons), progress strip with jump navigation, prev/next, Done/Skip auto-advance, completion panel
- [x] Rest timer recomputes remaining from a target timestamp — display-only interval, no tick accumulation (binding rule 3)
- [x] IndexedDB write queue (`resources/js/lib/offline-queue.ts`): queue-first writes with `client_key` UUIDs + `occurred_at`, single-flight drain with rerun + 3s retry ticker, replay on mount/online; poison 4xx dropped loudly, transient errors retried. UI reads server props merged with the queue (optimistic offline state)
- [x] Playwright (`tests/e2e/player.spec.ts`): full happy path (log all sets → done every item → complete → survives reload); offline-mid-session queue + replay-on-reconnect test
- [x] Test-env guard: `tests/bootstrap.php` refuses to run Pest when config is cached (cached env=local silently pointed RefreshDatabase at the dev DB and wiped it once — 2026-07-09)

### Phase 5 — History & adherence UI — DONE 2026-07-09
- [x] Dashboard: due-today card (link to today), weekly adherence bar (done/target), streak counter; recent sessions list (5 most recent + item counts + status chips) — done 2026-07-09
- [x] Library: 22-exercise grid with alias, region/frequency chips, dosage, cues, notes, clickable thumbnail + video; header licensing note — done 2026-07-09
- [x] Pest tests: dashboard stats, library all 22 exercises with media
- [x] Pain/RPE/note capture on the player completion panel — queued offline-safe to POST observations (session grain)
- [x] History page (`/history` + sidebar link): paginated session list with done counts, status, pain/RPE/note chips; links to session detail, which now shows session-grain observations
- [x] Pain trend sparkline on Dashboard (inline SVG, last 30 pain observations, computed in DashboardController — never stored)
- [x] Tests: Pest (history props + pain series), Playwright (complete session with pain/RPE/note → chips on done banner, after reload, and in history)

### Phase 6 — PWA + reminders — DONE 2026-07-09
- [x] PWA shell: `public/manifest.webmanifest` (start_url /today, standalone) + GD-generated icons (`public/icons/`) + apple meta tags in `app.blade.php`; hand-written `public/sw.js` (no workbox) registered from `app.ts` — cache-first for `/build/`, `/thumbs/`, `/icons/` (content-hashed/immutable), push + notificationclick (opens `/today`)
- [x] Web-push: `laravel-notification-channels/webpush` (VAPID keys in `.env` via `webpush:vapid`; `VAPID_SUBJECT` set); `push_subscriptions` table; POST/DELETE `/push-subscriptions`; `HasPushSubscriptions` on User
- [x] `reminders:send` (scheduler every 15 min, `routes/console.php`): enabled daily_nudge reminders past their `schedule` time, not yet sent today → `ScheduleService::dueOn()` count (derived, never stored) → `DailyNudge` push if work remains, stamps `last_sent_at`. Seeder creates Patient's daily_nudge @ 17:00
- [x] Dashboard "Daily reminder" card: enable toggle (permission → subscribe → store) + time picker (`PUT /reminder-settings`, updateOrCreate on kind)
- [x] Tests: Pest (subscription upsert/delete, settings update, send-once-per-day, quiet before schedule time, disabled skip) — 66 passed; Playwright still 4 passed with SW registered
- [x] iOS caveat for Patient: push needs iOS 16.4+ AND the app installed to the home screen first (Safari → Share → "Add to Home Screen", open from the icon, then enable the reminder on Dashboard). Desktop browsers work without install.
- [x] App timezone → `America/Los_Angeles` (env `APP_TIMEZONE`): was UTC, so `today()`/planned_date/due-today/reminder times rolled over at 4-5pm Pacific — found smoke-testing `reminders:send`
- [ ] Production needs: real-origin HTTPS + scheduler cron — tracked in DEPLOY.md (D3 is this phase's final acceptance test: installed-PWA push on Patient's iPhone)

### Phase 7 — Deploy
- [ ] Execute `DEPLOY.md` — owns all deploy tasks (A–F + Order). Reviewed /plan-eng-review 2026-07-10; sequencing resolved: deploy now, C7/C8 before D3.

### Post-v1 (detailed below)
- [ ] Program-revision interface (`program:revise`)
- [ ] form_check video pipeline

## form_check video pipeline

- **What:** Self-recorded form-check videos — mobile camera capture, chunked/resumable upload, HEVC→H.264/HLS transcode, signed-URL access control.
- **Why:** The sleeper feature: Patient's own correct form playing next to the stock video in the session player. Also the app's only sensitive data (video of a person exercising) — needs ACL, not bare URLs.
- **Pros:** High product value; schema already supports it (`exercise_media.kind = 'form_check'`).
- **Cons:** Hardest technical item in the app — PHP upload limits, transcoding (ffmpeg queue job or Bunny Stream), storage privacy. Weeks of edge cases if done fully.
- **Context:** v1 ships stock-video playback only; recording/upload is deferred. Start point: swap Flysystem to S3-compatible host (R2 or Bunny), add chunked upload (e.g. tus or resumable-js), transcode via queued ffmpeg job, serve via signed URLs. Added 2026-07-08 from /plan-eng-review outside-voice finding.
- **Depends on / blocked by:** Media storage swap to external host; v1 player shipped.

## Program-revision interface

- **What:** Tooling for "the PT revised the program": create new `program_version`, copy-forward prescription rows, modify, set `effective_from`, close prior version. Artisan command first (`program:revise`), UI later.
- **Why:** The immutable-versioning core of the data model has no interface — until this exists, a revision means hand-written SQL.
- **Pros:** Makes the versioning design usable; copy-forward is the one non-trivial write path in the app.
- **Cons:** Not needed until the program actually changes; premature before then.
- **Context:** v1 seeds the initial (sample date) program via seeder. When building this, settle the open decision from hep-data-model.md: does a new version carry over the current week's already-earned weekly completions (doc leans carry-over, matched on exercise_id). Added 2026-07-08 from /plan-eng-review.
- **Depends on / blocked by:** Core models + seeder existing.

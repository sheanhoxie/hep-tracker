# CONTENT-ADMIN — static → DB-managed content (admin CRUD)

Status: PLAN — reviewed via /plan-eng-review 2026-07-10/11, all 12 review decisions resolved (see GSTACK REVIEW REPORT at end).
Scope: admin/manager CRUD for exercises + video upload + cues/form notes + dosage editing via program-revision engine. Patient (patient role) read-only. form_check pipeline stays deferred (TODOS).

## Problem

Content shipped to prod via one-time seed (DEPLOY.md C6) and re-seeding is forbidden since Patient's first real set_event (A5, 2026-07-10). Today there is NO correction path for:
- placeholder demo videos (wger/Wikimedia hotlinks, several exercises share one generic squat video)
- PT dosage gaps preserved in `prescriptions.notes` (spec §5 VERIFY items)
- steps / cues / names as the PT verifies them

The seeder is `firstOrCreate` create-only — JSON edits never propagate. The DB is already the source of truth; it just has no write surface.

## Decisions locked in review (2026-07-11)

| # | Decision |
|---|----------|
| 1A | Completions carry over on revision at BOTH grains (daily + weekly), matched on exercise_id, deduped by date. Views recreated with exercise_id + user_id. |
| 2A | `effective_from` is always today — no date picker, no future-dating, no backdating. Same-day re-revision allowed (superseded version gets an empty window = "never ran"). |
| 3A | Optimistic concurrency: save posts `base_version_no`, 409 on mismatch. Transaction locks the `programs` row (parent mutex), null-checks current version. |
| 4A | hep-backup.sh extended to rsync `shared/storage/app/public` to R2; restore drill verifies one media file resolves. |
| 5A | sw.js caches `/storage/exercise-media/` **images only** (destination/extension check), videos excluded; CACHE version bumped. |
| 6A | `movement_pattern` / `body_region` validate as `nullable|string|max` (prod data has 17 distinct patterns; the 5-value comment is fiction). `laterality` stays strict `Rule::in` — it drives `expandSetQueue`. |
| 7A | `updateOrCreate` for media writes (Laravel `upsert()` throws 42P10 against a partial unique index). `Prescription::DOSAGE_COLUMNS` const feeds grid props, validation rules, and copy logic. One shared inline rules array per repo convention — no FormRequest pair. |
| 8A | Thumb chain is `image media row → null`; Vue renders a placeholder for null. No `/thumbs` file_exists middle step (seeded rows already cover the 22 originals). |
| 9A | Replace/delete removes old local file immediately; a stale open session 404ing its video is ACCEPTED (reload recovers, logging unaffected). |
| T-A | e2e: tinker-style beforeAll admin fixture + committed ~200KB video fixture + afterAll cleanup (delete created exercise, revert version). |
| P-A | Media eager-load lives at `ScheduleService::dueOn` (`with('exercise.media')`); `Model::preventLazyLoading(!production)` added to AppServiceProvider. |
| C-A | Deploy checklist corrected: storage:link is verify-only (deploy.sh:41 already pre-swap); Cache-Control immutable on /storage; nginx edits are MANUAL (certbot-managed vhost); duplicate pre-check dropped from migration. |
| OV-A | Outside-voice carry-over corrections: view changes are ADDITIVE-ONLY (grain unchanged — old code keeps working through the pre-swap migrate window); weekly cross-version dedupe computed from the daily view (date grain); DashboardController added as carry-over consumer + regression test; view SQL specified in W5; 409 → friendly Inertia error + reload button; session-under-superseded-version semantics documented + tested. |
| L-A | `laterality` frozen while the exercise is prescribed in the current version (expandSetQueue reads it at materialization — an edit would be an unversioned dosage change). Changing it = revise (remove → edit → re-add). |
| U-A | Browser upload cap 100MB (`client_max_body_size 120m`, php limits to match, no fastcgi timeout surgery). Bigger files: compress, URL-paste, or scp to shared/storage (documented out-of-band path). |
| H-A | Content mutability (design rule 1) stands — no content versioning. Every admin content edit emits one structured `Log::info` (actor, exercise, changed fields, old→new) as the audit trail. |

## Layer map (design rules 1–2 preserved)

```
MUTABLE CONTENT            IMMUTABLE-PER-VERSION           APPEND-ONLY
┌──────────────────┐       ┌───────────────────────┐       ┌──────────────┐
│ exercises        │       │ programs              │       │ set_events   │
│ exercise_cues    │  CRUD │  └ program_versions   │ COPY→ │ observations │
│ exercise_media   │ ◄───  │      └ prescriptions  │ NEW   │ (never       │
│ exercise_aliases │ admin │  (never UPDATE rows;  │ VER   │  touched)    │
└──────────────────┘       │   revise = new ver +  │       └──────────────┘
                           │   copied rows)        │
                           └───────────────────────┘
```

## Workstreams

### W1 — Authorization (role gate)

- `Gate::define('manage-content', fn (User $u): bool => in_array($u->role, ['practitioner', 'admin'], true))` in `AppServiceProvider::boot()`.
- Admin route group: `Route::middleware(['auth', 'verified', 'can:manage-content'])->prefix('admin')`.
- Sidebar admin section rendered only for practitioner/admin (`auth.user.role` already shared via `HandleInertiaRequests`).
- `user:role {email} {role}` artisan command (validates the three roles). Account creation stays tinker per C9.
- Patient → `/admin/*` = 403.

### W2 — Exercise CRUD

```
GET    /admin/exercises                      index
GET    /admin/exercises/create               create
POST   /admin/exercises                      store
GET    /admin/exercises/{exercise}/edit      edit    (binds by slug)
PATCH  /admin/exercises/{exercise}           update
DELETE /admin/exercises/{exercise}           destroy
```

- Fields: canonical_name, description, steps[] (form notes), body_region, movement_pattern, laterality, equipment[].
- Slug: `Str::slug(canonical_name)` on create, collision suffix `-2`, `-3`; **immutable after create** (media paths + e2e reference it) — update never touches it.
- Cues inline on the form (stock cues, `user_id = null`): nested payload sync; `sort_order` from array index. **Sync scopes `whereNull('user_id')`** — patient-owned cues untouched.
- Validation (decision 6A/7A): ONE shared inline rules array used by store and update (repo convention — inline `$request->validate()`): laterality `Rule::in(bilateral|unilateral|alternating)`; body_region/movement_pattern `nullable|string|max:100`; steps/equipment arrays of strings.
- Laterality freeze (L-A): update rejects a laterality change while the exercise is prescribed in the current program version — changing it requires a revision (remove → edit → re-add). Guard + friendly error + test.
- Audit (H-A): every store/update/destroy (exercises, cues, media, revisions) emits one structured `Log::info('content.edit', [...])` — actor, target, changed fields, old→new.
- Destroy guard: friendly flash error if `$exercise->prescriptions()->exists()` (restrictOnDelete backstop). Unprescribed delete cascades media/cues/aliases + deletes locally-hosted files.
- UI copy warns: laterality changes affect future session expansion only.

### W3 — Media upload (videos + thumbs)

Storage: local Flysystem `public` disk (`storage/app/public/exercise-media/{slug}/…`), served via existing `/storage` symlink (deploy.sh:41 creates it pre-swap — verified). Demo videos are not sensitive; public serving correct (E5 privacy applies to form_check only, deferred).

```
admin ──POST /admin/exercises/{ex}/media (multipart OR url)──►
  validate: url XOR file (both → 422, neither → 422)
    kind=video: mp4/webm/mov ≤ 100MB (U-A) · kind=image: svg/png/jpg ≤ 2MB
    (bigger source files: compress locally, URL-paste, or scp into shared/storage — documented)
  ├► file path: Storage::disk('public')->putFile("exercise-media/{slug}", file)
  ├► ExerciseMedia::updateOrCreate([exercise_id, kind], [url, uploaded_by, …])   ← decision 7A, never upsert()
  └► old row pointed at local /storage path → Storage::delete(old) best-effort
       (applies to BOTH upload-replace and URL-paste-over-local — no orphan files)
DELETE /admin/exercises/{ex}/media/{media} → remove row + local file (external URL rows: no delete attempt)
```

- One stock row per (exercise_id, kind) — enforced by W5 partial unique index; replace-on-upload semantics.
- Player/Library/Session controllers unchanged — they already read `exercise_media` by kind.
- Accepted (9A): stale open session may 404 its video after a replace; reload recovers.

### W4 — Program revision engine (dosage editing)

`App\Services\ProgramRevisionService`:

```
revise(Program $p, int $baseVersionNo, ?string $sourceNote, array $rows): ProgramVersion

DB::transaction:
  ┌ Program::whereKey($p->id)->lockForUpdate()->first()      ← parent mutex (3A)
  ├ $current = $p->versions()->whereNull('effective_to')->first()
  │    abort 500 if null (invariant breach — never proceed)
  ├ abort 409 if $current->version_no !== $baseVersionNo     ← stale grid (3A)
  │    "program was revised since you loaded this page — reload"
  ├ $today = CarbonImmutable::today()                        ← effective_from ALWAYS today (2A)
  ├ close old: effective_to = $today->subDay()
  │    (same-day re-revision ⇒ old window empty = "never ran"; version_no DESC backstop)
  ├ create program_versions row (version_no = $current->version_no + 1,
  │    effective_from = $today, source_note)
  └ insert prescriptions from $rows using Prescription::DOSAGE_COLUMNS + exercise_id (7A)
       edit = copy w/ new values · keep = verbatim copy · remove = omit · add = new row
```

Invariants (tested): exactly one `effective_to IS NULL` per program · old rows byte-identical · sessions keep old version ids · version_no strictly increasing · unique(program_id, version_no) as backstop · dosage stays typed columns.

UI: `GET /admin/program` — grid of current version's prescriptions, editable dosage cells (from `DOSAGE_COLUMNS`), add-exercise row, remove toggle, `source_note` field, hidden `base_version_no`. **One save = one new version.** No date picker (2A).

Carry-over (1A + OV-A — settles the hep-data-model.md open decision): completions match on **exercise_id across all of the program's versions**, deduped by calendar date, at both grains. Mechanism: daily grain reads `v_daily_completions` (has date grain already) joined to prescriptions → exercise_id; weekly counts are computed FROM the daily view (`COUNT(DISTINCT planned_date)` per exercise per ISO week) so cross-version same-day work counts once. `v_weekly_completions` keeps its exact current shape for untouched consumers. Consumers updated: `ScheduleService::dueOn()` AND `DashboardController` (weekly ring — the missed consumer). Fixed query count, pure-read, no synthetic rows.

409 handling (3A + OV-A): the revise endpoint returns the conflict as an Inertia-friendly validation error (`back()->withErrors(['base_version' => 'program was revised since you loaded this page'])`); the grid shows the message + a reload button — never a raw 409 modal. Tested server- and client-side.

History semantics (2A + OV-A): a session's `program_version_id` FK is authoritative for history/attribution; date windows drive scheduling only. A superseded same-day version with an empty window may still own sessions/set_events — correct and tested (session completed between two same-day revisions).

### W5 — Hardening migration + consumers

One migration:
- Partial unique index `exercise_media (exercise_id, kind) WHERE kind <> 'form_check'` — no pre-check query (C-A: seeder firstOrCreate keys on exactly these columns; CREATE UNIQUE INDEX is atomic and runs pre-swap).
- `timestampsTz` on `exercise_media` (audit for runtime edits).
- Index on `workout_session_items (prescription_id)` (FK had none; carry-over joins need it).
- View change is **ADDITIVE-ONLY** (OV-A — old code keeps serving during the pre-swap migrate window, and an aborted deploy leaves old code on the new views): `v_daily_completions` gains `exercise_id` via a prescriptions join, existing columns and grain unchanged; `v_weekly_completions` is recreated byte-compatible (same columns, same grain) — new weekly carry-over logic reads the daily view instead. Sketch:

  ```sql
  -- as implemented (2026_07_13_000001): column APPENDED (CREATE OR REPLACE requires it),
  -- no status filter — the original view exposes status raw and consumers filter
  CREATE OR REPLACE VIEW v_daily_completions AS
  SELECT s.user_id, s.planned_date, si.prescription_id, si.status, p.exercise_id
  FROM workout_sessions s
  JOIN workout_session_items si ON si.workout_session_id = s.id
  JOIN prescriptions p ON p.id = si.prescription_id;
  ```

Consumers:
- `DueItem`: thumb = image media row URL or **null** (8A — no /thumbs middle step); Today/Session Vue render placeholder on null.
- `ScheduleService::dueOn` line 30 → `with('exercise.media')` (P-A — the verified single seam); DueItem reads the in-memory collection.
- `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider::boot()` (P-A).
- `public/sw.js`: cache `/storage/exercise-media/` image requests only (destination/extension check), videos excluded; bump `CACHE` const (5A).
- Seeder: unchanged, bootstrap-only for empty DBs; header comment states DB is source of truth after first boot.

Inline ASCII diagram comments to add during implementation: `ProgramRevisionService` (the transaction pipeline above), `ScheduleService::dueOn` (cross-version carry-over read), `sw.js` (cache decision tree).

### W6 — Tests

Pest (conventions: `beforeEach` seeds HepProgramSeeder; admin = `User::factory()->create(['role' => 'admin'])`):

- **Authz**: guest → login; patient → 403 on every admin route; practitioner + admin → 200; `user:role` promotes / rejects bad role.
- **Exercise CRUD**: create w/ slug generation + collision; **update with renamed canonical_name keeps slug** (media paths depend on it); steps/equipment array validation; laterality `Rule::in` rejection + free-text pattern/region accepted (dataset per field); cue add/reorder/delete; **cue sync leaves a pre-inserted user-owned cue untouched**; destroy blocked when prescribed (row survives, friendly error); destroy unprescribed cascades + deletes local files (Storage::fake).
- **Media**: upload video (mime/size); replace updates row — no duplicate (unique index exercised); old local file deleted on upload-replace AND URL-paste-over-local; external URL overwritten w/o delete attempt; DELETE on external-URL row attempts no local delete; **url XOR file: both → 422, neither → 422**; image upload; oversize/wrong-mime rejected.
- **Revision engine**: revise creates v(n+1) w/ copied rows; old closed at today−1; old rows byte-identical; exactly one NULL effective_to; **stale `base_version_no` → 409, no version minted, no rows written**; **same-day re-revision: v(n) empty-windowed, v(n+1) current, dueOn(today) resolves v(n+1)**; sessions keep old version ids; add/remove/edit rows land; `DOSAGE_COLUMNS` copy verbatim (assert every column); double-revise sequential with correct bases → v2 then v3. (True two-connection lock race not testable under Pest's transaction-wrapped connection — documented; parent-lock code reviewed, unique constraint backstops.)
- **Carry-over (both grains, 1A)**: daily item done 9am + revise 2pm → NOT due, POST /sessions does not re-materialize (no second session); per_week item's earned days survive mid-week revision; revision-day dedupe (done under v_old + v_new same day counts once); Monday reset still resets.
- **Consumers (REGRESSION — mandatory)**: Today thumb VALUE from uploaded image row; null thumb for exercise without image; Library + Session render after view migration; **Dashboard weekly ring survives a mid-week revision (OV-A — the missed consumer)**; seeder idempotency still passes against the new unique index; query-count assertion on Today (preventLazyLoading enforces).
- **Laterality freeze (L-A)**: update with changed laterality on a prescribed exercise → 422 + row unchanged; unprescribed exercise → allowed.
- **Session under superseded version (OV-A)**: revise 8am → session completed 9am → re-revise 2pm: counters correct (carry-over), history attributes the session to the empty-windowed version via FK.
- **409 UX (OV-A)**: stale save → validation error rendered in grid + reload path (no raw modal).

Playwright (`tests/e2e/admin.spec.ts`, T-A): beforeAll tinker firstOrCreate admin user; committed `tests/e2e/fixtures/demo.mp4` (~200KB); admin logs in → creates exercise → uploads fixture video → adds to program with dosage → new version live → exercise in Patient's Library + Today; patient sees no admin nav, direct /admin → 403; afterAll deletes created exercise + reverts version. Offline spec addition: thumbs still render offline post-SW change.

Vue: upload form treats any non-Inertia error response (nginx 413 HTML) as "upload failed — file may be too large" — covered by the prod smoke test below.

## NOT in scope (explicit deferrals)

- form_check recording/upload pipeline (chunked upload, transcode, signed URLs, R2) — TODOS, unchanged
- R2/S3 storage swap — local public disk until it hurts (stack-decisions); backups now cover it (4A)
- Future-dated / staged revisions (2A: effective_from always today; revisit if the PT ever hands over a dated program)
- Patient-facing content features (alias editing UI)
- Multi-program / multi-patient admin UI (tables support it; UI targets the single program)
- `program:revise` CLI wrapper (service is the engine; 20-line follow-up if wanted)
- Video transcoding / thumbnail extraction — stored as uploaded

## What already exists (reused, not rebuilt)

- Content models + relationships — mutable by design rule 1; zero schema work for CRUD happy path
- `ScheduleService::currentVersion()` — version resolution unchanged
- Library/Session controllers already read media by kind — zero changes for uploaded videos
- `useForm` Vue patterns (settings pages) — admin forms copy them
- UserFactory + role column — authz needs no schema work
- deploy.sh storage:link (already pre-swap), rclone/R2 backup plumbing, healthchecks — extended, not rebuilt
- Seeder — stays as empty-DB bootstrap

## Failure modes

| Codepath | Realistic failure | Handling |
|---|---|---|
| Upload | oversized → nginx 413 (non-Inertia HTML) | Vue form surfaces friendly error; prod smoke test; manual vhost limits |
| Upload | disk full / <1GB headroom (~3× transient cost per max upload) | headroom check in checklist; hep-disk alarm at 90% |
| Replace media | old-file delete fails after new write | write-new → update-row → delete-old best-effort (log warning) |
| Replace media | open session still references old URL | ACCEPTED (9A): video 404s, reload recovers, logging unaffected |
| Revise | stale grid (two tabs/devices) | base_version_no → 409 + reload prompt (3A); test |
| Revise | concurrent submits | programs-row lock + null-check + unique constraint (3A); sequential test + reviewed lock |
| Revise | revision itself is wrong | same-day re-revision allowed (2A) — empty-window supersede; test |
| Revise mid-day/week | completions reset | carry-over both grains on exercise_id (1A); tests |
| Exercise destroy | prescribed exercise | guard + friendly flash (restrictOnDelete backstop) |
| DueItem | exercise with no image row | null → Vue placeholder (8A); test |
| Deploy | storage:link | already pre-swap in deploy.sh:41 — verify only (C-A) |
| Box loss | uploaded media gone | nightly rsync to R2 + restore-drill media check (4A) |

## Deploy checklist (first release of this feature)

1. **Manual** edits to the live certbot-managed vhost (provision.sh won't rewrite it — C-A): `client_max_body_size 120m;` (U-A) on the admin media location; `location /storage/ { add_header Cache-Control "public, max-age=31536000, immutable"; }`. No fastcgi timeout changes needed at 100MB. Then `nginx -t && systemctl reload nginx`.
2. php.ini: `upload_max_filesize=100M`, `post_max_size=110M`.
3. Verify ~500MB free disk headroom before enabling uploads (`df -h /`).
4. `php artisan migrate` (unique index + timestamps + wsi index + view recreate) — runs pre-swap, atomic.
5. hep-backup.sh: add rsync/rclone of `shared/storage/app/public` to the R2 bucket; extend hep-restore-test.sh to verify one media file (4A).
6. Promote manager account: create via tinker (C9 pattern) then `php artisan user:role <email> practitioner`.
7. Prod smoke: upload ~80MB through the real vhost → success; >120MB attempt → friendly too-large error (not raw 413 page). One `/storage` URL returns the immutable cache header.
8. route:cache rebuild — already in deploy.sh.

## Worktree parallelization

| Step | Modules touched | Depends on |
|------|----------------|------------|
| S1 migration (index/timestamps/views) | database/migrations | — |
| S2 revision service + carry-over + DueItem/ScheduleService | app/Services, app/Models | S1 |
| S3 authz gate + user:role + routes | app/Providers, app/Console, routes | — |
| S4 admin controllers + Vue pages | app/Http/Controllers/Admin, resources/js/pages/admin | S1–S3 |
| S5 sw.js image caching | public/ | — |
| S6 backup extension | deploy/ | — |
| S7 tests | tests/ | lands with each lane |

Lanes: **A**: S1 → S2 → S4 (sequential, shared services + routes) · **B**: S3 (merge before S4) · **C**: S5 (independent) · **D**: S6 (independent). Launch B, C, D in parallel worktrees alongside A's S1/S2; S4 waits on A+B. Conflict flag: S3 and S4 both touch `routes/` — land S3 first.

## Implementation Tasks

Synthesized from review findings. Checkbox as you ship.

- [x] **T1 (P1, human: ~1d / CC: ~20min)** — database — hardening migration: partial unique index on exercise_media, timestampsTz, wsi(prescription_id) index, recreate both views w/ exercise_id + user_id
  - Surfaced by: Architecture #1, Code Quality #7 · Files: database/migrations/*, app/Models/ExerciseMedia.php · Verify: `php artisan test` (seeder idempotency + view-consumer tests)
- [x] **T2 (P1, human: ~2d / CC: ~30min)** — services — ProgramRevisionService: parent lock, base_version_no 409, effective_from=today, same-day empty-window, DOSAGE_COLUMNS copy
  - Surfaced by: Architecture #2/#3, Code Quality #7 · Files: app/Services/ProgramRevisionService.php, app/Models/Prescription.php · Verify: revision Pest suite
- [x] **T3 (P1, human: ~1d / CC: ~20min)** — scheduler — carry-over both grains via daily view on exercise_id + Dashboard weekly ring + with('exercise.media') + DueItem null thumb + preventLazyLoading
  - Surfaced by: Architecture #1, Perf #10, Quality #8, Outside voice #1/#2 · Files: app/Services/ScheduleService.php, app/Services/DueItem.php, app/Http/Controllers/DashboardController.php, app/Providers/AppServiceProvider.php · Verify: carry-over + Dashboard regression + query-count tests
- [x] **T4 (P1, human: ~half-day / CC: ~10min)** — authz — manage-content Gate, admin route group, user:role command, sidebar gating
  - Surfaced by: W1 (role column unenforced) · Files: app/Providers/AppServiceProvider.php, routes/web.php, app/Console/Commands/UserRole.php, resources/js/components/AppSidebar.vue · Verify: authz Pest suite
- [x] **T5 (P2, human: ~2d / CC: ~40min)** — admin UI — ExerciseController CRUD + cue sync (user_id NULL scoped) + shared inline rules + Vue pages
  - Surfaced by: W2, Quality #6 (free-text validation) · Files: app/Http/Controllers/Admin/*, resources/js/pages/admin/* · Verify: CRUD Pest suite
- [x] **T6 (P2, human: ~1d / CC: ~30min)** — media — upload/URL endpoint: XOR validation, updateOrCreate, old-file cleanup both paths, 413-friendly Vue error
  - Surfaced by: Quality #7a, Tests (XOR gaps) · Files: app/Http/Controllers/Admin/ExerciseMediaController.php, resources/js/pages/admin/* · Verify: media Pest suite
- [x] **T7 (P2, human: ~1d / CC: ~30min)** — program grid — /admin/program page + revise endpoint w/ base_version_no
  - Surfaced by: W4 · Files: app/Http/Controllers/Admin/ProgramController.php, resources/js/pages/admin/Program.vue · Verify: e2e admin spec
- [x] **T8 (P2, human: ~2h / CC: ~10min)** — offline — sw.js /storage image-only caching + CACHE bump
  - Surfaced by: Architecture #5 · Files: public/sw.js · Verify: offline e2e assertion
- [x] **T9 (P1, human: ~2h / CC: ~10min)** — ops — backup + restore-drill extension for media files
  - Surfaced by: Architecture #4 · Files: deploy/hep-backup.sh, deploy/hep-restore-test.sh · Verify: manual drill run
- [x] **T10 (P2, human: ~1d / CC: ~30min)** — e2e — admin.spec.ts w/ tinker admin fixture, demo.mp4 fixture, afterAll cleanup
  - Surfaced by: Tests (e2e idempotency) · Files: tests/e2e/admin.spec.ts, tests/e2e/fixtures/demo.mp4 · Verify: `npx playwright test` twice (idempotent)
- [ ] **T11 (P3, human: ~1h / CC: ~5min)** — deploy docs — checklist items 1-3, 6-7 executed on prod at release
  - Surfaced by: Perf #11 · Files: CONTENT-ADMIN.md (this checklist) · Verify: prod smoke (item 7)

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 0 | — | — |
| Codex Review | `/codex review` | Independent 2nd opinion | 0 | — | — |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 1 | CLEAR (PLAN) | 20 issues, 0 critical gaps, 16 decisions all resolved |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | — | declined (D23: admin-only surface) |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | — | — |

- **CROSS-MODEL:** Outside voice (Claude subagent, fresh context — codex not installed) raised 8 challenges; 4 folded as corrections (Dashboard carry-over consumer, additive-only view migration, laterality freeze, 100MB upload cap + audit log), 1 already-litigated scope point stood (D3v2), 3 absorbed as tests/handling (session-under-superseded-version, 409 UX, empty-window semantics).
- **VERDICT:** ENG CLEARED — ready to implement. Run /ship when done.

NO UNRESOLVED DECISIONS

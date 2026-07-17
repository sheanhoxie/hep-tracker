# Stack Decisions — HEP App

Decided 2026-07-08 via /plan-eng-review (3 review questions + adversarial outside voice, 3 cross-model tensions resolved).

## The stack

| Layer | Choice | Rejected | Why |
|-------|--------|----------|-----|
| Backend | Laravel 13 (PHP 8.4+) | Go, .NET/EF Core | Strongest daily stack; DDL ports 1:1 to migrations; scheduler, notifications, Flysystem, auth built-in |
| Frontend | Inertia 3 + Vue 3 | Livewire 4, separate SPA+API | Session player is client-state-heavy (timers, wake-lock, video); one paradigm everywhere; npm ecosystem |
| Database | Postgres | SQLite, MySQL | DDL and derived views port verbatim; two-sided future free. Ops cost priced in via hosting decision below |
| Media | Flysystem local disk → S3-compatible swap later (R2/Bunny) | — | One `composer require` + config (see §NOT in scope correction); v1 plays stock videos only |
| Form factor | Responsive web + **PWA in v1** (manifest + service worker) | Native app | Service worker required by both offline player and web push |
| Reminders | Web push (VAPID) via PWA | SMS, email | Flaky on iOS, solid on Android; PWA prerequisite moved into v1 |
| Hosting | Single VPS (Hetzner/DO, ~$6–12/mo) | Fly.io + managed PG | One box runs Postgres, cron (scheduler), queue, media disk. Flat cost |
| Build/test | Vite, Tailwind, Pest + **Vitest** + 1 Playwright E2E | — | Player state machine is the highest-risk code; PHP-only testing covers the safe half |

## Architecture rules (from adversarial review — binding)

1. **Server materializes, client executes.** The scheduler expands each prescription into a fully-resolved step list (per-side expansion, hold/tempo/rest values) stored in/served with `session_items`. The Vue player only executes steps — dosage interpretation logic exists in PHP once, never duplicated in JS.
2. **Player is an offline-tolerant island.** Session start downloads the full materialized session payload. Set completions and observations write to an IndexedDB queue with client-generated idempotency keys, replayed to the server on reconnect. Losing wifi mid-plank loses nothing.
3. **Timers recompute from timestamps, never tick.** Screen lock throttles/kills JS timers. Hold, tempo, and rest countdowns derive remaining time from a stored start timestamp on every wake/visibilitychange.
4. **`set_events` replay must be idempotent.** Server dedupes on client-generated key — the append-only log tolerates at-least-once delivery.

## v1 sequencing

1. Laravel scaffold + migrations (order per data model doc §Migration-order)
2. Seeder loading the sample program into exercises + program_version + prescriptions
3. Scheduler ("what's due today") as pure function + Pest tests
4. Session player island (Vitest for state machine, Playwright happy path)
5. PWA manifest + service worker + web push reminders
6. Deploy to VPS

## NOT in scope (v1)

- **form_check recording/upload pipeline** — hardest item in the app (capture, chunked upload, transcode, signed URLs). Captured in TODOS.md. v1 plays stock videos only.
- **Program-revision interface** — v1 seeds the initial program; revision via artisan command later. Captured in TODOS.md.
- **PT-facing side** — schema supports it; no UI until a second user is real.
- **Native app** — PWA covers the single user.
- **Media CDN / external video hosting** — local disk until it hurts; Flysystem makes the swap config-only.
  - Correction (2026-07-09): not purely config-only. Neither `league/flysystem-aws-s3-v3` nor `aws/aws-sdk-php` is installed, so `config/filesystems.php`'s `s3` disk has no driver behind it — selecting it throws `Error: Class "Aws\S3\S3Client" not found` during driver construction, which the disk's `'throw' => false` flag does not catch. The swap costs one `composer require`. See `DEPLOY.md` §E.

## What already exists

- `hep-data-model.md` — full DDL, reused as-is (source of truth for migrations).
- No code exists; nothing rebuilt unnecessarily.

## Failure modes (new codepaths)

| Codepath | Realistic failure | Test planned | Handling planned | User-visible? |
|----------|------------------|--------------|------------------|---------------|
| Player write queue replay | Duplicate set_events on flaky reconnect | Vitest (queue) + Pest (dedup) | Idempotency keys (rule 4) | No — silent correct |
| Timer resume after screen lock | Countdown frozen/wrong on wake | Vitest (timestamp math) | Rule 3 | Clear — timer shows recomputed value |
| Web push delivery | iOS silently drops subscription | Manual + `last_sent_at` audit | Deficit alert also visible in-app | **Partial gap: delivery failure is silent** — mitigated by in-app "what's due" being primary |
| Seeder | PDF transcription error → wrong dosage | Pest assertion vs known program | Human review of seeded data | Yes — Patient sees wrong reps |
| Session payload fetch | Network gone at session start | Playwright offline case | Service worker caches last program version | Clear — cached session or explicit offline notice |

No critical gaps (silent + untested + unhandled): web push is the closest — flagged, mitigated by in-app due-list as primary surface.

## Worktree parallelization

Lanes after step 2 (scaffold + seeder are prerequisites for everything):

- **Lane A:** scheduler + Pest tests (app/Services, tests/) — pure function, no UI dependency
- **Lane B:** player island (resources/js/, Vitest) — can build against a fixture payload before Lane A lands
- **Lane C (after A+B):** PWA/push + deploy

Launch A + B in parallel worktrees; merge both; then C. Conflict risk low — disjoint directories.

## Open decisions carried from data model doc

- Week boundary (ISO Monday vs rolling 7-day) — decide when building the scheduler (step 3).
- Weekly-completion carry-over on version supersession — decide when building program-revision tooling (TODO 2).
- Alternating laterality modeling — start simple (one set, side=NULL) per doc.

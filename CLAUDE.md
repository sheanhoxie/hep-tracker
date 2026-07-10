# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project State

Scaffolded 2026-07-08. Laravel app with domain migrations, models, seeder, and Pest tests all in place and green (`php artisan test`, real Postgres — see phpunit.xml). No scheduler, session player, or HEP UI yet — roadmap in TODOS.md. No git repository; user commits manually.

## Stack (decided 2026-07-08 — see stack-decisions.md for rationale and binding architecture rules)

Laravel 13 (PHP 8.3+) · Inertia 3 + Vue 3 · Postgres · Vite/Tailwind · Pest + Vitest + Playwright · single VPS · PWA with web-push reminders in v1. The session player is an offline-tolerant island: server materializes expanded step lists, client executes and queues writes to IndexedDB with idempotency keys, timers recompute from timestamps.

## Source of Truth

`hep-data-model.md` is the design document. Read it before any schema or domain work. `program-source-document` is the authoritative program content (real the PT HEP, sample date): 22-exercise catalog with dosages, aliases, cues, and data gaps — machine-readable copy in `database/seeders/data/hep-exercises.json`, seeded by `HepProgramSeeder`. The entity chain:

**Exercise → Prescription → ProgramVersion → SessionPlan → SetEvent**

## Core Design Rules (from the data model doc — do not violate)

1. **Content is mutable, prescriptions are immutable-per-version, logs are append-only.** Never edit `prescriptions` rows in place — a program revision creates a new `program_version` with copied-then-modified prescription rows. Never UPDATE or DELETE `set_events` or `observations`.
2. **Derivable data is computed, never stored.** Streaks, adherence, weekly counters, "what's due today" come from views (`v_weekly_completions`, `v_daily_completions`) or pure application-code functions — the scheduler reads state, never writes it.
3. **Built as-if two-sided (PT + patient), but every table works with a single user.**

## Domain Architecture

- **Identity**: `users`, `user_profiles` (preferences/equipment as JSONB — deliberately schemaless).
- **Content**: `exercises` plus per-user `exercise_aliases` (nicknames like "sample alias"), `exercise_cues`, `exercise_media` (including `form_check` — user's own recorded correct form).
- **Assignment**: `programs` (stable identity) → `program_versions` (dated revisions) → `prescriptions`. Dosage is typed columns (sets/reps/hold_sec/eccentric_sec/…), not JSON — the session runtime, scheduler, and analytics compute from these fields.
- **Runtime**: `sessions` → `session_items` — the materialized queue the player walks through, generated at session start, then owned by the runtime (reorder/skip). This is the crash-safe resume layer.
- **Record-keeping**: `set_events` (append-only) and a single `observations` table for all subjective data (pain/RPE/notes) attachable at set, item, or session grain.

Migration order when scaffolding: users/profiles → exercise tables → program tables → session tables → event tables → reminders/views.

## Open Decisions (flagged in the doc, not yet made — ask before assuming)

- ~~Week boundary~~ DECIDED 2026-07-08: ISO Monday (spec §5, matches journal grid) — implemented in `ScheduleService` and `v_weekly_completions`.
- Whether a new program_version carries over the current week's already-earned completions (doc leans carry-over, matched on exercise_id).
- ~~Alternating-laterality modeling~~ DECIDED 2026-07-08: one set with side=NULL, alternate within the set (doc's lean) — implemented in `SessionService::expandSetQueue()`.
- Media storage: URLs assume external hosting; self-recorded form checks will need an upload pipeline.

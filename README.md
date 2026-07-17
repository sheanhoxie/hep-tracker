# Home Exercise Program (HEP) Tracker

A single-user physical-therapy home-exercise-program tracker, built as an
offline-tolerant PWA. Not a general workout log — it models PT-specific
dosage (sets/reps/hold/eccentric/tempo), append-only set logging, subjective
pain/RPE observations, and versioned program revisions.

> **Snapshot notice:** this repository is a one-time public snapshot of a
> private project (July 2026) and is not actively synced with it. Issues and
> PRs are welcome but responses are best-effort.

## Stack

Laravel 13 (PHP 8.4+) · Inertia 3 + Vue 3 · Postgres · Vite/Tailwind ·
Pest + Vitest + Playwright · PWA with web-push reminders.

See `stack-decisions.md` for the rationale and `hep-data-model.md` for the
domain model (`Exercise → Prescription → ProgramVersion → SessionPlan → SetEvent`).

## Sample data

`database/seeders/data/hep-exercises.json` ships a synthetic 22-exercise
sample program (invented for this repo — not any real patient's or
practitioner's program) so the seeder runs out of the box. Video URLs in
`hep-media.json` are placeholder demo clips from Wikimedia Commons (CC-BY).

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Default seeded login: `patient@example.com` / `password`.

## Tests

```bash
php artisan test   # Pest, real Postgres
npm run test       # Vitest
npx playwright test
```

## License

MIT — see `LICENSE`.

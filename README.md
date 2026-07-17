# Home Exercise Program (HEP) Tracker

A single-user physical-therapy home-exercise-program tracker, built as an
offline-tolerant PWA. Not a general workout log — it models PT-specific
dosage (sets/reps/hold/eccentric/tempo), append-only set logging, subjective
pain/RPE observations, and versioned program revisions.

## Stack

Laravel 13 (PHP 8.4+) · Inertia 3 + Vue 3 · Postgres · Vite/Tailwind ·
Pest + Vitest + Playwright · PWA with web-push reminders.

See `stack-decisions.md` for the rationale and `hep-data-model.md` for the
domain model (`Exercise → Prescription → ProgramVersion → SessionPlan → SetEvent`).

## Sample data

`database/seeders/data/hep-exercises.json` ships a small sample exercise
catalog (not tied to any real patient or practitioner) so the seeder runs
out of the box. Video URLs in `hep-media.json` are placeholder demo clips
from wger.de (CC-BY-SA) and Wikimedia Commons (CC-BY).

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

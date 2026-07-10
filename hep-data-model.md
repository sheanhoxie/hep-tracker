# HEP App — Data Model Sketch

Postgres-flavored DDL, trivially portable to Laravel migrations or EF Core.
Entity chain: **Exercise → Prescription → ProgramVersion → SessionPlan → SetEvent**

Design rules applied throughout:

1. Content is mutable, prescriptions are immutable-per-version, logs are append-only.
2. Anything derivable (streaks, adherence, "what's due") is computed, never stored.
3. Built as-if two-sided (PT + patient) but every table works with a single user.

---

## 1. Identity & personalization

```sql
CREATE TABLE users (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            TEXT NOT NULL,
    email           TEXT UNIQUE NOT NULL,
    role            TEXT NOT NULL DEFAULT 'patient',  -- patient | practitioner | admin
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE user_profiles (
    user_id         BIGINT PRIMARY KEY REFERENCES users(id),
    affected_side   TEXT,                 -- left | right | bilateral | null
    injury_context  TEXT,                 -- free text: "knee, sample"
    equipment       JSONB NOT NULL DEFAULT '[]',   -- ["band","ball","chair"]
    preferences     JSONB NOT NULL DEFAULT '{}'    -- units, voice_cues, default_rest_sec…
);
```

`preferences` as JSONB is deliberate: these change shape constantly and nothing
queries into them relationally. Equipment is queried ("hide band exercises"),
but a JSONB array with a GIN index is fine at this scale.

---

## 2. Exercise library (content domain)

```sql
CREATE TABLE exercises (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug            TEXT UNIQUE NOT NULL,          -- 'ta-activation'
    canonical_name  TEXT NOT NULL,                 -- 'TA (Sample Exercise) Activation'
    description     TEXT,
    steps           JSONB NOT NULL DEFAULT '[]',   -- ordered instruction strings
    body_region     TEXT,                          -- core | hip | knee | ankle-foot
    movement_pattern TEXT,                         -- squat | hinge | isometric | gait | balance
    laterality      TEXT NOT NULL DEFAULT 'bilateral',  -- bilateral | unilateral | alternating
    equipment       JSONB NOT NULL DEFAULT '[]',
    created_by      BIGINT REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Your "sample alias" / "sample alias" layer. Per-user, so Patient's nicknames
-- don't collide with a future second user's.
CREATE TABLE exercise_aliases (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    exercise_id     BIGINT NOT NULL REFERENCES exercises(id),
    user_id         BIGINT NOT NULL REFERENCES users(id),
    alias           TEXT NOT NULL,                 -- 'sample alias'
    is_display_name BOOLEAN NOT NULL DEFAULT false, -- show instead of canonical
    UNIQUE (user_id, exercise_id, alias)
);

CREATE TABLE exercise_cues (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    exercise_id     BIGINT NOT NULL REFERENCES exercises(id),
    user_id         BIGINT REFERENCES users(id),   -- NULL = stock cue, else personal
    cue             TEXT NOT NULL,                 -- 'tuck tail', 'ribs quiet'
    sort_order      INT NOT NULL DEFAULT 0
);

CREATE TABLE exercise_media (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    exercise_id     BIGINT NOT NULL REFERENCES exercises(id),
    kind            TEXT NOT NULL,                 -- video | gif | image | form_check
    url             TEXT NOT NULL,
    uploaded_by     BIGINT REFERENCES users(id),   -- form_check = user's own recording
    duration_sec    INT,
    sort_order      INT NOT NULL DEFAULT 0
);
```

`form_check` media kind is the sleeper feature: Patient records herself once,
and the session player can show *her own* correct form next to the stock video.

---

## 3. Programs & prescriptions (assignment domain)

The versioning core. A `program` is the stable identity ("Patient's knee rehab");
a `program_version` is one dated revision of it (the PT's sample date PDF). Prescriptions
hang off the *version* and are never edited in place — a revision creates a new
version with copied-then-modified rows. History stays intact for "what was I
doing in June?"

```sql
CREATE TABLE programs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    patient_id      BIGINT NOT NULL REFERENCES users(id),
    author_id       BIGINT REFERENCES users(id),   -- the PT, eventually
    title           TEXT NOT NULL,                 -- 'Sample Rehab Program'
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE program_versions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id      BIGINT NOT NULL REFERENCES programs(id),
    version_no      INT NOT NULL,
    source_note     TEXT,                          -- 'the PT HEP, sample date'
    effective_from  DATE NOT NULL,
    effective_to    DATE,                          -- NULL = current
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (program_id, version_no)
);

CREATE TABLE prescriptions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_version_id BIGINT NOT NULL REFERENCES program_versions(id),
    exercise_id     BIGINT NOT NULL REFERENCES exercises(id),

    -- dosage (structured, not JSON — the session player computes from these)
    sets            INT,
    reps            INT,
    hold_sec        INT,                           -- isometric hold per rep
    eccentric_sec   INT,                           -- your 6-sec lowers (tempo)
    rest_sec        INT,
    per_side        BOOLEAN NOT NULL DEFAULT false,
    to_fatigue      BOOLEAN NOT NULL DEFAULT false,
    duration_sec    INT,                           -- time-based work (90s exercise-r)

    -- cadence
    frequency_kind  TEXT NOT NULL,                 -- daily | per_week | as_needed
    times_per_week  INT,                           -- 3 for @3 items, NULL for daily
    time_of_day     TEXT,                          -- morning | evening | any

    sort_order      INT NOT NULL DEFAULT 0,
    notes           TEXT                           -- 'ball between thighs'
);
```

Dosage as columns rather than a JSON blob is the one place I'd push back on
"flexible": the session runtime, the scheduler, and analytics all compute from
these fields. Typed columns keep that logic honest. The odd combos
(30–45s ranges) go in `notes` or get normalized (pick 45).

---

## 4. Sessions (runtime domain)

A `session` is one sitting. `session_items` is the materialized queue the
player walks through — generated from the scheduler at session start, then
owned by the runtime (reorder, skip). This is the crash-safe persistence layer:
kill the app mid-plank and the session resumes from its items' states.

```sql
CREATE TABLE sessions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id),
    program_version_id BIGINT REFERENCES program_versions(id),
    planned_date    DATE NOT NULL,
    started_at      TIMESTAMPTZ,
    ended_at        TIMESTAMPTZ,
    status          TEXT NOT NULL DEFAULT 'planned' -- planned | active | done | abandoned
);

CREATE TABLE session_items (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    session_id      BIGINT NOT NULL REFERENCES sessions(id),
    prescription_id BIGINT NOT NULL REFERENCES prescriptions(id),
    sort_order      INT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'pending', -- pending | active | done | skipped
    skip_reason     TEXT                             -- 'knee pain' | 'no band' | 'time'
);
```

---

## 5. Event log (record-keeping domain, append-only)

Never UPDATE, never DELETE. Streaks, adherence, weekly counters — all derived.

```sql
CREATE TABLE set_events (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    session_item_id BIGINT NOT NULL REFERENCES session_items(id),
    set_no          INT NOT NULL,
    side            TEXT,                          -- left | right | NULL
    reps_done       INT,
    hold_sec_done   INT,
    completed       BOOLEAN NOT NULL DEFAULT true,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Subjective data, attachable at any grain: a set, an item, or a whole session.
CREATE TABLE observations (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id),
    session_id      BIGINT REFERENCES sessions(id),
    session_item_id BIGINT REFERENCES session_items(id),
    kind            TEXT NOT NULL,                 -- pain | rpe | note
    numeric_value   NUMERIC,                       -- pain 0–10, RPE 1–10
    text_value      TEXT,
    occurred_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (session_id IS NOT NULL OR session_item_id IS NOT NULL)
);
```

One `observations` table instead of separate pain/rpe/notes tables: the PT
export is "everything subjective in date order," and this makes that a single
query. `kind` + value columns is the classic EAV-lite compromise; it earns its
keep because grains genuinely vary.

---

## 6. Derived views (compute, don't store)

```sql
-- What counts toward the weekly budget, per prescription, this ISO week
CREATE VIEW v_weekly_completions AS
SELECT si.prescription_id,
       date_trunc('week', s.planned_date) AS week_start,
       COUNT(DISTINCT s.planned_date)     AS days_done
FROM session_items si
JOIN sessions s ON s.id = si.session_id
WHERE si.status = 'done'
GROUP BY 1, 2;

-- Daily adherence: % of due daily prescriptions completed per date
-- (scheduler logic lives in app code; this view just supplies raw completions)
CREATE VIEW v_daily_completions AS
SELECT s.user_id, s.planned_date, si.prescription_id, si.status
FROM sessions s JOIN session_items si ON si.session_id = s.id;
```

The scheduler itself — "what's due today" — stays in application code as a pure
function of `(current program_version, calendar date, v_weekly_completions)`.
It reads state, never writes it. That's what makes it unit-testable without
fixtures gymnastics.

---

## 7. Notifications (supporting)

```sql
CREATE TABLE reminders (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id),
    kind            TEXT NOT NULL,        -- daily_nudge | weekly_deficit | pt_visit_prep
    schedule        TEXT NOT NULL,        -- cron-ish or 'daily@18:00'
    enabled         BOOLEAN NOT NULL DEFAULT true,
    last_sent_at    TIMESTAMPTZ
);
```

---

## Relationship map

```
users ─┬─ user_profiles
       ├─ exercise_aliases ──┐
       ├─ observations       │
       └─ programs ─── program_versions ─── prescriptions ─── exercises ─┬─ exercise_cues
                                    │                                    ├─ exercise_media
                                    │                                    └─ (aliases ┘)
                      sessions ─────┘
                         └─ session_items ── set_events
                                └─ observations
```

## Migration-order cheat sheet

1. users, user_profiles
2. exercises, exercise_aliases, exercise_cues, exercise_media
3. programs, program_versions, prescriptions
4. sessions, session_items
5. set_events, observations
6. reminders, views

## Open decisions (flagged, not made)

- **Week boundary**: ISO Monday (assumed above) vs rolling 7-day window for
  the @3 items. Monday-start matches your journal grid.
- **Version supersession**: does a new program_version void this week's
  already-earned weekly completions, or carry them over? I'd carry over by
  matching on exercise_id.
- **Alternating laterality** (quadruped leg lifts): model as one set with
  side=NULL, or two interleaved per-side events? One set is simpler; per-side
  gives better asymmetry analytics. Start simple.
- **Media storage**: URLs assume external hosting (S3/Bunny). Self-recorded
  form checks need an upload pipeline eventually.

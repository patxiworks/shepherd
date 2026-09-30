# Pastores backend (PHP + MySQL)

Replaces the Google Sheets / Apps Script datasource with a MySQL database
and a small PHP admin panel, while keeping the exact JSON contract the
Next.js frontend already expects.

## Layout

```
backend/
  schema.sql            MySQL schema
  config.sample.php     copy to config.php and fill in DB credentials
  includes/              shared PHP helpers (db connection, auth, formatting)
  api/                   public JSON endpoints, called by the Next.js server
    activities.php        GET  ?zone=&section=&centre=&action=lastupdate
    zones.php               GET  -> { zones: [...] }
    login.php               POST { zone, passcode } -> { success, user }
  admin/                 browser-based admin panel (session login required)
  includes/romcal/       PHP port of ROMCAL (liturgical calendar generator) + fixed.dat
  migrate/migrate.php    one-time import from the current Google Sheets data
```

## Deploying to shared/cPanel hosting

1. Create a MySQL database and user in cPanel, then import `schema.sql`
   (phpMyAdmin "Import" tab, or `mysql -u USER -p DBNAME < schema.sql`).
2. Upload the `backend/` folder to your hosting account (e.g. as a
   subdomain like `api.yourdomain.com`, or a subfolder such as
   `public_html/pastores-api/`).
3. Copy `config.sample.php` to `config.php` next to it and fill in the DB
   host/name/user/password cPanel gave you. `config.php` is gitignored —
   never commit real credentials.
4. Create your first admin login (always a `super` account — see "Admin
   access levels" below):
   ```bash
   php -r "echo password_hash('choose-a-real-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   then insert it:
   ```sql
   INSERT INTO admin_users (username, password_hash) VALUES ('admin', '<hash>');
   ```
5. Visit `https://your-host/backend/admin/login.php` and log in. Add at
   least one zone, then centres/users/activities as needed.
6. Confirm the API responds: `https://your-host/backend/api/zones.php`
   should return `{"zones": [...]}`.

If you already ran `schema.sql` before the admin access-levels feature
existed, apply `migrate/002_admin_roles.sql` instead of re-running the
full schema (it's a plain `ALTER TABLE`, safe on an existing database):
```bash
mysql -u USER -p DBNAME < migrate/002_admin_roles.sql
```

Likewise, to add the priests/sections/labors lookup tables (used by the
dropdowns on the admin Activities form) to an existing database, apply
`migrate/003_lookup_tables.sql`. It seeds the lists from values already in
use and backfills `activities.weekday`/`week`; it is safe to re-run:
```bash
mysql -u USER -p DBNAME < migrate/003_lookup_tables.sql
```
Then apply `migrate/004_priest_zones_derived_fields.sql` **once** to give
each priest a zone (assigned from the zone they have the most activities in
— check them under Admin > Priests) and to backfill the day/weekday/week
and section values that the Activities form now derives from the date and
centre:
```bash
mysql -u USER -p DBNAME < migrate/004_priest_zones_derived_fields.sql
```
Finally apply `migrate/005_settings_week_start.sql` (safe to re-run). It
adds the `settings` table (week start, default Sunday) and recalculates
each activity's weekday/week for a Sunday-start week:
```bash
mysql -u USER -p DBNAME < migrate/005_settings_week_start.sql
```
And `migrate/006_activity_types.sql` (safe to re-run) adds the activity types
list behind the Activity dropdown, seeded from the activity names in use:
```bash
mysql -u USER -p DBNAME < migrate/006_activity_types.sql
```
Finally `migrate/007_source.sql` (safe to re-run) adds the `source` table
(Admin > Source, super admin only):
```bash
mysql -u USER -p DBNAME < migrate/007_source.sql
```
If `007_source.sql` was applied before the date was removed from `source`,
also apply `migrate/008_source_drop_date.sql` (safe to re-run) to drop that column:
```bash
mysql -u USER -p DBNAME < migrate/008_source_drop_date.sql
```
Then `migrate/010_source_integrity.sql` (safe to re-run) makes `source` agree
with the lookup tables (adds the centres, priests and activity types it uses
to their tables, sets source sections from the centres, drops `source.unit`)
and lists any source rows whose priest belongs to another zone, which need
sorting out by hand:
```bash
mysql -u USER -p DBNAME < migrate/010_source_integrity.sql
```
Finally `migrate/011_priest_zones.sql` (safe to re-run) adds `priest_zones`
(priests who also serve in other zones) and, for priests that existing source
rows already use in a zone that isn't their home, lists that zone for them:
```bash
mysql -u USER -p DBNAME < migrate/011_priest_zones.sql
```

`migrate/009_liturgical_calendar.sql` (safe to re-run) adds the
`liturgical_calendar` table and the calendar options; see "Liturgical
calendar" below:
```bash
mysql -u USER -p DBNAME < migrate/009_liturgical_calendar.sql
```

## Liturgical calendar

`includes/romcal/Romcal.php` is a PHP port of ROMCAL 6 (Kenneth G. Bath's C
program, see `includes/romcal/NOTICE.txt` — non-commercial use only). It
computes the General Roman Calendar for a year from Easter, the seasons and
the fixed-date celebrations in `includes/romcal/fixed.dat`, and needs no C
compiler, `exec()` or extension, so it runs on shared hosting.

**Admin > Settings > Liturgical calendar** (super admin only) has a
*Generate calendar* button. It computes the next 12 months (today through the
same day next year, spanning two calendar years when needed) and replaces
those days in `liturgical_calendar` in one transaction — if anything fails,
nothing changes. Rows for dates before today are kept. The card also holds the
options ROMCAL had as command-line flags (Ascension on Sunday, Epiphany on Jan
6, Corpus Christi on Thursday, include optional memorials); they are stored in
`settings` as `calendar_*` and used the next time you generate.

Columns: `cal_date`, `celebration`, `class` (A–E), `liturgical_rank`, `color`,
`season`, `notes`, `votive`, `devotion`.

- `fixed.dat` keeps the original format: `MONTH day RANK COLOR text`, where
  RANK is `O M F L S` or `V` (votive) and COLOR is `G R W P`. In the text, the
  first tab-separated piece is the celebration name, a lone letter `A`–`E` is
  the class, and anything else (So Ex, Te Deum, novena days, ...) goes to
  `notes`. Edit the file and regenerate; there is no build step.
- `votive` and `devotion` reproduce what ROMCAL's text output printed under
  each day: Tue Psalm 2, Thu Adoro te, Sat Salve, and on green Tue–Sat the
  votive Mass of the day. Sundays get class C unless `fixed.dat` gives one.
- Deliberate difference from the C program: when Christmas is on a Sunday
  (next: 2033) the C code failed to set Dec 30 as the Holy Family feast (an
  out-of-bounds write in `xmas2.c`); the port sets it.
- The port was checked against the compiled C program for every day of
  1990–2100 under six option combinations (243,252 days): identical apart from
  that Dec 30 fix.

## Admin access levels

`admin_users.role` is one of:

- **`super`** — sees and manages everything: zones, centres, users,
  activities, the source table, the priests/sections/labors lists,
  and other admin accounts (`/admin/admins/`).
- **`zone`** (`zone_id` set) — scoped to one zone: can manage that zone's
  users and activities. Cannot see the Zones, Centres, or Admins sections —
  centres are set up by `super` and are out of a zone admin's purview.
- **`centre`** (`centre_id` set, `zone_id` derived from it automatically)
  — scoped to one centre: can only manage users and activities whose
  `centre` field matches that centre's name, within its zone. Cannot see
  Zones, Centres or Admins at all.

Every list/edit/delete query is filtered server-side by the logged-in
admin's scope (see `*_in_scope()` helpers in each `admin/<entity>/index.php`
and `admin_require_role()` in `includes/auth.php`) — scoped fields in the
forms (e.g. a `zone`-level admin's Zone field, a `centre`-level admin's
Centre field) are rendered `disabled` for clarity, but the enforcement is
server-side: a crafted request naming another zone/centre's record id is
rejected regardless of what the form would have submitted.

Manage accounts at `/admin/admins/index.php` (super-only). A `centre`-level
account only needs a Centre picked — its zone is derived from the centre
automatically, so it can't end up mismatched.

Only a `super` account can create the first admin (via the `INSERT INTO
admin_users` above, or `password_hash()`+SQL for a colleague) — there's no
self-service signup.

## One-time data migration

`migrate/migrate.php` pulls the current data straight from the live Google
Apps Script endpoints (same URLs the Next.js app calls today) and imports
it into MySQL. Run it **locally**, pointed at your new database (set up
`config.php` first, same as above):

```bash
php migrate/migrate.php
```

It's idempotent for zones/users (matched by name) but will duplicate
activities if you run it twice — truncate that table first if you
need a clean re-import. Review the imported data in the admin panel before
cutting the frontend over.

## Cutting the Next.js frontend over

The frontend never talks to Google Sheets or this PHP API directly — it
only calls its own two route handlers:

- `src/app/api/collections/route.ts`
- `src/app/api/auth/zone-login/route.ts`

Once this backend is deployed and the data migrated, those two files need
small edits (only the parts that build the remote URL / do the credential
check — the request/response shape stays the same, so `page.tsx` and
`login/page.tsx` don't need to change):

- **`collections/route.ts`**: replace `REMOTE_ACTIVITIES_URL` with
  `https://your-host/backend/api/activities.php` (move it into an env var
  rather than hardcoding, e.g. `PASTORES_API_BASE`). There is no masses endpoint any more.
- **`auth/zone-login/route.ts`**:
  - `GET` — instead of fetching all users and computing distinct zones,
    fetch `api/zones.php` and return its JSON as-is.
  - `POST` — instead of fetching all users and comparing passcodes in
    Next.js, `POST` the `{zone, passcode}` body straight to
    `api/login.php` and relay its JSON/status code. This also fixes the
    current security gap where all zone passcodes are fetched to the
    Next.js server on every login/zone-list request.

Keep the old Google Sheets URLs around (or in git history) until you've
verified the new backend in production, so you can flip back quickly if
something's wrong.

## Notes / deliberate deviations from the old Sheets behaviour

- Passcodes are hashed (`password_hash`/`password_verify`), not stored or
  compared in plaintext. Case-insensitivity is preserved by lower-casing
  before hashing and before verifying.
- `api/login.php`'s success response omits the `passcode` field that the
  old Apps Script response included — nothing in the frontend reads
  `user.passcode` after login, so this was safe to drop.
- `zones.last_update` is bumped by every admin write (`touch_zone()` in
  `includes/functions.php`) so the frontend's "check for updates" polling
  keeps working.
- `activities.from` / `.to` / `.duration` are re-encoded back into the
  same odd `"1899-12-30THH:MM:00.000Z"` shape Google Sheets exported, so
  the existing frontend time-formatting code in `src/app/page.tsx` keeps
  working unchanged.

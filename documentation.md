# Pastores — Developer Documentation

Pastores is a schedule/roster app for pastoral centre activities ("Schedule
for Pastoral Attention of Centres"). It has two parts:

1. **Frontend** — a Next.js app (`src/`), deployed on Vercel, installable as
   a PWA. This is what end users (zone/centre staff) see and interact with.
2. **Backend** — a PHP + MySQL API and admin panel (separate `pastores-backend` repo), which
   replaces the app's original Google Sheets datasource. This is where
   zones, centres, users, activities and multi-day activities are actually managed.

If you're new to this repo, read this file top to bottom once, then use it
as a map — it links to the actual source files rather than duplicating
their contents.

## Status at a glance

- ✅ PHP/MySQL backend built, running locally, tested (see the
  `pastores-backend` repo's README).
- ⚠️ **Not yet cut over.** The frontend's two API routes
  (`src/app/api/collections/route.ts`,
  `src/app/api/auth/zone-login/route.ts`) still call the original Google
  Apps Script endpoints, not the new PHP backend. See
  [Cutover: connecting the frontend to the new backend](#cutover-connecting-the-frontend-to-the-new-backend).
- ✅ **Liturgical calendar** generator added (PHP port of ROMCAL, run from
  Admin → Settings). Migration `009` has been applied to the local MAMP
  database; the table is empty until someone presses *Generate calendar*. Not
  yet used by the frontend — see [Liturgical calendar](#liturgical-calendar).
- ✅ **Multi-day activities** (retreats/courses/camps at a venue over several
  days — a port of the standalone "Painted Calendar" HTML tool) added on the
  `conference-centres` branch: migration `015`, an admin list/CRUD page, a
  painted calendar view and a statistics dashboard, with the tool's 148 rows imported into the local MAMP
  database. Not yet used by the frontend — see
  Multi-day Activities in the backend docs.
- **Repos are split by host:** this repo is the frontend only (Vercel); the PHP
  backend is the separate `pastores-backend` repo (Hostinger Git deployment, repo
  root = web root). Each host receives only its own code.

---

## 1. Frontend (`src/`)

Stack: Next.js 15 (App Router), React 18, TypeScript, Tailwind, shadcn/ui
(Radix-based components in `src/components/ui`), `date-fns`.

### Pages

- [`src/app/login/page.tsx`](src/app/login/page.tsx) — zone + passcode
  login form. Fetches available zones from `GET /api/auth/zone-login`,
  submits credentials to `POST /api/auth/zone-login`, and on success
  stores the returned user object in `localStorage` under `zoneUser`.
  Also handles the PWA "Add to Home Screen" prompt.
- [`src/app/page.tsx`](src/app/page.tsx) — the main schedule view. All
  client-side (`"use client"`). Responsibilities:
  - Redirects to `/login` if `localStorage.zoneUser` is missing.
  - Fetches activities + masses (the date-header names, still from the old Google Apps Script) from `GET /api/collections`, caches the
    response in `localStorage.pastoresData`, and renders instantly from
    cache on repeat visits.
  - Polls `GET /api/collections?action=lastupdate` in the background and
    shows an "Update available" toast when the remote data is newer than
    what's cached (compares `last_update` timestamps).
  - Groups activities by date / centre / activity (`groupBy` state) into
    an accordion ([`src/components/grid-accordion/`](src/components/grid-accordion)).
  - Filters by priest, section, labor, centre, free-text search.
  - Renders differently by `userRole` (`admin`, `ctr`, or unset) — e.g.
    `ctr` (centre-level) users don't see the "Go to centre" picker or the
    "Group by Centre" option, since they only see their own centre's data.

### API routes (`src/app/api/`)

These are the **only** two integration points between the frontend and any
backend — the browser never talks to Google Sheets, Apps Script, or the
PHP API directly.

- [`src/app/api/collections/route.ts`](src/app/api/collections/route.ts)
  — `GET ?zone=&section=&centre=&action=lastupdate`. Proxies to a remote
  activities source and a remote masses source, combines them into
  `{ activities: ApiActivity[], masses: Record<string, {Class, Mass}> }`.
- [`src/app/api/auth/zone-login/route.ts`](src/app/api/auth/zone-login/route.ts)
  — `GET` returns `{ zones: string[] }` for the login dropdown; `POST
  { zone, passcode }` returns `{ success, user }` or `{ success: false,
  message }`.

### Types (`src/types/index.ts`)

- `ApiActivity` — one row of the schedule: `unit`, `week`, `day`,
  `weekday`, `date`, `centre`, `activity`, `section`, `labor`, `from`,
  `to`, `duration`, `mfrequency`, `priest`, `description`.
- `ZoneUser` — a logged-in user: `zone`, `name`, `centre`, `section`,
  `passcode`, `role`, `last_update`.
- `AccordionGroupData` / `GroupItem` — the derived shape used to render
  the accordion, grouped by date/centre/activity.

### Section colour coding

Section colours are set in the admin (Structure → Sections: each section has a
colour picker, stored in `sections.colour`) and served by
`api/sections.php` in the backend repo as
`{ "sections": { "sf": "#FFB74D", ... } }`. The Next.js app reads them through
[`src/app/api/sections/route.ts`](src/app/api/sections/route.ts), which proxies
the URL in the `SECTIONS_API_URL` environment variable (unset → `{}`), and
applies them with `setSectionColors()` whenever it fetches fresh data.
[`src/lib/section-colors.ts`](src/lib/section-colors.ts) only holds the
**fallback** colours used until then (and for a section with no colour).
Labores have no colours.

### Local dev

```bash
npm install
npm run dev       # next dev --turbopack
npm run typecheck # tsc --noEmit
npm run lint
```

There's also a Genkit/Google AI setup (`src/ai/`, `genkit:dev` /
`genkit:watch` scripts) — this looks scaffolded but unused by the current
app; it's not wired into any page or API route.

---

## 2. Backend

The PHP + MySQL backend now lives in its **own repository** (`pastores-backend`,
deployed to Hostinger, while this repo is deployed to Vercel). Its data model, admin
panel, API endpoints, migrations and deployment are documented in that repo's
`documentation.md` and `README.md`. The frontend only talks to it over HTTP
(`api/activities.php`, `api/zones.php`, `api/login.php`, `api/sections.php`).

---

## Cutover: connecting the frontend to the new backend

**This has not been done yet.** The two Next.js route handlers still
point at the original Google Apps Script URLs. To switch over:

1. Deploy the `pastores-backend` repo to Hostinger (see its README §"Deploying") and migrate
   production data there.
2. In [`src/app/api/collections/route.ts`](src/app/api/collections/route.ts),
   replace `REMOTE_ACTIVITIES_URL` with the deployed
   `api/activities.php` URL (move to env vars instead of hardcoding). There is no
   backend replacement for `REMOTE_MASSES_URL` (the `masses` table was removed): drop
   the masses fetch, and the page then shows no mass names in date headers.
3. In [`src/app/api/auth/zone-login/route.ts`](src/app/api/auth/zone-login/route.ts):
   - `GET` → proxy to `api/zones.php` instead of fetching all users and
     computing distinct zones in Next.js.
   - `POST` → proxy the `{zone, passcode}` body to `api/login.php`
     instead of fetching all users and comparing passcodes in Next.js
     (this also closes the current gap where every zone's passcodes are
     pulled to the Next.js server on every request).
4. Keep the old Google Sheets URLs around (git history is enough) until
   the new backend is verified in production, so you can revert fast.

Neither `page.tsx` nor `login/page.tsx` need to change — the JSON
contracts were deliberately kept identical.


## Where to look for X

| Task | File(s) |
|---|---|
| Change how the schedule is grouped/filtered | [`src/app/page.tsx`](src/app/page.tsx) |
| Change the login form | [`src/app/login/page.tsx`](src/app/login/page.tsx) |
| Generate / refresh the liturgical calendar (next 12 months) | Admin panel → Settings → Liturgical calendar → *Generate calendar* (super admin) |
| Change which day the week starts on | Admin panel → Settings (super admin); also renumbers `activities.weekday` and `source.day` |
| Add/rename/move a priest, or add a section, labor or activity type | Admin panel → Priests / Sections / Labores / Activities (activity types; super admin; the up/down arrows set their order). A centre's section and labores are set on the Centres page. |
| Change a section's *colour* | Admin panel → Sections (colour picker). Fallbacks for the public app: [`src/lib/section-colors.ts`](src/lib/section-colors.ts) |
| Add/edit multi-day activities, or mark an activity type as multi-day | Admin panel → Multi-day Activities (List tab); to make an activity type a multi-day one, tick "Multi-day programme" on Admin panel → Activities (Structure) |

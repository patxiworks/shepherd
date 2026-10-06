# Pastores — Developer Documentation

Pastores is a schedule/roster app for pastoral centre activities ("Schedule
for Pastoral Attention of Centres"). It has two parts:

1. **Frontend** — a Next.js app (`src/`), deployed on Vercel, installable as
   a PWA. This is what end users (zone/centre staff) see and interact with.
2. **Backend** — a PHP + MySQL API and admin panel (`backend/`), which
   replaces the app's original Google Sheets datasource. This is where
   zones, centres, users, activities and multi-day activities are actually managed.

If you're new to this repo, read this file top to bottom once, then use it
as a map — it links to the actual source files rather than duplicating
their contents.

## Status at a glance

- ✅ PHP/MySQL backend built, running locally, tested (see
  [backend/README.md](backend/README.md)).
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
  [Multi-day Activities](#admin-panel-backendadmin).
- The backend is committed on the `backend-admin-panel` branch (not on
  `master`), and `conference-centres` is branched from it — check
  `git branch` / `git status` before assuming any of it is deployed anywhere.

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

### Section/labor colour coding

[`src/lib/section-colors.ts`](src/lib/section-colors.ts) hardcodes the
known `section` values (`sf`, `sv`, `c-m`, `c-w`, `p-m`, `p-w`) and
`labor` values (`sm`, `sg`, `sr`, `sm agd`, `sr club`, `seminarians`,
`priests`) to colours. The lists of valid sections/labors now live in the
`sections`/`labors` database tables (managed in the admin panel), but the
*colours* are still hardcoded here — so when you add a section or labor in
the admin, add a matching colour in this file too.

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

## 2. Backend (`backend/`)

Stack: plain PHP (no framework/Composer — chosen for compatibility with
shared/cPanel hosting) + MySQL via PDO. Full details, deployment steps,
and the exact JSON contracts are in **[backend/README.md](backend/README.md)**
— this section is the map of *how it's organized* and *why*.

### Why it exists

The app originally had no real backend: `collections/route.ts` and
`zone-login/route.ts` called a **Google Apps Script** web app that read
from a **Google Sheet** directly. That's fragile (a spreadsheet as a
database, editable by hand, no real access control — every zone's
passcodes were fetched to the Next.js server on every login) and doesn't
scale to needing per-zone/per-centre admin accounts. `backend/` replaces
the spreadsheet with MySQL and gives non-technical staff a proper admin UI
instead of editing a spreadsheet.

### Data model (`backend/schema.sql`)

```
zones (id, name, last_update)
  └─ centres (id, zone_id, name, section)
  └─ users (id, zone_id, name, centre, section, passcode_hash, role)  -- the ZoneUser login accounts
  └─ activities (id, zone_id, unit, week, day, weekday, activity_date,
                 centre, activity, section, labor, from_time, to_time,
                 duration, mfrequency, priest, description)

source (id, zone_id, unit, week, day, centre, activity, section, labor,
        from_time, to_time, duration, mfrequency, priest, description)
                             -- super-admin only; like activities, but `day` is
                             -- the numeric weekday and there is no date
                             -- (see "Source" below)

absences (id, zone_id, priest, start_at, end_at, activity, description)
                             -- when a priest is away; admin-only (see "Absences" below)

multiday_activities (id, zone_id, centre, activity, section, labor, priest,
                     start_date, start_time, end_date, end_time, description, roll_rule)
                             -- multi-day venue programmes: a date+time RANGE, not a
                             -- single activity_date; kept apart from `activities`
                             -- (see "Multi-day Activities" below)

settings (name, value)       -- admin-editable options (week_start, max_masses_per_day, calendar_*)

liturgical_calendar (cal_date, celebration, class, liturgical_rank, color,
                     season, notes, votive, devotion, generated_at)
                             -- one row per day, generated from Admin >
                             -- Settings by the PHP port of ROMCAL
                             -- (backend/includes/romcal/); see README

priests (id, name, zone_id)  -- lookup lists behind the Activities form
  └─ priest_zones (priest_id, zone_id)  -- extra zones a priest also serves in;
sections (id, name)          -- activities.priest/section/labor/activity
labors (id, name)            -- store the *name*, not an id
activity_types (id, name, is_multiday)  -- is_multiday = 1: a multi-day programme type

admin_users (id, username, password_hash, role, zone_id, centre_id)  -- PHP admin-panel logins, separate from `users` above
```

Two completely separate login systems share this database — don't confuse
them:

| | `users` table | `admin_users` table |
|---|---|---|
| Who | End users (zone/centre staff) | People managing the data |
| Where they log in | Next.js `/login` page | PHP `/admin/login.php` |
| Auth | zone + passcode | username + password |
| Scope model | flat (`role` string like `admin`/`ctr`, used only for frontend UI branching) | structured RBAC (`super`/`zone`/`centre`, see below) |

`priests`, `sections`, `labors` and `activity_types` are lookup tables that feed the
Activities form (`sections` only feeds the Centres/Users forms now — see
below). Sections and labors are global; each priest has a home zone
(`priests.zone_id`) and can also serve in other zones (`priest_zones`, e.g. a
temporary transfer — "Also serves in" on the Priests page: a type-to-search field that adds zones as removable chips). The priests "of" a
zone are those whose home it is plus those who list it, and that is what the
Activities and Source forms/filters/inline editors list and what Source
validation accepts (`priests_by_zone()` in `includes/functions.php`); a
transferred priest shows up under both zones. Ending a transfer means
unticking the zone, which is refused while that zone's source rows still use
the priest. Priest names stay unique across zones (one person, one entry). Like
`centre`, `activities.priest/section/labor` hold the name as text rather
than a foreign key, so the frontend JSON is unchanged. Renaming an entry in
the admin cascades to the existing rows that use it; deleting one leaves
existing rows alone (the edit form still shows their current value).

`centre` in `activities`/`users` is a **free-text string**, not a foreign
key to `centres.id` — it has to match a centre's `name` exactly. This
mirrors how the old spreadsheet worked and keeps the frontend's JSON
contract unchanged, but means renaming a centre doesn't cascade to
existing activity/user rows. Worth fixing if it becomes a real pain point.

### Admin panel access levels

Three roles on `admin_users`, enforced **server-side** in every
`admin/<entity>/index.php` file (not just hidden in the UI):

- **`super`** — everything, including zones, centres, other admin
  accounts (`admin/admins/`), the priests/sections/labors lists, and the
  Source table.
- **`zone`** (scoped by `zone_id`) — that zone's users, activities and
  absences. Cannot touch centres (centre creation/editing is `super`-only),
  zones, or other admins.
- **`centre`** (scoped by `centre_id`, with `zone_id` auto-derived from
  it) — only users/activities whose `centre` field matches that centre's
  name. Everything else is 403.

See `admin_require_role()` in
[`backend/includes/auth.php`](backend/includes/auth.php) and the
`*_in_scope()` helper functions in each admin page for the enforcement
pattern (always check ownership server-side before mutating a record by
ID — don't trust a hidden form field).

### API endpoints (`backend/api/`)

These exist specifically to be **drop-in replacements** for the old
Google Apps Script URLs — same request params, same response shape — so
that when the frontend is cut over, only the URL constants change:

| Endpoint | Old equivalent | Notes |
|---|---|---|
| `GET api/activities.php?zone=&section=&centre=&action=lastupdate` | `REMOTE_ACTIVITIES_URL` | date/time fields re-encoded to match the odd Sheets ISO format the frontend already parses |
| `GET api/zones.php` | zone-login `GET` handler | `{ zones: [...] }` |
| `POST api/login.php` | zone-login `POST` handler | does the passcode check in PHP now, not in Next.js |

### Admin panel (`backend/admin/`)

Plain PHP pages, one `index.php` per entity (`zones`, `centres`, `users`,
`activities`, `source`, `absences`, `multiday_activities`, `admins`, `priests`,
`sections`, `labors`, `activity_types`, `settings`), each
handling its own list + create + edit + delete in one file (list on GET, mutate on POST with an
`action=delete` flag for deletes). Shared chrome lives in
`backend/admin/includes/` (`layout_top.php`/`layout_bottom.php` for the
HTML shell + role-aware nav, `flash.php` for one-shot success/error
banners via `$_SESSION['flash']`). The `priests`/`sections`/`labors` pages
are thin wrappers around the shared
[`admin/includes/lookup_page.php`](backend/admin/includes/lookup_page.php).

**Table styling** (`layout_top.php`, applies to every admin table unless a
page overrides it with a more specific/later selector, e.g. the Dashboard
heatmap): `th` is a faint top-to-bottom gradient tint of the brand purple
(`#673AB7`) rather than flat grey, body rows alternate white/`#f8f6fb`
(`tbody tr { background: #fff }`, `tbody tr:nth-child(even)` overriding it —
explicit rather than left transparent/falling through to `.table-wrap`'s own
background, which didn't reliably paint over the wrap's right border along a
plain row's un-covered last cell, e.g. an editing row's actions cell before
it had its own background applied), a hover tint (`tbody tr:hover`, same
specificity but declared after the stripe so it always wins) applies
regardless of a row's stripe, and cells get a light-purple grid
(`border-bottom`/`border-right`, `#ceb9f3`, the `border-right` dropped on the
last column). The outer border/rounding/shadow are on `.table-wrap` (see
above) rather than the `<table>` itself, so they frame it as a fixed box
instead of scrolling away with the table's content on a narrow screen; the
table's own corners are rounded to match by targeting the corner cells
directly (accounting for the bulk-delete checkbox column, when present,
always being the true first cell) rather than `overflow: hidden` on the
wrap, which would otherwise silently stop the sticky header below from
working (confirmed by testing both ways — any ancestor with a non-`visible`
`overflow` breaks `position: sticky` relative to the browser viewport, even
one that never itself scrolls, and moving that `overflow` to a further-out
ancestor doesn't avoid it either — confirmed the same way). `table` itself
has no `background` (unlike before): its flat rectangular background, no
longer clipped to the wrap's rounded shape now that overflow can be
`visible`, was poking a small square notch past the rounded corners in
place of the curve; leaving it transparent lets `.table-wrap`'s own
(correctly-rounded, since a box always rounds its own background) white
background show through instead. `th` is `position: sticky; top: 0`, so a
long table's header (Activities' especially, with its 300-row cap) stays
visible while scrolling — via two different mechanisms depending on screen
width, since a table can't both scroll horizontally in its own box *and*
stick to the browser viewport (confirmed the same way — they're mutually
exclusive on the same element). `≥900px`, `.table-wrap` drops
`overflow-x: auto` (no horizontal scroll needed there in practice) and the
header sticks to the top of the browser viewport as the page scrolls.
`<900px`, where the horizontal scrollbar is still needed, `.table-wrap`
instead gets `max-height: 60vh; overflow-y: auto`, turning it into a
bounded, both-axis scrollport of its own; once a table is taller than that,
it scrolls internally (not the page) and the header sticks to the top of
*that* — confirmed working with horizontal and vertical scroll together in
the same box. A table shorter than `60vh` is unaffected either way (nothing
to scroll).

**Activities List "focus" toggle (`<900px`, zone admin only)** — a
fixed-position round icon button (`#act-focus-toggle`,
[`activities/index.php`](backend/admin/activities/index.php), only rendered
when `$admin['role'] === 'zone'`) hides *everything* above the table,
including the shared top menu bar, so the table can use the full screen
height — for a zone admin the List view's own top nav has just Activities
and Absences, easily reached again after toggling back, unlike super's
multi-level dropdowns. Everything from `<h1>` through the "Click a row to
edit…" hint is wrapped in `#act-above-table`; clicking the icon toggles
`body.act-focus`, which hides `header.topbar`, any flash message and
`#act-above-table`, zeroes `main`'s margin/padding, and gives `.table-wrap`
`height: 100vh` with its border/radius/shadow removed (edge-to-edge). The
icon itself is `position: fixed` (so it never scrolls away) at
`top: 93px` normally (clearing the — at this width, two-row — header) and
`top: 10px` once the header's hidden. It's a single up-chevron SVG — CSS
alone rotates it 180° (`body.act-focus .act-focus-toggle svg { transform:
rotate(180deg) }`) to point down once toggled, so the small script right
after the inline-editing one only ever toggles the class/aria attributes,
never touches the icon markup. Verified in the browser: toggles cleanly
both ways, repositions correctly between the two `top` values, and the
chevron's computed `transform` flips between `none` and a 180°
rotation matrix.

Pages that colour whole rows for a reason (Activities'
absent/duplicate/bilocation/no-priest/mass-limit flags, bulk-select's
`tr.picked`) do it by painting the `<td>`s, which sit on top of both the
stripe and the hover tint.

**Top nav** (`layout_top.php`, role-aware, built from `$_SESSION['admin_role']`):
a super admin gets four click-to-open dropdowns (`.nav-drop`, toggled by the
shared script in `layout_bottom.php`), in order — **Structure** (Zones,
Centres, Sections, Labors, Activity types), **People** (Users, Priests,
Admins), **Records** (Source, Absences) and **Activities** (Regular →
Activities List, Multi-day → Multi-day Activities List) — plus **Settings**
as a plain top-level link.
A zone admin gets two plain links, **Activities** and **Absences**; a centre
admin gets **Users** and **Activities**. There is no Masses page: the `masses` table, its admin page and `api/masses.php`
were removed (migration `018`).

**Activities tabs.** (The tab strip, shared with Multi-day, is `nav.tabs` in `layout_top.php`: the tabs sit on a 2px brand-coloured base line.) The Activities section has tabs **List** (the table below,
[`index.php`](backend/admin/activities/index.php)), **Calendar**
([`calendar.php`](backend/admin/activities/calendar.php)), **Grid**
([`grid.php`](backend/admin/activities/grid.php)) and **Dashboard**
([`dashboard.php`](backend/admin/activities/dashboard.php)); all use the full page
width. The **Calendar** is a read-only view over the same
`activities` rows: one zone at a time (a super admin picks it, the first zone by
default, as on the List tab; zone/centre admins are locked to theirs, and a centre
admin only sees their own centre) with a **Month / Week / Day** switch, ‹ › and
Today navigation (a month, 7 days or a day at a time), and a "Go to date" picker. The
view is anchored on `?date=YYYY-MM-DD` (`?month=YYYY-MM` is still accepted) and
`?view=month|week|day`. Tick-box **Centre / Activity / Group / Section / Priest**
filters refresh live (the filter card and script are the Multi-day calendar's, from
[`multiday_filters.php`](backend/includes/multiday_filters.php)); the navigation and
view links keep the filters.
- *Month*: whole weeks starting on the *week start* setting (so the neighbouring
  months' days show their activities too); each activity is a chip
  (`time centre activity · group`) coloured by section (`sf` pink, `sv` blue, other
  grey; dashed edge = no priest); a day shows the first 3 with "+N more" to expand it.
  A day number opens that day's Day view.
- *Week*: seven taller columns with no limit; chips wrap and show the start time (no end
  time) plus a second line with the priest and group.
- *Day*: a time grid, hours (from 06:00 to 20:00, wider if an activity falls outside) down
  the left and each activity a larger chip whose top is its start time and whose height
  runs to its end time (`to_time`, else start + `duration`, else half an hour; never
  drawn shorter than 30 minutes or past midnight). Chips that overlap share the width:
  each cluster of overlapping chips is split into as many columns as it needs
  (`acal_day_layout()`). Activities with no start time sit in a "No time set" strip above
  the grid, and a red line marks the current time when the day is today (placed by the
  browser's clock, not PHP's, whose timezone is UTC here, and moved every minute).

In Month and Week, hovering a chip shows a tooltip (centre, activity, date and times,
group, section, priest, description) and clicking opens the activity in the List tab's
editor. The List tab's flags (absent priest, duplicates, bilocation, mass limit) are not
shown on the calendar.

The **Grid** tab shows one date (date picker, ‹ › and Today; a super admin also picks the
zone) as a table with every centre of the zone as a row (a centre admin: only theirs) and
every *regular* activity type (`activity_types.is_multiday = 0`) as a column; the centre
column and the header stay in view while scrolling. A cell lists the activities of that
centre + type on the date (priest, times, note) and **clicking it opens an editor dialog**:
one line per activity with Priest (the zone's priests), From, To, Group and Note, a
"+ Add another" button (e.g. two Masses) and × per line; saving with no lines clears the
cell. Saving posts `action=cell_save` (JSON of the cell's entries) to `grid.php`, which
updates entries by id, inserts new ones (week/day/weekday from the date, section from the
centre, duration = To − From, as on the List tab), deletes the ones removed, bumps
`zones.last_update`, and the page then refreshes just the table. Scope is re-checked
server-side (centre must be the admin's, activity type regular, priests the zone's). Each
entry is coloured by the List tab's first applicable flag (no priest, priest absent, priest
in a multi-day activity, bilocation, over the mass limit, duplicate), using the same SQL
select fragments as the List; a legend sits above the table. "Hide empty rows & columns"
(remembered in `localStorage`) trims the grid to what is used that day. In the date bar, under the date text (lighter grey, 14px), the day's
liturgical celebration is shown as "Celebration [Rank | Class]. Notes" from the `liturgical_calendar`
table (nothing if it is empty for that date). A row of draggable priest chips (the zone's priests)
sits above the grid: **dropping a chip on a cell** fills the first entry of that cell that has no
priest, else adds a new entry with that priest (times blank, editable by clicking the cell), saved
at once with the same `cell_save`. **Dragging an entry** to another cell moves it there (Alt/Option-drag copies it; the activity type follows the target column, and the entry is added to the target before it is removed from the source). The grid's chrome follows the theme colour: the centre column is a light mix of the brand colour (`color-mix` 78% brand / white) with white text, the header row 85% brand / white, the corner `--brand-dark`, borders `--tint-border`. Assigned entries are green (purple is the mass-limit colour). Tables can opt out of
the shared sorting/wrapping in `layout_bottom.php` with `data-no-sort` / `data-no-wrap`.

The **Dashboard** is modelled on the Multi-day one: statistics over the same rows for
one zone (a super admin picks it; zone/centre admins are locked as on the Calendar) and
a date range (**From / To**, the current month by default, at most 731 days, swapped if
reversed, with This month / Last month / Next month / This year quick links), using the
Calendar's tick-box Centre / Activity / Group / Section / Priest filters with the same
live refresh. It shows stat cards (Total Activities, Centres Active and Priests Involved —
each with how many the zone has, and how many aren't on the Centres/Priests lists —,
Days With Activity, Scheduled Time = each activity's `to_time − from_time` or its
`duration`, Avg. per Active Day); a row of **needs-attention** counts using the List tab's
flags (No priest, Priest absent, Priest bilocation, Over mass limit, Duplicates, from the
same SQL fragments as the List legend; a non-zero card opens the List tab filtered to the
range and that flag); and bars for By Centre, By Activity, By Priest (activities and hours,
top 15), By Group, By Day of the Week (in week-start order), a **donut** for By Section
(share-of-whole with a handful of uneven slices is the one case a donut reads well;
Day of the Week stays a bar since a pie/donut misreads close values — see the
dataviz skill's anti-patterns), Load Over Time (per week, per month beyond 120 days) and Start Time (per hour). The bar and
stat-card and donut building blocks (`dash_bar()`, `dash_donut()`, `dash_stat()` and
their CSS) are shared with the Multi-day dashboard in
[`backend/admin/includes/dash.php`](backend/admin/includes/dash.php); the donut's
legend always prints the exact count and percentage as text next to each slice, so
identity/value are never colour or angle alone.

**Activities form** ([`backend/admin/activities/index.php`](backend/admin/activities/index.php)):

- **Date is the only input for day/weekday/week.** `activities.day`
  (`Mon`…`Sun`, as in the existing data), `weekday` and `week` are derived
  from the date on save by `date_parts()` in
  [`backend/includes/functions.php`](backend/includes/functions.php). The form
  just shows them as small text under the Date field.
  - `week` is **which occurrence of that day of the week it is in the
    month** (days 1–7 → 1, 8–14 → 2, …), e.g. 2026-09-20 is the 3rd Sunday
    → 3. It doesn't depend on any setting.
  - `weekday` is 1 for the first day of the week, controlled by the **week
    start** setting (Admin → Settings, super admin; default Sunday): Sunday
    start → Sun=1…Sat=7, Monday start → Mon=1…Sun=7. Changing the setting
    recalculates every existing activity (`recompute_activity_calendar()`).
- **Modal forms + sortable tables (all admin pages).** Any
  `<div class="card" data-modal data-add-label="New x">` holding an
  add/edit form is turned into a `<dialog>` by the script in
  [`backend/admin/includes/layout_bottom.php`](backend/admin/includes/layout_bottom.php),
  hidden by CSS until then so it can't flash while the page loads, and opened by a "New x" button (or straight away on `?edit=ID`; closing it
  in edit mode returns to the list). Every table there is sortable by
  clicking a column heading (client-side, over the rows on the page).
- **Edit/Delete icons (every admin table's actions column).** `icon_edit($href)`
  and `icon_delete($onclick = '...')`
  ([`backend/includes/functions.php`](backend/includes/functions.php)) render the
  `.icon-btn` pencil/trash links used in place of "Edit"/"Delete" text, styled in
  `layout_top.php`; both take `title`/`aria-label` from the action name so they
  stay accessible without visible text.
- **Responsive layout.** At `≤720px` (`layout_top.php`) the whole nav —
  dropdowns or the zone/centre admin's plain links — collapses behind a
  hamburger button (`.nav-toggle`, next to the brand); tapping it drops
  `nav.topnav` open as a full-width column below the brand/logout row (each
  dropdown still expands in place as an accordion within it). The toggle,
  the accordion open/close and closing on outside click/Escape are wired up
  in `layout_bottom.php` (`closeNav()`/`closeDrops()`), reusing the same
  `.nav-drop`/`.open` mechanism the desktop dropdowns use. A `≤640px`
  breakpoint drops a `.toolbar-row`'s right-aligned buttons (Filter / Export /
  Import / …) onto their own row under the left-aligned ones (New x / Roll
  forward / …) instead of wrapping into the same crowded row — done by giving
  the first `.push-right` item `flex-basis: 100%` so it (and everything after
  it) starts a fresh flex line; this is what Activities' and Multi-day
  Activities' toolbars do on a phone. A `≤480px` breakpoint tightens
  card/table padding and font size. Every table is wrapped in a `.table-wrap`
  (`overflow-x: auto`) so a wide table scrolls horizontally instead of
  squashing — `layout_bottom.php` adds the wrapper automatically to any table
  that doesn't already have one (Source and Activities wrap their own, for
  the toolbar/filter-count line above them). The Dashboard bar-chart labels
  ([`backend/admin/includes/dash.php`](backend/admin/includes/dash.php)) and the
  Venue/Activity/Group/Section/Priest tick-box filter panels
  ([`backend/includes/multiday_filters.php`](backend/includes/multiday_filters.php))
  also shrink/clamp under these breakpoints. `admin/login.php` had its own
  fixed-width form (a content-box sizing bug made it wider on screen than its
  declared 320px) fixed to `width: 100%; max-width: 320px` with `border-box`
  sizing.
- **Bulk delete (every admin table with Delete links).** An icon at the far left of
  the toolbar (in `layout_bottom.php`) switches the table into selection mode;
  the checkbox column and the "N selected · Delete selected · Cancel" bar are
  hidden otherwise. The script finds deletable rows itself (a row containing a
  Delete form with `action=delete` + `id`), so tables need no markup; the
  checkbox column is always in the DOM (hidden by CSS) so sort column numbers
  don't shift, and a MutationObserver adds it to rows re-rendered by inline
  editing. While selecting, clicking a row ticks it instead of opening the
  inline editor (Shift-click ticks a range); the header box ticks every shown
  row. **Delete selected** confirms and posts `action=bulk_delete` with `ids[]`
  to the page. Each page handles it with `bulk_run()`
  ([`backend/admin/includes/bulk.php`](backend/admin/includes/bulk.php)) around
  the *same* closure its single Delete uses (`$deleteOne`), so scope checks and
  "in use" refusals (centres / lookup entries used by Source, your own admin
  account) apply identically; the result is one summary ("Deleted 3 zones. 1 not
  deleted: …"). Tables that show only the latest N rows (Activities, Source,
  Absences, Multi-day) set `data-bulk-total` (and `data-bulk-extra`, JSON of
  extra fields such as the filter string and zone). Once every shown row is
  ticked and more exist, a link offers "Select all N matching the current
  filters"; that needs the count typed to confirm and posts `all_matching` +
  `expected`, and the page calls `bulk_delete_matching()`, which refuses if
  the count changed meanwhile (ids are selected first, since a WHERE that reads
  the same table can't be used inside a DELETE). For Absences "all" means
  everything the admin may delete (a zone admin: their own zone). Zones delete everything linked to them, as the single Delete does.
- **Spreadsheet-style editing (Activities only).** Clicking a row makes its
  cells inputs; Enter/Save posts `action=inline_save` to the same page,
  which answers with JSON containing the re-rendered row
  (`activity_row_html()`); Esc/Cancel reverts. The table columns are
  Date, Centre, Activity, Priest, From, To and Description: Day, Wk, Section, Labor
  and Duration are not shown (they are still stored and derived on save; an inline
  save keeps the row's existing Labor, which is still editable in the modal form).
  **Duration is always computed as To − From** on save (blank if either is empty or
  To isn't later) and has no form field; rows saved before this keep their old value
  until they're next saved. You must save or cancel a changed row
  before editing another. The Edit link still opens the full modal form. An
  editing cell (`tr.editing td`, same on Source and Multi-day Activities) has
  no padding of its own, so its `.cell-input` (which also has no border of
  its own — just the yellow cell background — on top of that, and a fixed
  `height: 40px`) fills it edge-to-edge instead of leaving a gap around it —
  otherwise editing widened a row (selects/inputs sized for their content)
  more than it needed to, sometimes forcing the table's own horizontal
  scrollbar to appear. The editing row itself (`tr.editing td`) gets a black
  `border-top`/`border-bottom` (the usual light-purple grid colour elsewhere)
  so it stands out from the rows around it — **2px**, not 1px: with
  `border-collapse`, this row's border-top and the row above's border-bottom
  share one collapsed edge, and same-width/same-style conflicts there don't
  reliably resolve in favour of whichever was declared here, so it's made
  strictly wider instead, which always wins unambiguously (confirmed in the
  browser: 1px lost to the neighbour's purple, 2px shows solid black on both
  edges). Its `.actions` cell keeps `padding: 0 8px` (the row's other cells
  stay at `0`) so the Save/Cancel icons below aren't flush against that black
  edge. Save and Cancel (also the same on all three) are icon buttons — a
  check and an ×, built inline
  in each page's own `startEdit()` rather than through
  `icon_edit()`/`icon_delete()` (those are PHP-rendered; these are strings
  inside client-side JS) — sized as small centred squares
  (`tr.editing td.actions button`) instead of the old padded text buttons.
- **Section is derived from the centre**: on save it is copied from
  `centres.section`; the form shows it as small text under Centre. To change
  the section of a centre's activities, change the centre's section (Centres
  page) — that also updates the activities currently using that centre.
  There is no section field on the form.
- *Centre* and *Priest* are dropdowns of the selected zone's centres/priests
  (re-filtered in the browser when a super admin changes the Zone).
- *Activity* and *Labor* are dropdowns fed from the `activity_types` and
  `labors` tables (Admin → Activity types / Labors, super admin).
- **Unit and Frequency were removed from the admin form and table.** The
  `activities.unit` / `mfrequency` columns and the API output are untouched,
  and existing values are preserved when an activity is saved.
- The posted values for day/weekday/week/section are ignored by the server.
- **Filter button** (next to "New activity"): filters by date / date range
  (`date_from`, `date_to`; set both equal for a single date), day, centre,
  section and priest. Filtering is **server-side** via GET params
  (`activity_filters()` validates them), so it searches the whole zone, not
  just the rows on screen; the button shows the number of active filters and
  a "Filtered by …" line with a Clear link appears above the table. Filters
  are carried through Edit/Save/Delete (hidden `qs` field) so you stay on the
  filtered list.
- **Day navigation + celebration** (List tab, `.day-nav` in `index.php`; placed at the left of the toolbar right after **Optimise & Review** via `data-after-left`; every `[data-toolbar-item]` is hidden by CSS until the toolbar script has moved it, so it no longer flashes at its source position on a long page): next to the date picker are ‹ › buttons (previous/next day; only with a single date) and **Today** (browser clock); with a single date, the date text ("Thursday 1 October 2026") is shown beside them with the liturgical celebration under it in lighter grey. The same line ("Celebration [Rank | Class]. Notes", from `liturgical_calendar`; empty if the table or the date's row is missing) comes from `liturgical_day_text()` in [`backend/includes/liturgical_day.php`](backend/includes/liturgical_day.php), shared with the Grid tab and the Calendar's **Day** view (after the Month/Week/Day switch, before the activity count; Month/Week show none).
- **Date picker** (a `Date` calendar input right after "New activity", before the
  right-aligned Filter group; placed by the toolbar script via `data-toolbar-item
  data-before-right`). Choosing a date reloads the list filtered to that single date
  (`date_from = date_to`, other filters kept); clearing it removes the date filter. It
  reads "No date" unless exactly one date is filtered (cleared on purpose, or a From/To range).
  **Default is today:** opening the list with no filters (at most a zone) redirects, in the
  browser, to today's date by the browser's clock; clearing the picker sets a
  `sessionStorage` flag (`actShowAll`) so today isn't re-applied until a date is picked again.
  **Day summary:** with a single date filtered, a "Priests on <date>" box lists each
  priest (alphabetical, "(no priest)" last) with their activities that day (time,
  activity, centre; from the rows shown, so other filters apply). Docked right of the
  table at ≥1410px (sticky; = the 1000px minimum table + 340px box + 16px gap + 32px page padding + scrollbar); below that it floats bottom-right, collapsed to its title
  until tapped, and is hidden by the zone-admin focus toggle. After an inline save it is
  rebuilt in the browser from the table rows (`window.refreshDaySummary()`), so edits
  show immediately; delete and the modal form reload the page anyway. Activities in the
  box show only their start time, and its priest column doesn't wrap. Under each priest's name it shows "Mass: N" in small print: the larger of the Mass rows shown and the server's count for that priest and day (the row's `data-masses`, which also counts other zones), so it follows edits like the rest of the box. The main table
  uses fixed column widths (`table.act-table`, `table-layout: fixed`, Description takes
  the rest) so inline editing doesn't shift the layout. The table is at least 1000px wide
  (it scrolls inside its box on narrower screens) and, when columns are resized, never wider than
  its box, i.e. the screen minus the docked summary box. **Column widths are user-resizable**: drag the
  right edge of a heading (double-click an edge to reset); the first drag freezes all
  columns at their current pixel widths (the table can then grow wider and scroll), and
  the widths are kept in `localStorage` (`actColWidths`), per browser. Because the frame (`.table-wrap`) changes width with the window and the summary box while a resized table has fixed pixel widths, a `ResizeObserver` rescales the columns to the frame's width (min 1000px) whenever it changes. The priest column
  shows the priest's name, their number of activities that day "(N)" and, if they are over
  the limit, the "N masses" badge and, if their activities overlap at different centres, the "bilocation" badge, each under the name (the only flags shown; copied from the rows). Absent priests are always the last rows, after "(no priest)".
  Because one edit can change the mass count of other rows, an inline save redraws the box
  at once from the table and then again from a fresh fetch of the page. The zone's
  priests who are absent that date (from `absences`, honouring the Priest filter) are
  listed even with no activities, with "Activity (dd/mm/yyyy to dd/mm/yyyy)" in red ("Absent" if the absence has no activity); the table has no
  alternating row shading.
- **Optimise & Review** (modal titled "Proposed distribution for <date>"; button right after "New activity", shown only when the date picker has a
  fixed date; code in [`backend/includes/day_optimiser.php`](backend/includes/day_optimiser.php)).
  Posts `action=optimise` (zone + date) and gets JSON back: a proposed priest for every activity
  of that zone on that date (a centre admin: their centre's only), so that none of the flags
  remain. Other activities of the date (another zone, or outside a centre admin's centre) are not
  changed but constrain the result. A priest may take an activity unless he is absent, in charge
  of a multi-day activity elsewhere, or booked at an overlapping time in another centre (by a fixed
  activity); among the proposed rows no priest gets two overlapping activities in different
  centres or more than the mass limit (fixed masses counted). Candidates are the zone's priests
  (`priests_by_zone()`) plus whoever the activity already has. It is a backtracking search
  (200,000-node cap) that keeps the current priest wherever possible, then prefers the least-loaded
  priest; if no full solution exists, as many activities as possible are assigned and the rest are
  reported as unresolved. The **modal** shows time, centre, activity, current priest (with why it
  was in conflict) and a **Proposed priest** dropdown listing the priests who could take it
  (options that would clash with the other rows' current choices are marked ⚠, rows still in
  conflict are red, changed rows green, with a live summary). **Accept** posts
  `action=optimise_apply` (`assign[activity id]=priest`): only the priest column of that day's
  activities is updated (scope re-checked server-side, priests must be the zone's), `zones.last_update`
  is bumped, and a message reports the changes and any remaining conflicts (accepting while
  red rows remain asks for confirmation). Next to the table the modal shows the same **"Priests on <date>"
  summary box** as the page (each priest, their activity count, the activities incl. those that stay
  as they are in grey italics — only for the zone's own priests, so a priest of another zone who happens to be busy that day is not listed —, "Mass: N" in small print under each name (their masses that day incl. fixed ones), **N masses** / **bilocation** badges, absent priests in red), rebuilt in
  the browser on every dropdown change so it always matches the table as shown, and a colour legend
  row above the table. **Reject** just closes the modal; nothing is saved
  until Accept. Toolbar placement uses a new `data-after-left` attribute handled in
  `layout_bottom.php`.
- **Add from source** (button before "New activity"; code in
  [`backend/includes/source_apply.php`](backend/includes/source_apply.php)).
  Takes a start and end date (max 366 days) and fills the activities from the
  [Source](#admin-panel-backendadmin) table: each date takes the source rows
  of the same zone with the same `week` (which occurrence of that day it is in
  the month) and `day` (its number in the week per the week-start setting),
  e.g. 24/09/2026 is the 4th Thursday = week 4, day 5 (Sunday start). The modal has an
  **"Overwrite existing activities in this date range"** checkbox (off by default). *Off:* a date
  that already has activities (the zone's, or the centre admin's centre's) is left as it is, and
  only dates with none are filled; the message says how many dates were kept. *On:* submitting asks
  for confirmation, then on every date that has matching source rows the existing activities are
  **replaced** by them (in one transaction). Either way, dates with no matching rows are
  left untouched. Only the current user's own zone is touched (a super admin,
  who has none, uses the zone being viewed; a posted zone id is ignored for
  everyone else), and a centre admin only gets/replaces their own centre's
  rows. Centre, priest, activity, section etc. are copied from source as
  written (they were validated when they went into source).
  **Class A dates:** afterwards, every date in the range whose `class` is `A`
  in the `liturgical_calendar` table (Admin → Settings generates it) gets a
  `Med` and a `Ben` activity for each centre of the zone **that has a section**
  (the zone's rows in `centres`; only the admin's own centre for a centre admin),
  unless that centre already has that activity on
  that date. These rows have the zone, date, centre, activity, the fields the
  form always derives (week, day, weekday, section) and the **start / end times
  (and duration) from source** for that centre's `Med` / `Ben` (see "Times for
  the automatic rows" below); priest, labor and description are blank. This happens whether or not the date has
  source rows, and is skipped (with a message) if the calendar table is
  missing. `mfrequency` is not
  copied (source allows fractions like 0.33, activities doesn't). Day, weekday
  and week are set from the date as usual, and `zones.last_update` is bumped.
- **Times for the automatic rows** (`source_time_lookup()` / `source_times_for()`
  in `backend/includes/source_apply.php`). The class A `Med`/`Ben` rows and the
  Vigil rows added by *Add from source* or the *New activity* form take `from`, `to` and `duration` from the
  source rows of the **same zone, centre and activity** that have a start time:
  the most common pair among those on the **same weekday** as the date (ties →
  earlier start), else the most common pair over all weekdays (the activity is at a
  fixed time for that centre), else blank. The result message says how many of the
  automatic rows got times, how many of those used the centre's usual times
  because source has none for that weekday, and that the rest are blank.
- **Vigil** (code in [`backend/includes/vigil.php`](backend/includes/vigil.php)).
  The **Thursday before the first Friday of each month** (when the 1st is a
  Friday, that is the last day of the previous month, e.g. 30/04/2026) is a Vigil
  day. When activities are added — the *New activity* form or *Add from source* —
  and such a date **has activities in the zone**, every centre of the zone
  **that has a section** (`centres` table; only the admin's own centre for a centre
  admin) that has no
  `Vigil` that day gets one with only zone, date, centre, activity `Vigil`, the
  derived week/day/weekday/section and the start/end times from source (below).
  - *New activity form:* checked for the date of the activity just created (not on
    edits, and not if the add was refused as a duplicate). The success message says
    "Also added N Vigil activities".
  - *Add from source:* checked for every date in the range, after the source rows
    and the class A rows are in, so a Vigil date with no source rows still gets its
    Vigils if activities already exist on it; the message says "Vigil: added N …".
  - A date with **no** activities in the zone gets nothing (the rule is "whenever
    the date is among those in the activities list"). Centres that already have a
    Vigil that day (from source, by hand or an earlier run) are skipped, so
    repeating it is safe.
- **Export** (right of the Filter button; code in
  [`backend/includes/activity_io.php`](backend/includes/activity_io.php)).
  There is no import button any more (the import code is still in
  `activity_io.php`, and its file readers/parsers are reused by the Source
  import). Columns: `zone, section, week, day, duration, date, priest,
  activity, description, labor, centre, from, to`.
  *Export* first opens a modal asking whether to filter what's exported
  (the same date range / day / centre / section / priest fields as the
  Filter modal, prefilled with the current filters). "Export" downloads
  what those fields match (everything in the zone if they're empty), and
  "Export all" ignores any filters. It's a UTF-8 CSV of all matching rows
  (not just the 300 shown) using those columns. Text that
  would be read as a spreadsheet formula (`=`, `+`, `-`, `@`) gets a
  leading `'`.
- **Absent priests** (code in [`backend/includes/absences.php`](backend/includes/absences.php);
  data from the [Absences](#admin-panel-backendadmin) page). Nothing is stored on
  the activity: it is worked out whenever the page is built, so editing or
  deleting an absence takes effect immediately.
  - *Clash rule:* same priest (matched by name, whichever zone recorded the
    absence) and the activity's time span overlaps the absence: from = date +
    `from_time` (00:00 if empty), to = date + `to_time`, else `from_time` + 1
    minute, else end of day (no times = whole-day). Touching at a boundary is not
    a clash. `absence_overlap_sql()` is the single definition; the table, the
    filter, the save warning and the source report all use it.
  - *Table:* the whole row is tinted light red (`tr.absent`) and the priest
    has a red **absent** badge whose tooltip is the absence's dates and
    activity. Inline editing re-renders both on save (the row flashes a deeper
    red instead of green); while a row is being edited it shows the normal
    yellow editing colour.
  - *Filter / Export:* "Only activities whose priest is absent" checkbox
    (`absent=1`).
  - *Saving:* never blocked. The modal form's success message becomes a red
    warning naming the priest and absence.
  - *Add from source:* source rows know nothing about absences, so afterwards the
    range is checked and the message lists the absent priests (with counts); see
    "After Add from source" below for how the list is filtered.
  - Skipped silently if migration `012` has not been applied.
  - The warning banner (`.flash.warning`, used for all of the above) is red.
- **Duplicate activities** (code in
  [`backend/includes/activity_duplicates.php`](backend/includes/activity_duplicates.php)).
  Two activities are duplicates when they have the same zone, date, centre,
  activity, priest, from time and to time (empty matches empty; description and
  labor are *not* compared; activities without a date are never duplicates).
  Duplicates are **prevented on the way in, and flagged if they exist anyway**:
  - *New activity form:* an activity identical to an existing one is **not
    added**; the page says "Not added: an identical activity already exists".
  - *Add from source:* identical source rows are added only once; the extra ones
    are skipped and listed in the message ("Skipped N duplicate source rows: Mass
    at C 10:00 (Fr. X) on 2026-11-05, …"). (Existing activities on those dates are
    replaced by design, so they are never "duplicates" of the new rows.)
  - *Not prevented:* editing an activity into a duplicate (modal or inline), and
    duplicates already in the data. These are flagged: a blue **duplicate** badge
    next to the activity (tooltip: how many others) and a light blue row. Every
    copy is flagged. `activity_duplicate_sql()` is the single definition.
  - *Filter / Export:* "Only duplicates" checkbox (`duplicate=1`).
  - *Saving an edit:* the warning says "identical to N other activities".
- **Priest in a multi-day activity** (code in
  [`backend/includes/activity_multiday_busy.php`](backend/includes/activity_multiday_busy.php)).
  A priest in charge of a multi-day activity (the Priest in charge field, migration `016`) is
  not free for other activities while it runs. Worked out on the fly and only flagged, like
  absences. An activity clashes when it has the same priest (by name, any zone), is **not at the
  multi-day activity's own centre** (same zone + centre; a retreat leader may still say Mass
  there) and its time span overlaps the multi-day one's: start date + start time to end date +
  end time. The times are freeform and usually blank, so a blank start counts as 18:00 (evening
  arrival) and a blank end as 12:00 (morning departure), as the calendar paints them; text that
  isn't HH:MM counts as blank; an activity with no times is a whole-day one (as for absences), so
  it clashes on the first and last day too. `activity_in_multiday_sql()` is the single definition.
  - *Table:* a teal badge **priest in cv | LS** (activity | centre; several joined by commas;
    tooltip: "In charge of cv | LS (06/12 to 12/12)") and a light teal row. Row colour order: no
    priest, absent, in multi-day, bilocation, over the mass limit, duplicate.
  - *Filter / Export / legend:* "Only activities whose priest is in charge of a multi-day activity
    at another centre" (`in_multiday=1`) and a **Priest in multi-day** legend box.
  - *Saving (new or edit):* warns "Fr. X is in charge of cv | LS (06/12 to 12/12) then".
  - *Add from source:* the range is checked and the warning names the priests and counts.
  - Skipped silently if the multi-day table or its priest column is missing.
- **Priest bilocation** (code in
  [`backend/includes/activity_bilocation.php`](backend/includes/activity_bilocation.php)).
  Not the same as a duplicate: **the same priest on the same date with
  overlapping times in different centres**. Overlaps within one centre (e.g. a
  Mass and a Confession in the same church) are not bilocation. A centre is
  identified by zone + name. The priest is matched by name across zones (one
  person), so a priest double-booked between two zones is caught; the tooltip adds
  the other zone's name, e.g. "Mass at Centre 10:15-10:45 (Zone name)". Both activities need a from time (an activity without times is
  not compared); an activity lasts from `from_time` to `to_time`, or one minute if
  there is no `to_time`; 10:00–11:00 and 11:00–12:00 do not overlap. An exact
  duplicate (same centre) is therefore never bilocation. `activity_bilocation_sql()`
  is the single definition. It is only flagged, never blocked:
  - *Table:* an amber **bilocation** badge next to the priest (tooltip: "Overlaps
    with Mass at Centre 10:30-11:30; …") and a light amber row.
  - *Filter / Export:* "Only priest bilocation" checkbox (`bilocation=1`).
  - *Saving (new or edit):* warns "it overlaps another activity of Fr. X (…)".
  - *Add from source:* the range is checked and the message reports how many
    activities overlap another one of the same priest.
  - Uses index `idx_activities_date_priest` (migration `013`).
- **Mass limit** (code in
  [`backend/includes/activity_mass_limit.php`](backend/includes/activity_mass_limit.php)).
  A priest should not have more than `mass_limit()` masses on
  the same day. The limit is the **`max_masses_per_day` setting** (Admin →
  Settings → "Maximum masses per priest per day", super admin; whole number 1–24,
  default 2; migration `014`, and the default is used if the row is missing).
  It is read once per request (`mass_limit($pdo)` at the top of the Activities
  page) and applies straight away; changing it changes no data. A mass is an activity named `Mass` (the `PASTORES_MASS_ACTIVITY`
  constant; the "Mass" entry of Activity types). The priest is matched by name
  across zones, so masses in several zones add up. **Flagged, not blocked** (the
  limit is a rule of thumb that has legitimate exceptions, and blocking would
  make Add from source silently drop rows). When a priest is over the limit,
  *every* mass of theirs that day is flagged (there is no way to say which one is
  the extra), and other activities are not:
  - *Table:* a purple **N masses** badge next to the priest (tooltip: "Fr. X has
    3 masses on this day (maximum 2)") and a light purple row.
  - *Filter / Export:* "Only masses of priests with more than 2 masses in a day"
    checkbox (`masses=1`).
  - *Saving (new or edit):* warns "Fr. X now has 3 masses that day (maximum 2)".
  - *Add from source:* the range is checked and the message names the priests
    and dates (e.g. "Fr. M on 05/11 (3)").
  - The activity name (`Mass`) is the `PASTORES_MASS_ACTIVITY` constant at the top
    of that file. Uses index `idx_activities_date_priest` (migration `013`).
- **No priest assigned** (code in
  [`backend/includes/activity_no_priest.php`](backend/includes/activity_no_priest.php)).
  There must be a priest for every activity, but this is **flagged, not
  blocked** — some rows legitimately have none yet, e.g. a class A Med/Ben row or
  a Vigil row added automatically (they only get a centre and activity; see
  "Vigil" above), until someone fills the priest in.
  - *Table:* a grey **no priest** badge in the priest cell (there is no name to
    put it next to) and a light grey row. It never applies together with absent /
    bilocation / over the mass limit (those all require a priest to compare), but
    can combine with duplicate (two priest-less activities can still be
    identical); grey wins that combination.
  - *Filter / Export:* "Only activities with no priest assigned" checkbox
    (`no_priest=1`).
  - *Saving (new or edit):* warns "no priest is assigned".
  - *Add from source:* source rows can also have no priest (it's optional there
    too); the range is checked and the message reports the count.
- **Row colours summary** — grey = no priest, red = priest absent, teal = priest in a multi-day activity, amber =
  priest bilocation, purple = over the mass limit, blue = duplicate; when several apply
  the row takes the first of that order and shows every badge. Inline saves
  flash the row in the matching stronger shade.
- **After "Add from source"** the range is checked for no priest, absent
  priests, priest bilocation, the mass limit and duplicates, each reported in the red
  warning; the list then opens filtered to the first of them that applies (in
  that order), and the message says to use Filter for the others.
- **Legend** — a row of coloured boxes above the table, one per rule above
  (no priest, priest absent, priest bilocation, over the mass limit, duplicate), each
  showing how many of the zone's activities currently match it. The count
  respects the date/day/centre/section/priest filters that are set, but ignores
  any of these five filters that is already active, so every box always shows
  the true total for the rest of the current view. Clicking a box with a count
  above zero opens the list filtered to just that rule (replacing whichever of
  the five was active before, keeping the other filters); the box in question is
  outlined once its filter is the active one. A box at zero is plain text, not a
  link, so clicking it does nothing.
- The table shows the latest 300 matching activities of the zone; sorting
  only reorders those rows.

**Source** ([`backend/admin/source/index.php`](backend/admin/source/index.php),
super admin only; migration `backend/migrate/007_source.sql`): a separate
`source` table with the same columns as `activities` and a page that
replicates the Activities page's functions and buttons (New / Filter /
Import / Export, spreadsheet-style row editing, sortable table). Differences
from Activities:

- **`day` is a number**, not `Mon`…`Sun`: the day of the week counted from
  the **week start** setting, i.e. the same value `activities.weekday` holds
  (Sunday start: Sun=1, Mon=2, Tue=3 …; Monday start: Mon=1 … Sun=7). The
  separate `weekday` column doesn't exist, since it would duplicate `day`.
  Changing the setting renumbers every source row (`recompute_activity_calendar()`
  shifts them, since there's no date to derive them from).
- **No date.** Source rows are a weekly pattern (as in the "Pastoral
  Attention" spreadsheet), not dated events, so `week` (nth occurrence of that
  day in the month) and `day` are chosen by hand, in the New/Edit form and in
  the inline editor. There's no date column, date field or date filter.
- **All zones in one table**, with a Zone column instead of a zone picker;
  there's a Zone filter (Filter/Export modals). Shows up to 5,000 rows.
  Zone is changed via the Edit modal, not inline.
- **Source only holds values that exist in the lookup tables.** `centre`,
  `priest`, `activity` and `labor` are text (as in activities), but they must
  be entries of `centres` (of the row's zone), `priests` (of the row's zone),
  `activity_types` and `labors`; the section is always the centre's. This is
  enforced in code, not by foreign keys (`source_resolve()` /
  `source_lookups()` in `source_io.php`): the New/Edit form, the inline editor
  and the import all reject an unknown value with a message saying where to
  add it. A row's existing legacy value doesn't block editing its other cells.
  In the other direction, on the lookup pages: renaming a priest / section /
  labor / activity type cascades to source (as to activities); renaming a
  centre updates its source rows (name and section); deleting an entry that
  source uses is refused, and so is moving a centre or priest to another zone
  while that zone's source rows use it. There is no `unit` column (it copied
  the zone name and would go stale when a zone is renamed); the importer still
  reads a `unit` column as the zone. `mfrequency` is `DECIMAL(4,2)` (1, 0.33).
  `migrate/010_source_integrity.sql` brought the existing data in line: it
  added the centres, priests and activity types found in source to their
  tables, set source sections from the centres, and dropped `unit`.
- **Import/export** ([`backend/includes/source_io.php`](backend/includes/source_io.php),
  reusing the readers/parsers in `activity_io.php`). Columns:
  `zone, week, day, centre, activity, section, labor, priest,
  from, to, duration, mfrequency, description`; anything else (e.g. `ctrname`,
  `weekday`) is ignored. Zone comes from `zone`, else `unit`, and must
  already exist. A row needs `week` + `day`. Centre, priest (both of that
  zone), activity and labor must exist in the admin lists, otherwise the row
  is reported (with the unknown values listed) and skipped; the section is the
  centre's (a `section` column is ignored). A negative/`#VALUE!` duration is imported as empty.
  Duplicates (same zone, week, day, centre, activity, times, priest,
  description) are skipped, so re-importing is safe.
- Saving does not bump `zones.last_update` (source isn't served by the API).

**Absences** ([`backend/admin/absences/index.php`](backend/admin/absences/index.php),
super and zone admins; migration `backend/migrate/012_absences.sql`): a list of
priests' absences with the fields zone, priest, start date and time, end
date and time, activity and description. Same layout as the other list pages (New button →
modal form, sortable table, latest 300 rows). A zone admin only sees and edits
their own zone's rows; a super admin picks the zone. Notes:

- The **priest** must be one of the zone's priests (home zone or "also serves
  in", `priests_by_zone()`); the form's dropdown follows the selected zone.
  **Activity** is free text with the `activity_types` list as suggestions.
  Both are stored as text, so renaming a priest / activity type on its lookup
  page cascades to absences.
- The end must not be before the start.
- Not served by the API and does not bump `zones.last_update`; nothing in the
  frontend reads it yet. The Activities page checks against it — see
  "Absent priests" above.

**Multi-day Activities** ([`backend/admin/multiday_activities/index.php`](backend/admin/multiday_activities/index.php)
for the list/CRUD, [`calendar.php`](backend/admin/multiday_activities/calendar.php) for the
painted venue view, [`dashboard.php`](backend/admin/multiday_activities/dashboard.php) for the
statistics; **super admin only** (zone and centre admins get a 403, and the nav link
and dashboard count are hidden from them); migration
`backend/migrate/015_multiday_activities.sql`): retreats, courses and camps
that run across several days at a centre, ported from a standalone
"Painted Calendar" HTML tool that had no backend of its own. Kept in its own
table (`multiday_activities`), not `activities`, so the day-to-day Activities
view never needs to filter these out. The three pages are tabs of one section
(List / Calendar / Dashboard), linked from the top nav's Activities dropdown ("Multi-day") and with a
count on the dashboard (guarded by `multiday_activities_available()` in
[`backend/includes/multiday_activities.php`](backend/includes/multiday_activities.php),
like `absences_available()`, so the dashboard still loads before migration
`015` is applied). Migration `016` must be applied before the List tab or the
Priests page is used (the Priests page counts/cascades on `multiday_activities.priest`), and
`017` (`roll_rule`) before the List tab too.

- **Data model.** Migration `015` adds `activity_types.is_multiday` — a flag on
  the *type*, not derived from the name (a name like `Mass St. Josemaria` is
  one day; `crt` spans several) — and seeds the 13 activity types the original
  tool used with it set to 1. It is edited with the **Multi-day programme**
  checkbox on Admin → Activity types (the list has a Multi-day column; the
  checkbox is an option of the shared `lookup_admin_page()`, only shown once
  migration `015` is applied). Renaming an activity type also renames it on
  the `multiday_activities` rows that use it. `multiday_activities` holds
  zone, centre, activity, section, labor ("group" in the original tool),
  priest (in charge; optional, added by migration `016`),
  `start_date`/`start_time`, `end_date`/`end_time` and a description. Centre,
  activity, section and labor are stored as text (same convention as
  `activities`), and the times are freeform and optional.
- **List / CRUD** (`index.php`, same layout as Absences: modal form, sortable
  table, latest 300 rows). The Activity dropdown only offers `activity_types`
  with `is_multiday = 1`; Centre and **Priest in charge** follow the selected
  zone (the priest must be one of the zone's priests, `priests_by_zone()`, and
  is optional; renaming a priest cascades to these rows). The page uses the
  same full width as the Calendar tab (`$pageWide`) so switching tabs doesn't
  resize the content. The end (date, and
  time if both are on the same day) must not be before the start, and the
  centre must belong to the chosen zone. The scope checks for zone admins
  (`mday_in_scope()` and the zone-restricted queries) are still in the code as
  defence in depth, but the pages are super-only, so every zone is visible.
  Leave the times blank for the usual pattern (evening arrival, morning
  departure).
  - **Spreadsheet-style editing.** As on Activities, clicking a row turns its
    cells into inputs (dropdowns for Centre / Activity / Section / Group /
    Priest, a date + time pair for Start and End, a textarea for Description);
    Enter (in a single-line field) or Save posts `action=inline_save` to the same
    page, which answers with JSON containing the re-rendered row
    (`mday_row_html()`) or `{error}` (403 out of scope, 422 validation);
    Esc/Cancel reverts. You must save or cancel a changed row before editing
    another, and picking a start date moves an empty or earlier end date up to
    it. The zone isn't editable inline (use the Edit link). Centre has no blank
    option (it is required); the other dropdowns have one, to clear the value.
    Validation is shared with the modal form (`mday_fields()`), so both apply
    the same rules.
  - **Bulk delete** works here as on every admin table — see "Bulk delete" under
    the modal-forms bullet above. On this page `data-bulk-extra` carries the filter
    string (`qs`), so "select all N matching the filters" deletes through
    `mday_where()`.
  - **Filter, search, Import, Export** (a search box followed by the
    buttons, above the table; the box uses `data-toolbar-item data-before-right`
    in [`layout_bottom.php`](backend/admin/includes/layout_bottom.php) to sit before
    the right-aligned Filter button). Filtering is **server-side** via GET params
    (`mday_filters()` validates them, `mday_where()` builds the SQL), so it
    searches every entry, not just the 300 shown: zone (super admin only),
    centre, activity, section, group, priest, and a date range ("Running from" /
    "Running until" — an entry matches if it runs on at least one day of the
    range, i.e. `end_date >= from AND start_date <= until`). The **search box**
    (`q`, Enter to apply) matches text in zone name, centre, activity, section,
    group, priest and description; every word must appear in at least one of them, and
    `%`/`_` are taken literally. The Filter button shows the number of active
    filters and a "Filtered by …" line with a Clear link appears above the
    table. Filters (including the search) are carried through Edit / Save /
    Delete / inline saves / Import (hidden `qs` field) so you stay on the
    filtered list. The dropdown values are those found in the entries in scope.
    - *Export* opens a modal with the same filter fields (prefilled from the
      current filters): "Export" downloads what they match, "Export all" ignores
      them (a zone admin's own zone only either way). It is a UTF-8 CSV of every
      matching entry, not just the 300 shown, with the columns `zone, centre,
      activity, section, labor, priest, start_date, start_time, end_date,
      end_time, description, roll_rule` (text starting with `=`, `+`, `-`, `@` gets a
      leading `'`).
    - *Import* (code in [`backend/includes/multiday_io.php`](backend/includes/multiday_io.php),
      reusing the file readers and date/time parsers in `activity_io.php`) reads
      a CSV or .xlsx upload, or a Google Sheets link (shared as "Anyone with the
      link can view"). The first row is a header; columns can be in any order
      and the exported names are accepted, plus aliases (`venue`, `group`,
      `start`, `end`, `priest in charge`, …); unknown columns are ignored. A row
      needs `centre`, `start_date` and `end_date` (end not before start);
      times are optional. The zone comes from `zone`, else the zone currently
      filtered on; a zone admin's rows always go into their own zone and a row
      naming another zone is refused. Centre and priest (of that zone), activity,
      section and labor must exist in their admin lists (matched
      case-insensitively, stored spelling wins) — a blank section stays blank,
      not the centre's, so re-importing an export doesn't create near-duplicates.
      Rows with problems are reported (row number and reason, plus a list of the
      unknown values to add) and skipped; valid rows are still imported in one
      transaction. Rows identical to an existing one (same zone, centre,
      activity, section, group, priest, dates, times and description) are
      skipped, so importing the same file twice is safe.
  - *Roll rule.* Each entry has a **Roll rule** (`roll_rule`, migration `017`;
    Exact date / Stick to weekend / Flexible, default Flexible), a column in the
    table that is edited inline or in the entry form, and an optional
    `roll_rule` column in the import/export (blank = flexible; accepts
    `exact`, `weekend`, `flexible`).
  - *Roll forward* (the **Roll forward** button right after **New entry**;
    code in [`backend/includes/multiday_rollforward.php`](backend/includes/multiday_rollforward.php)),
    ported from the original's "Roll Forward to New Year". Choose the zone (super
    admin; blank = all zones — a zone admin always rolls their own), the **From**
    year (default: the latest year with entries) and **To** year, and
    **Generate preview** (a GET to the same page, `roll=1&roll_*`). Every entry
    starting in the From year is copied into the To year with its dates moved
    by its rule — *Exact date* keeps the calendar date, *Stick to weekend* and
    *Flexible* both take the nearest same weekday (forward before backward, up
    to a week) — and its length, times, priest, group etc. preserved. *Free* and
    *Maintenance* entries are not copied but regenerated as placeholders, per
    the original: a Free day after each `ca` entry ends (if open; it may fall
    up to 3 Jan of the next year); at least one Free day per month for each venue
    with entries; and a minimum number of Maintenance days (default 14) for
    the listed venues (default `Iroto, Iwollo` — editable, since the original
    hard-coded them), greedily placed in the largest open gaps, with a warning
    row when they don't fit. Placeholders occupy whole days (times 00:00–23:59)
    and are not checked against bookings outside the roll's own venue/year.
    Unlike the original, which replaced its whole log, **Apply** (`roll_apply`,
    which recomputes the plan and inserts it in one transaction) only *adds*
    rows: the From year is untouched, rows already present in the To year
    (identical zone, centre, activity, section, group, priest, dates, times,
    description) are skipped, and days already booked in the To year, existing
    Free months and existing Maintenance days are taken into account, so
    re-running a roll is safe (it adds nothing new). The `Free` / `Maintenance`
    activity types are created (multi-day) on first use. Afterwards the list is
    filtered to the To year.
- **Calendar view** (`calendar.php`, read-only): one block per month, each with
  a bold border and a gap below, and one swimlane per venue (centre) with a
  small gap between venues.
  - *Painting.* Each entry is painted across the days it occupies, coloured by
    section (`sf` pink, `sv` blue, other grey) and fully encircled by a bold
    outline: top/bottom edges follow the entry's topmost/bottommost occupied
    slot of each day, joined by short step connectors where they change height,
    with thicker start/end edges — as in the original. A label
    (`activity-group`) is printed once, at the midpoint of the run. Since
    times are freeform, painting still uses three bands per day
    (Morning <12:00 / Afternoon <18:00 / Evening); a blank start time counts as
    evening and a blank end time as morning (`mday_slot()`).
  - *Tooltip.* Hovering a day with activity shows a dark tooltip like the
    original: venue and activity, `Priest: …` (only when one is set), `Grp: … · Sec: …`, the date range (with the
    time, or morning/evening when none is set) and the description, one block
    per entry when several overlap. It is a single fixed-position element fed
    from a hidden `.mday-tip` in each cell, so the scrolling grid doesn't clip
    it.
  - *Clashes.* Entries at the same centre whose occupied slots genuinely
    overlap are outlined with a dashed red border and counted in a banner
    (`mday_find_clashes()`); back-to-back handovers (one ending in the morning,
    the next starting in the evening) are not clashes. Grouping is by
    zone + centre, so two zones reusing a centre name are never merged, but
    only the centre name is shown.
  - *Filters.* **Year** first, then **Zone** (super admins only; "All zones" is
    the default, and only zones that have multi-day activities are listed;
    changing it reloads the page and clears the Venue ticks, since that list is
    per zone), then **Venue**, **Activity**, **Group**, **Section** and **Priest** as
    tick-box dropdowns (several values can be ticked, with All/None; nothing
    ticked means no filter), matching the original. There is no Apply button:
    each change re-fetches the page and swaps in the new banner and grid
    without a reload, leaving the dropdown open (falling back to a normal
    submit if that fails), and the URL is updated so the view stays linkable.
    Venue lists the zone's centres (every centre in "All zones"); Activity is
    the `is_multiday` types; Group and Section come from `labors` / `sections`; Priest lists the zone's
    priests (every zone's in "All zones"; `priests_by_zone()`) and matches the
    entry's priest in charge.
    The filter card, its tick-box dropdowns and the live-refresh script are
    shared with the Dashboard tab and live in
    [`backend/includes/multiday_filters.php`](backend/includes/multiday_filters.php)
    (`mday_view_filters()`, `mday_filter_options()`, `mday_render_filter_form()`,
    `mday_render_filter_assets()`; the dropdown styles are emitted by the form itself, `mday_render_filter_styles()`, and the Activities List page's own `<style>` sits right after the layout, both so nothing renders unstyled while a long page loads — don't move them to the end of the page).
- **Dashboard view** (`dashboard.php`, read-only): statistics over the same rows as
  the List tab, ported from the original tool's dashboard tab. It uses the
  Calendar tab's filters (Year, Zone, Venue, Activity, Group, Section, Priest, live
  refresh, linkable URL). Counting follows the original: an entry counts on
  each day it runs inside the selected year (one spanning New Year is
  clipped), and a venue's booked days are the *union* of its entries' days, so
  overlaps never count a day twice. Contents: stat cards (Total Entries,
  Venues Active of all centres in scope, Groups Active of all groups,
  Priests in Charge with the number of entries that have none, Activity-Days,
  Average length); **Venue Utilisation** (days booked / days in the year,
  coloured per venue from the original palette by a hash of the name);
  **Group Utilisation** (share of activity-days); **Monthly Load**
  (activity-days per month); and, beyond the original, **By Activity**, **By
  Section** (sf / sv / other, in the calendar colours) and **By Priest in
  Charge**, each as activity-days and entries.
- **Initial data.** The Painted Calendar's 148 rows were loaded once into the
  local MAMP database with a throwaway script (not in the repo). Venue → centre
  mapping: Iroto and Alayo became new centres in a new zone **Ijebu-Ode**,
  Iwollo a new centre in a new zone **Iwollo**, and the rest reused existing
  centres — Irawo → Rao and Imoran (Ibadan), Lagoon → LS and Whitesands → WS
  (VI-Lekki), Greendale → Gdl (Nsukka). Eleven new `labors` were added for the
  group codes (`n`, `nax`, `sss+`, `agd`, `cl`, `s`, `sacd`, `RVS`, `Admins`,
  `LS`, `Iwollo H`); every source row used evening-start/morning-end, so all
  imported with blank times. Re-running it elsewhere would need the same zones
  and centres created first (or the mapping adjusted).
- Not served by the API and does not bump `zones.last_update`; nothing in the
  frontend reads it yet. The original tool's CSV/XLSX import
  is the List tab's Import, its dashboard tab is the Dashboard tab and its
  roll-forward is the List tab's Roll forward. (Inline spreadsheet-style editing
  was added on the List tab afterwards.)

### Liturgical calendar

A table with one row per day of the General Roman Calendar (celebration,
class, rank, colour, season, ...), generated on demand from the admin panel.

**How it works**

- [`backend/includes/romcal/Romcal.php`](backend/includes/romcal/Romcal.php)
  is a PHP port of **ROMCAL 6**, a C program by Kenneth G. Bath (the version
  in use is the customised build supplied as `romcal.zip`, whose `fixed.dat`
  has the community's own additions and annotations). It
  computes one Gregorian year from Easter, the seasons and the fixed-date
  celebrations. It is plain PHP — no compiler, `exec()` or extension — so it
  runs on shared hosting. Its header comment lists where it deliberately
  differs from the C code. **Licence:** non-commercial use only; keep
  [`NOTICE.txt`](backend/includes/romcal/NOTICE.txt) with it.
- [`backend/includes/romcal/fixed.dat`](backend/includes/romcal/fixed.dat) is
  the data file, in ROMCAL's original format (`MONTH day RANK COLOR text`;
  RANK `O M F L S` or `V` = votive; COLOR `G R W P`). It is read at run time,
  so **edit it and regenerate — there is no build step**. In the text, the
  first tab-separated piece is the celebration name, a lone `A`–`E` is the
  class, and every other piece (So Ex, Te Deum, novena days, "Mass of
  Thanksgiving", ...) is kept in `notes`, separated by ` | `. The file
  contains some personal/family entries (e.g. saint's-day notes) — review it
  before exposing this data to end users.
- [`backend/includes/liturgical_calendar.php`](backend/includes/liturgical_calendar.php)
  reads the options, runs `Romcal::generate()` for each calendar year the
  window touches, and writes the rows.
- The UI is a card in
  [`backend/admin/settings/index.php`](backend/admin/settings/index.php)
  (super admin only): four option checkboxes, **Generate calendar** (with a
  confirm prompt), **Save options only**, a status line (rows, date range,
  last generated) and a preview of the next 14 days. The POST handler there
  now dispatches on an `action` field (`save_week_start` — the default, so old
  posts still work —, `save_calendar`, `generate_calendar`).

**What "generate" does.** It computes a rolling 12 months starting today
(today through the day before the same date next year, so 365 or 366 days; it
spans two calendar years unless run on January 1) and **replaces** those days in
`liturgical_calendar` inside one transaction — if anything fails nothing
changes and the admin sees an error. Rows for dates before today are left as
history, so each run only adds the days that have passed since the previous
window and rewrites the rest.

**Table** (`backend/schema.sql`, migration
[`009_liturgical_calendar.sql`](backend/migrate/009_liturgical_calendar.sql)):
`cal_date` (primary key), `celebration`, `class` (`A`–`E`, or null; Sundays
default to `C` like ROMCAL's text output), `liturgical_rank` (Solemnity,
Feast, Feast of the Lord, Memorial, Optional memorial, Commemoration, Votive,
Sunday, Weekday, Ash Wednesday, Holy Week, Triduum), `color`, `season`
(Advent, Christmas, Ordinary Time, Lent, Easter), `notes`, `votive`,
`devotion`, `generated_at`. `votive`/`devotion` hold what ROMCAL's text output
printed under each day: Tuesday *Psalm 2*, Thursday *Adoro te*, Saturday
*Salve*, and on green Tue–Sat the votive Mass of the day (`liturgical_rank` is
named that way because `rank` is a reserved word in MySQL 8).

**Options** are stored in `settings` as `calendar_ascension_on_sunday`,
`calendar_epiphany_on_jan6`, `calendar_corpus_christi_on_thursday` (all default
`0`, ROMCAL's own defaults: Ascension Thursday, Epiphany and Corpus Christi on
Sunday) and `calendar_optional_memorials` (default `1`). They take effect the
next time the calendar is generated; earlier rows keep the values they were
generated with.

**Deliberate differences from the C program**: Dec 30 is set as Holy Family
when Christmas falls on a Sunday (the C code had an out-of-bounds write there;
next occurrence 2033), and Jan 1 / Dec 25 / Ash Wednesday–Holy Week / Easter
octave get a proper `season` (Christmas / Lent / Easter). The port was
compared with the compiled C program for every day of 1990–2100 under six
option combinations (243,252 days): identical apart from the Dec 30 fix.

**Not connected to the frontend.** Nothing reads this table yet. The schedule's date
headers ("(C) Mass of St Joseph") still come from the old Google Apps Script; the
backend's own `masses` table and `api/masses.php` were removed. If the calendar should
feed those headers, add an endpoint that returns `class` → `Class`, `celebration` → `Mass`
per date.

### Priests load summary (admin home)

Under the count cards on the admin home page ([`backend/admin/index.php`](backend/admin/index.php)),
[`backend/includes/pastoral_dashboard.php`](backend/includes/pastoral_dashboard.php) shows how
priests' work is spread, adapted from the standalone `pastoral-dashboard.html`. Data: `activities`
dated in the chosen **Period** (`pd_days`: next 7/30/90 days, default 30) plus, if migration `015`
is applied, `multiday_activities` overlapping it — grouped by zone, centre, labor and priest in
SQL and passed to the browser as JSON, which computes everything else. Scoped like the other
pages (zone admin: own zone; centre admin: own centre, no roster). *Load* = weighted engagements:
an activity weighs its labor's weight (default 1), a multi-day programme its own (default 3);
weights are edited under **Weights** and kept in `localStorage`. Panels: KPI cards, priest load
bars (black tick = average, red above 130 % of it, amber below 60 %, filterable by zone), alerts
(overload, activities without a priest, priests on the `priests` list with nothing assigned,
cross-zone service, priests not on the list, centres shared by 3+ priests), most/lightest
centres and a zones table. Differences from the original: no pasted-sheet import (the data is the
database) and no dark-mode toggle (it uses the admin theme colours).

### Import of Structure tables (Settings → Import data)

Super admin. One card on the Settings page imports into any table of the Structure menu —
Zones, Centres, Sections, Labors, Activity types — from a CSV or XLSX upload or a Google
Sheets link (same readers as the Activities/Source importers in
[`backend/includes/activity_io.php`](backend/includes/activity_io.php); sheet must be shared
"Anyone with the link can view"). Code: [`backend/includes/structure_import.php`](backend/includes/structure_import.php).
First row = header, columns in any order: Zones `name`; Centres `zone`, `name`, `section`
(optional); Sections `name`; Labors `name`; Activity types `name`, `is_multiday` (optional,
yes/no/1/0). Import **only adds**: rows whose key already exists (name; zone + name for
centres, case-insensitive) are skipped and nothing existing is changed. A centre's zone
and section must already exist, so import Zones/Sections first. Invalid rows (empty or
too-long name, unknown zone/section) are rejected and listed (first 25) under the card;
the rest are added in one transaction. Result: "added N, already there N, rejected N".

### Admin theme

The admin panel's colours follow a `theme_mode` setting (Admin → Settings → **Theme**,
super admin; stored in `settings`, no migration): **Default** is sky blue (`#0284C7`);
**Liturgical season** uses today's `color` in `liturgical_calendar` — Green `#2E7D32`,
Red `#C62828`, Purple `#673AB7`, Rose `#B0396A`, and gold `#94700A` in place of White
(a white theme would vanish into the page). With no calendar row for today (not
generated yet) the default is used. Code: [`backend/includes/theme.php`](backend/includes/theme.php)
emits one `:root` block of CSS variables (`--brand`, `--brand-dark`, `--tint-*`, the tints
mixed from the brand with `color-mix()`) from `layout_top.php` and `login.php`; pages use
`var(--brand)` etc. rather than hex. Today's colour is read per request using the server's
clock. The Next.js frontend is not themed.

### Local development

The backend runs directly against MAMP without copying anything into
MAMP's `htdocs` — see [backend/README.md](backend/README.md) for the full
setup, but in short:

```bash
# MySQL: MAMP's bundled MySQL on port 8889 (not the 3306 default)
# backend/config.php points at it (gitignored — copy config.sample.php elsewhere)

/Applications/MAMP/bin/php/php8.3.30/bin/php -S localhost:8000 -t backend
```

Then `http://localhost:8000/admin/login.php` and
`http://localhost:8000/api/zones.php` etc.

### Migration from Google Sheets

[`backend/migrate/migrate.php`](backend/migrate/migrate.php) is a one-time
CLI script that pulls current data straight from the live Google Apps
Script endpoints and imports it into MySQL (zones, centres, users,
activities). Run once against a target database; re-running
duplicates activities unless that table is truncated first. The
real production data has already been migrated into the local MAMP
database during backend development — see `backend/README.md` before
re-running this against any database you don't want duplicated rows in.

SQL migrations `002`–`018` in `backend/migrate/` are incremental schema
changes for databases created before the change (all safe to re-run). The
latest is `018_drop_masses.sql` (drops the `masses` table). Apply any that an environment (e.g.
production) is missing before using the feature they belong to:

- `009_liturgical_calendar.sql` — Settings → Generate calendar; without it the
  card says the table is missing.
- `012_absences.sql`, `013_activities_priest_index.sql`, `014_max_masses_setting.sql`
  — Absences and the Activities-page checks (absent priest, bilocation, mass
  limit); `014` is optional, since the default of 2 is used without it.
- `015_multiday_activities.sql`, `016_multiday_priest.sql` and
  `017_multiday_roll_rule.sql` — Multi-day Activities. `016` is required by the
  List tab and the Priests page, `017` by the List tab (Roll rule / Roll forward).

`009`, `015`, `016` and `017` have been applied to the local MAMP database.

---

## Cutover: connecting the frontend to the new backend

**This has not been done yet.** The two Next.js route handlers still
point at the original Google Apps Script URLs. To switch over:

1. Deploy `backend/` to real hosting (cPanel/shared host — see
   `backend/README.md` §"Deploying to shared/cPanel hosting") and migrate
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

---

## Where to look for X

| Task | File(s) |
|---|---|
| Change how the schedule is grouped/filtered | [`src/app/page.tsx`](src/app/page.tsx) |
| Change the login form | [`src/app/login/page.tsx`](src/app/login/page.tsx) |
| Add a new field to an activity | [`backend/schema.sql`](backend/schema.sql) (add column) → `backend/api/activities.php` (add to SELECT + response) → `backend/admin/activities/index.php` (add form field) → `src/types/index.ts` (`ApiActivity`) → `src/app/page.tsx` (if displayed) |
| Generate / refresh the liturgical calendar (next 12 months) | Admin panel → Settings → Liturgical calendar → *Generate calendar* (super admin) |
| Change a fixed-date celebration (name, rank, colour, class, notes) | [`backend/includes/romcal/fixed.dat`](backend/includes/romcal/fixed.dat), then regenerate |
| Change how the calendar is computed (Easter, seasons, precedence) | [`backend/includes/romcal/Romcal.php`](backend/includes/romcal/Romcal.php) (mirrors the C source file by file; re-check against the C build if you change it) |
| Change the calendar window or how rows are written | [`backend/includes/liturgical_calendar.php`](backend/includes/liturgical_calendar.php) |
| Change the admin home's Priests load summary panel (load rule, alerts, weights) | [`backend/includes/pastoral_dashboard.php`](backend/includes/pastoral_dashboard.php) |
| Change the admin theme colours (sky blue default / liturgical season) | [`backend/includes/theme.php`](backend/includes/theme.php); the choice is in Admin → Settings → Theme |
| Change which day the week starts on | Admin panel → Settings (super admin); also renumbers `activities.weekday` and `source.day` |
| Add/rename/move a priest, or add a section, labor or activity type | Admin panel → Priests / Sections / Labors / Activity types (super admin). A centre's section is set on the Centres page. |
| Add a section/labor *colour* | [`src/lib/section-colors.ts`](src/lib/section-colors.ts) |
| Change what a `zone`/`centre` admin can access | `admin_require_role()` calls and `*_in_scope()` functions in `backend/admin/<entity>/index.php` |
| Add a new admin access level or field | [`backend/schema.sql`](backend/schema.sql) `admin_users` table + [`backend/includes/auth.php`](backend/includes/auth.php) + [`backend/admin/admins/index.php`](backend/admin/admins/index.php) |
| Change how the multi-day Roll forward shifts dates or places Free/Maintenance placeholders | [`backend/includes/multiday_rollforward.php`](backend/includes/multiday_rollforward.php) (`mday_roll_plan()`, `mday_roll_apply()`; the form/preview are in `index.php`) |
| Change bulk delete (the select icon/bar, or a page's bulk handling) | [`backend/admin/includes/layout_bottom.php`](backend/admin/includes/layout_bottom.php) (client), [`backend/admin/includes/bulk.php`](backend/admin/includes/bulk.php) (`bulk_run()`, `bulk_delete_matching()`) and each page's `$deleteOne` |
| Change the Activities dashboard's statistics (or the shared bar/card helpers) | [`backend/admin/activities/dashboard.php`](backend/admin/activities/dashboard.php) and [`backend/admin/includes/dash.php`](backend/admin/includes/dash.php) |
| Change the Activities Grid tab (centres × activity types for one date, its cell editor or save) | [`backend/admin/activities/grid.php`](backend/admin/activities/grid.php) |
| Change the Activities calendar's grid, chips, tooltip or filters | [`backend/admin/activities/calendar.php`](backend/admin/activities/calendar.php) |
| Change the multi-day list's filters, search, CSV export or import columns/validation | [`backend/admin/multiday_activities/index.php`](backend/admin/multiday_activities/index.php) (`mday_filters()`, `mday_where()`, `mday_fields()`) and [`backend/includes/multiday_io.php`](backend/includes/multiday_io.php) |
| Change the multi-day calendar's painting, tooltip, clash detection or filters | [`backend/admin/multiday_activities/calendar.php`](backend/admin/multiday_activities/calendar.php) (helpers are the `mday_*` functions at the top) |
| Change the multi-day dashboard's statistics, or the filters shared by the Calendar and Dashboard tabs | [`backend/admin/multiday_activities/dashboard.php`](backend/admin/multiday_activities/dashboard.php) and [`backend/includes/multiday_filters.php`](backend/includes/multiday_filters.php) |
| Add/edit multi-day activities, or mark an activity type as multi-day | Admin panel → Multi-day Activities (List tab); to make an activity type a multi-day one, tick "Multi-day programme" on Admin panel → Activity types |
| Run the one-time Sheets → MySQL import | [`backend/migrate/migrate.php`](backend/migrate/migrate.php) |
| Deploy the backend | [`backend/README.md`](backend/README.md) |

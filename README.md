# Grantee Map

An interactive Leaflet map of grantee organizations with color-coded markers, multi-select organization-type filter chips (which double as a legend), and a year timeline scrubber that animates the foundation's reach growing over time. Built for WordPress + ACF Pro.

---

## Requirements

- WordPress 5.0+
- PHP 7.4+
- ACF Pro
- A Google Maps API key (for the location picker in the admin — the public map uses CartoDB/OpenStreetMap, no key needed)

---

## What the plugin provides

| Thing | Notes |
|---|---|
| `wilhelm_grantee` CPT | Individual award posts. Only registered if not already present (e.g. via a mu-plugin). |
| `grantee_org` CPT | Organizations that group awards together. |
| `org-types` taxonomy | Organization types (e.g. Dance, Museum). Each term has a color picker. Markers on the map reflect these colors. |
| Taxonomies | `grant-cycle`, `grant-types`, `disciplines` on `wilhelm_grantee`. Only registered if not already present. |
| ACF field groups | Loaded from `acf-json/` — org location, website, image, and relationship to awards. |
| REST endpoint | `GET /wp-json/grantees/v1/map` — returns all orgs with embedded org type data. Cached per org via transients. |
| `[grantees_map]` shortcode | Renders the map with multi-select org-type filter chips, a year timeline scrubber, and a live grantee count. |
| Page template | **Grantee Map** page template (registered from the plugin, no theme file needed). |
| Bidirectional sync | Keeps the org→award and award→org relationship fields in sync on save. |
| Migration tool | Creates org posts from existing award posts grouped by title. |
| Orphan check | Admin utility to find awards not linked to any org. |
| Seeders | Developer utilities to seed org locations and org type assignments (see below). |

---

## Installation

### 1 — Install the plugin

Upload the `grantee-map` folder to `wp-content/plugins/` and activate it in **Plugins → Installed Plugins**.

### 2 — Add your Google Maps API key

Go to **Theme Settings** (in the WP admin sidebar) and enter your Google Maps API key in the **Map Settings** section. This is needed for the address/location picker when editing org posts in the admin.

### 3 — Sync ACF field groups

Go to **Custom Fields → Field Groups**. If any of the following show a **Sync** button, click it:

- **Grantee Organization Details** — location, website, image, and the relationship field linking orgs to their awards
- **Grantee Organization Ref** — the reverse lookup field on award posts showing which org they belong to

### 4 — Set up Organization Types

Go to **Grantees → Org Types** and create your taxonomy terms (e.g. Dance, Museum, Queer, Women Owned). Each term has a **Color** field — set a color for each type. These colors appear as the marker dots on the map.

Orgs with one type get a solid colored dot. Orgs with multiple types get a pie-segment dot, one slice per color.

### 5 — Set up award posts

Each `wilhelm_grantee` post represents an individual award. Each one should have:

- A title (the organization's name — awards for the same org should have identical titles)
- Grant Cycle, Grant Type, and Discipline terms assigned
- An **Amount** value in the ACF field

### 6 — Create organizations

#### Option A — Migration tool (recommended when award posts already exist)

If award posts are already in the database, run the migration tool to create organizations automatically. It groups posts by title, creates one org per unique name, and links all matching awards.

**Dry run first** (nothing is written):
```
/wp-admin/?run_grantee_migration=1&dry_run=1
```

Review the output, then run for real:
```
/wp-admin/?run_grantee_migration=1
```

After the migration, go to **Grantees → Organizations** and add a map location and org type(s) to each org.

#### Option B — Manual entry

Go to **Grantees → Organizations → Add New** for each org:

- Set the title
- **Grantee Awards** — select all the individual award posts for this org
- **Location** — search the address and confirm the pin
- **Organization Types** — assign one or more org types from the sidebar
- Fill in website URL, display name, and logo

Saving the org automatically writes the reverse relationship back onto each linked award post.

### 7 — Run the bidirectional backfill (if needed)

If awards were linked to orgs before the plugin was active, or if the reverse `grantee_org_ref` field is blank on any award posts, run the backfill:

```
/wp-admin/?sync_grantee_bidir=1
```

### 8 — Add the map to a page

Create a new page and assign the **Grantee Map** page template under **Page Attributes**, or add the shortcode to any page:

```
[grantees_map]
```

---

## Shortcode options

```
[grantees_map
  center_lat="39.5"
  center_lng="-98.35"
  zoom="4"
]
```

| Attribute | Default | Description |
|---|---|---|
| `center_lat` | `39.5` | Initial map center latitude |
| `center_lng` | `-98.35` | Initial map center longitude |
| `zoom` | `4` | Initial zoom level. Fractional values (e.g. `4.5`) are supported — the map uses `zoomSnap: 0.25`. |

The default (unfiltered) view always uses `center_lat`/`center_lng`/`zoom` as-is rather than fitting to marker bounds, so a few outlying orgs (e.g. Caribbean locations) don't zoom the map out over open ocean. If the default framing looks too wide or too tight for a given page, adjust `zoom` rather than relying on auto-fit. Once an org-type chip or timeline year narrows the results, the map does fit/fly to the filtered markers.

The map height is controlled by CSS (`aspect-ratio: 100 / 66` by default) rather than a shortcode attribute.

---

## Features

- **Organization type chips** — colored, multi-select chips (colors match the marker dots, so they also serve as a legend). Selecting more than one is an OR filter.
- **Year timeline** — a scrubber below the map filters to orgs whose earliest award year is at or before the selected year, so dragging it shows the foundation's geographic reach growing over time. The play button animates automatically from the earliest to the latest year. Only appears if grant-cycle year data exists and spans more than one year.
- **Fly-to on single result** — when chips and/or the timeline narrow the map down to exactly one grantee, the map flies to it and opens its popup instead of doing a generic bounds fit.
- **Marker pop-in animation** — newly appearing markers animate in with a small scale/fade, most noticeable while scrubbing or playing the timeline. Respects `prefers-reduced-motion`.
- **Reset** — clears chips and the timeline back to the default view in one click. Only shown when a filter is active.

---

## Map styling

Edit `assets/js/grantee-map-style.json` to customize the map appearance without touching code:

```json
{
  "tiles": {
    "url": "https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png",
    "attribution": "&copy; OpenStreetMap contributors &copy; CARTO",
    "maxZoom": 19,
    "retina": true
  },
  "marker": { "color": "#1a1a1a", "borderColor": "#ffffff", "size": 11 },
  "popup":  { "accentColor": "#1a1a1a", "linkColor": "#1a1a1a" }
}
```

`marker.color` is the fallback dot color for orgs with no org type assigned (used both for individual markers and, per-slice, for cluster bubbles — see below). Any Leaflet-compatible tile provider URL works in `tiles.url`.

Cluster bubbles aren't configured via this file: each one renders as a pie of the org-type colors it contains (a solid circle if every org inside shares one color), sized up with the number of orgs it groups.

---

## How the data model works

```
wilhelm_grantee (award post)          grantee_org (organization post)
─────────────────────────────         ──────────────────────────────────
title: "Arts Council"                 title: "Arts Council"
grant-cycle: "2024"                   org-types: [Dance, Museum]
grant-types: "Project Grant"          grantee_map: { lat, lng, address }
disciplines: "Visual Arts"            website_url, website_nicename
amount: "$15,000"                     custom_image
grantee_org_ref: [org_id]  ◄────────► grantee: [award_id_1, award_id_2, ...]
```

The `grantee` field on the org and `grantee_org_ref` on the award are kept in sync automatically whenever either post is saved.

The map endpoint reads from `grantee_org` posts only. Filtering and marker rendering happen client-side after a single fetch on page load.

---

## Admin utilities

All utilities require admin login and output a results page when run.

| URL | What it does |
|---|---|
| `/wp-admin/?run_grantee_migration=1&dry_run=1` | Preview org creation from existing awards — nothing is written |
| `/wp-admin/?run_grantee_migration=1` | Create org posts from award posts grouped by title |
| `/wp-admin/?sync_grantee_bidir=1` | Backfill reverse relationships on all award posts |
| `/wp-admin/?check_grantee_orphans=1` | List award posts not linked to any org |
| `/wp-admin/?seed_org_types=1` | Create default org type terms and randomly assign 1–3 to each org (dev/testing only) |
| `/wp-admin/?seed_grantee_locations=1` | Seed placeholder map locations onto orgs that have no coordinates (dev/testing only) |
| `/wp-admin/?clear_grantee_map_cache=1` | Flush the cached map data for every org, forcing a rebuild on next map load |

---

## Performance

Org data is cached per-org using WordPress transients (1 week TTL). The cache is automatically busted when an org or any of its linked awards is saved.

To manually clear all map cache, visit `/wp-admin/?clear_grantee_map_cache=1` (admin login required), bulk-edit all orgs in **Grantees → Organizations** (select all → Edit → Apply), or run this SQL:

```sql
DELETE FROM wp_options
WHERE option_name LIKE '_transient_grantee_org_map_%'
   OR option_name LIKE '_transient_timeout_grantee_org_map_%';
```

Note: after deploying a code change that alters what gets cached (e.g. a fix to how a field is formatted), existing cached entries won't reflect it until they expire or are flushed — the admin utility above is the quickest way to force it on a live site.

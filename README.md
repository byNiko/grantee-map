# Grantee Map

An interactive Leaflet map of grantee organizations with color-coded markers and client-side filtering by organization type. Built for WordPress + ACF Pro.

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
| `[grantees_map]` shortcode | Renders the map with org type filter dropdown and grantee count. |
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
| `zoom` | `4` | Initial zoom level |

The map height is controlled by CSS (`aspect-ratio: 100 / 66` by default) rather than a shortcode attribute.

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
  "marker":  { "color": "#1a1a1a", "borderColor": "#ffffff", "size": 11 },
  "cluster": { "background": "#ffffff", "color": "#1a1a1a", "opacity": 1 },
  "popup":   { "accentColor": "#1a1a1a", "linkColor": "#1a1a1a" }
}
```

`marker.color` is the fallback dot color for orgs with no org type assigned. Any Leaflet-compatible tile provider URL works in `tiles.url`.

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

---

## Performance

Org data is cached per-org using WordPress transients (1 week TTL). The cache is automatically busted when an org or any of its linked awards is saved.

To manually clear all map cache, bulk-edit all orgs in **Grantees → Organizations** (select all → Edit → Apply), or run this SQL:

```sql
DELETE FROM wp_options
WHERE option_name LIKE '_transient_grantee_org_map_%'
   OR option_name LIKE '_transient_timeout_grantee_org_map_%';
```

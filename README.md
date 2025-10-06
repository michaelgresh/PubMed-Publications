# PubMed Publications

A lightweight WordPress plugin that pulls **PubMed/NCBI** publications for each author (e.g., physicians) and renders **Divi-friendly** publication cards—no hand-curating posts or heavyweight academic stacks.

- Custom Post Type: `pubmed_publication` *(renameable)*  
- Taxonomy (per-author/doctor): `pubmed_doctor` *(renameable)*  
- Shortcodes for **Latest** and **Per-Author** lists  
- Optional **modal viewer** so PubMed/PMC pages open inside your site  
- Sorting that matches PubMed’s “Most Recent” (uses `sortpubdate → epubdate → pubdate`)  
- **Delete authors/doctors** from the admin with two safe modes (+ success notices)

> This repo typically contains a single plugin file, e.g. `pubmed-publications/pubmed-publications.php`.  

---

## Features

- **Pulls from PubMed** via NCBI E-utilities (`esearch` + `esummary`)
- **Accurate dates**: stores an ISO date using `sortpubdate` when available (falls back to `epubdate`, then `pubdate`), and syncs WP `post_date` for consistent sorting
- **Chronological lists** (newest → oldest), no year separators
- **Bibliography links** per author/doctor (MyNCBI or PubMed results pages)
  - If a PubMed results URL contains `?term=...`, the plugin extracts the term to mirror that exact search when importing
- **Modal open** (optional) for PubMed/PMC links
- **Caching** via WordPress transients (default 6h); manual Fetch bypasses cache
- **De-duplication** by PMID (falls back to title)
- **Admin: Delete doctor** with two safe modes:
  - *Delete doctor (keep publications)* → removes the taxonomy term; posts remain
  - *Delete doctor + publications (exclusive only)* → moves exclusive posts to **Trash**; co-authored posts are kept (doctor detached)
- **Admin notices** show success messages after actions
- **Divi-friendly HTML/CSS** out of the box
- **Single top-level** admin menu for a clean UX

---

## Requirements

- WordPress 6.0+  
- PHP 7.4+ (8.x supported)  
- A monitored email address (set in the plugin constant) to comply with NCBI’s tool policy

---

## 🔧 Install

1. Create the folder:  
   `wp-content/plugins/pubmed-publications/`
2. Copy the plugin file into that folder (e.g., `pubmed-publications.php`).  
3. Activate **PubMed Publications** in **Plugins**.

> After activation, you’ll see a single admin menu: **PubMed Publications**  
> - **Authors & Import** (setup + fetch + delete)  
> - **Publications** (CPT list)  
> - **Add New** (optional manual entries)

---

## Admin: Authors & Import

**Add an author/doctor**

- **Name**: e.g., `Johnson` or `Jones` (used as the taxonomy term)
- **PubMed query**: supports boolean OR and parentheses  
  Example:
  ```
  (Johnson A[au] OR Johnson AM[au]) AND (YourHospital[ad] OR YourCity[ad])
  ```
  - `[au]` = author, `[ad]` = affiliation
  - **Do not** put the entire expression in quotes
- **Bibliography URL** (optional): any public link (MyNCBI or PubMed results)
  - If it’s a **PubMed results URL containing `?term=`**, the plugin automatically extracts and uses that `term` for fetching so your site mirrors the exact search.
  - If it’s a **MyNCBI bibliography page** (e.g., `https://www.ncbi.nlm.nih.gov/myncbi/.../bibliography/public/`), the link is **displayed**, and imports use the stored query.

Click **Save** → **Fetch/Refresh** (per author or **ALL**).

**What happens on fetch**

- The plugin queries PubMed for the newest items (default up to 100).  
- It creates/updates `pubmed_publication` posts, attached to the author taxonomy term.  
- It stores:
  - `pubmed_pmid`, `pubmed_pmcid`, `pubmed_doi`, `pubmed_journal`
  - `pubmed_pubdate_display` (nice string, prefers Epub date)
  - `pubmed_pubdate_iso` (YYYY-MM-DD, from `sortpubdate/epubdate/pubdate`)
  - `pubmed_url` (PubMed article URL)
- It also sets the **WordPress `post_date`** to the ISO date so sorting is consistent everywhere.

---

## Deleting an author/doctor (safe options)

From **Authors & Import**, each row has a **Delete** control with two modes:

- **Delete doctor (keep publications):** removes the taxonomy term. Existing posts remain; the doctor is detached.  
- **Delete doctor + publications (exclusive only):**  
  - Posts that are **only** tagged with this doctor are moved to the **Trash** (not permanently deleted).  
  - Posts with **multiple doctors** are kept; this doctor is detached.

After deletion, a **success notice** confirms what happened.

> Tip: If you want an extra guardrail, add a “type the name to confirm” field in the delete form. (Optional snippet available in the issues/wiki.)

---

## Shortcodes

> **Prefix note:** The examples below use the current shortcode names (`pubmed_...`). If you rename your prefixes in code, adjust accordingly (e.g., `pubmed_publications`).

### Latest across all authors
```text
[pubmed_latest_publications
  limit="10"
  open="modal"
  title="Latest Publications"
  subtitle=""
  anchor=""
  heading="h2"]
```

### Per-author list
```text
[pubmed_publications
  doctor="Johnson"
  limit="10"
  open="modal"
  title="Dr. Johnson — Recent Publications"
  subtitle=""
  anchor="johnson"
  heading="h2"
  show_bibliography="true"
  bib_label="View full bibliography"]
```

**Attributes**

- `doctor` – taxonomy term name (e.g., `Johnson`, `Jones`) — represents the author
- `limit` – number of items (default `10`)
- `open` – `modal` or `newtab` (default `newtab`)
- `title`, `subtitle`, `anchor`, `heading` – section header options (`heading`: `h2`..`h5`)
- `show_bibliography` – `true|false` (default `true` for per-author)
- `bib_label` – link text for the bibliography button

> `year_groups` is ignored (kept for backward compatibility).

---

## Styling

Minimal CSS is inlined by the plugin and designed to play nicely with Divi.  
Available classes:

- `.pubmed-section-header`, `.pubmed-section-title`, `.pubmed-section-subtitle`
- `.pubmed-bib-link`
- `.pubmed-grid` (responsive grid)
- `.pubmed-card`, `.pubmed-title`, `.pubmed-meta`, `.pubmed-authors`, `.pubmed-links a`

**Example override (Divi → Theme Options → Custom CSS)**

```css
.pubmed-card { border-radius: 14px; }
.pubmed-title { font-size: 1.05rem; }
.pubmed-meta em { font-style: italic; }
```

---

## Data model

- **Post Type**: `pubmed_publication` *(renameable)*  
  - `post_date`: synced to the article’s ISO date for consistent sorting
- **Taxonomy**: `pubmed_doctor` *(renameable)*  
  - Term Meta:
    - `pubmed_query` – PubMed query (boolean OR supported)
    - `pubmed_bib_url` – public bibliography URL (MyNCBI or PubMed results)
- **Post Meta**:
  - `pubmed_pmid`, `pubmed_pmcid`, `pubmed_doi`
  - `pubmed_journal`, `pubmed_authors`
  - `pubmed_pubdate_display` (string shown on cards)
  - `pubmed_pubdate_iso` (YYYY-MM-DD, used for sorting)
  - `pubmed_url` (PubMed URL)

---

## Caching & rate limits

- Results from PubMed API are cached for **6 hours** (change `CACHE_HOURS`).
- The **Fetch/Refresh** action bypasses the cache.
- The plugin sends `tool` and **your email** to NCBI. Update `const EMAIL` to a monitored address.

---

## Date logic (why it sorts correctly)

PubMed has several dates:
- `sortpubdate` (used by PubMed to sort “Most Recent”)
- `epubdate` (online first)
- `pubdate` (print)

**We use:** `sortpubdate → epubdate → pubdate` to build an ISO date and set `post_date` to that value.  
Cards show the **display** date (prefers Epub string if present).

This avoids issues like “Epub 2024 / Print 2025” appearing under the wrong year or out of order.

---

## Maintenance

- Periodically click **Fetch/Refresh ALL** to pull new publications.
- If you imported before the date logic existed, use **Rebuild Dates** to re-sync `post_date` from stored ISO dates.
- To refine results: expand author variants and/or add an affiliation clause  
  e.g., `(Johnson A[au] OR Johnson AM[au]) AND (YourHospital[ad] OR YourCity[ad])`

---

## Troubleshooting

**Nothing imports for an author**

- Remove outer quotes around the entire query.
- Test your query directly at PubMed (confirm results).
- If using a bibliography URL, ensure it’s a PubMed **results** link with `?term=` for import mirroring. MyNCBI bibliography pages are **displayed only**, not parsed for the term.

**Sorting looks wrong**

- Ensure you’re on the latest plugin with `sortpubdate` logic.
- Click **Fetch/Refresh** for that author (or **ALL**).
- Run **Rebuild Dates** (optional) to re-sync `post_date`.

**I see duplicates**

- The plugin dedupes on **PMID**, falling back to **title**. If an item appears twice in PubMed under different PMIDs, it can appear twice here (expected).

---

## Security & Privacy

- Fetches **public** bibliographic data from PubMed (no PHI).
- Follows NCBI’s E-utilities guidelines (`tool` + `email`).
- Stores no sensitive data beyond publication metadata.

---

## Development notes

- Single-file plugin for portability.
- Hooks:
  - `admin_post_pubmed_save_doctors`
  - `admin_post_pubmed_fetch`
  - `admin_post_pubmed_rebuild_dates` *(optional)*
  - `admin_post_pubmed_delete_doctor`
- Easy extension ideas:
  - Add **EFetch** to store abstracts
  - Add **WP-Cron** weekly refresher
  - Add a second taxonomy for Posters/Presentations
  - Add “type the name to confirm” before deletion

---

## Versioning

- `1.2.0` — **Delete doctor** (keep vs. delete exclusive to doctor → Trash), admin success notices; consolidated admin language.  
- `1.1.0` — Bibliography links, section headers, modal, PubMed term extraction from results URLs, date logic (`sortpubdate`) + `post_date` syncing, single chronological lists (no year separators).

---

## License

MIT (or update to your preferred license).  

---

## Maintainers

- Your organization / site vendor  
- Dev contact: update `const EMAIL` in the plugin for NCBI contact and support

---

## Quick Start (TL;DR)

1. Activate the plugin.  
2. **PubMed Publications → Authors & Import**  
   - Add an author, e.g., query: `(Johnson A[au] OR Johnson AM[au]) AND (YourHospital[ad] OR YourCity[ad])`  
   - Add bibliography URL (e.g., MyNCBI public page)  
   - **Save** → **Fetch/Refresh**  
3. In your Divi page:
   ```text
   [pubmed_latest_publications title="Latest Publications" limit="10" open="modal"]
   [pubmed_publications doctor="Johnson" title="Dr. Johnson’s Recent Publications" show_bibliography="true" open="modal"]
   ```
4. (Optional) Delete a test author to see the safe delete modes + notices.  
5. Done.

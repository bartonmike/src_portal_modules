# Superfund Import — Drupal Module

Downloads a `.zip` file from a configured URL, extracts every `.csv` inside it, and imports each one into the matching `superfund_*` database table. Runs on a configurable Drupal cron schedule and also exposes a Drush command for Linux system cron.

---

## Requirements

| Requirement | Version |
|---|---|
| Drupal | 10 or 11 |
| PHP | 8.1+ |
| PHP `zip` extension | enabled |
| Drush (optional) | 12+ |

---

## Installation

1. Copy the `superfund_import` folder into `web/modules/custom/`.
2. Enable the module:
   ```bash
   drush en superfund_import -y
   drush cr
   ```

---

## Configuration

Navigate to **Admin → Configuration → System → Superfund Import**
(`/admin/config/superfund-import`)

| Field | Description |
|---|---|
| **ZIP file URL** | Full HTTPS URL to the `.zip` archive. Must end in `.zip`. |
| **Import frequency** | How often Drupal cron triggers a new import (hourly → weekly). |

A **"Run import now"** button lets you trigger an immediate run without waiting for cron.

---

## How it works

```
ZIP URL
  └─► Download to /tmp/superfund_import_<id>.zip
        └─► Extract to /tmp/superfund_extract_<id>/
              └─► For each *.csv found:
                    filename (without .csv) → prepend "superfund_" → table name
                    e.g.  orders.csv  →  superfund_orders
                    1. TRUNCATE superfund_<name>
                    2. Read header row  →  column names
                    3. Batch INSERT (1 000 rows/query) inside a transaction
              └─► Cleanup temp files
```

### Table name mapping

| CSV file | Target table |
|---|---|
| `orders.csv` | `superfund_orders` |
| `site_data.csv` | `superfund_site_data` |
| `Chemical_List.csv` | `superfund_chemical_list` |

Rules:
- Filename is lowercased and non-alphanumeric characters become `_`
- Tables that don't exist are **skipped** (warning logged, import continues)
- Unknown CSV columns are **ignored** (warning logged, known columns are still inserted)

### Re-import strategy

Each run performs a **TRUNCATE → INSERT** — the table is fully flushed before new data is loaded. The flush + insert runs inside a database transaction, so a mid-import failure leaves the table intact (rolled back), not half-empty.

---

## Cron

### Option A — Drupal cron (recommended)

The module registers `hook_cron`. Set the frequency in the admin UI and make sure Drupal cron is running:

```bash
# Run cron manually any time
drush cron

# Or set up a system cron to drive Drupal cron
*/15 * * * * cd /var/www/html && vendor/bin/drush cron --quiet
```

The module tracks its own last-run timestamp so it only fires at the configured interval, independent of how often `drush cron` itself runs.

### Option B — Linux system cron calling Drush directly

If you prefer to bypass Drupal cron entirely and control the schedule in crontab:

```bash
crontab -e
```

Add a line (adjust path and schedule as needed):

```cron
# Superfund import — runs every day at 2:00 AM
0 2 * * *  cd /var/www/html && vendor/bin/drush superfund:import >> /var/log/superfund_import.log 2>&1
```

Available Drush command:
```bash
drush superfund:import
# alias:
drush sf-import
```

---

## Logging

All activity is written to Drupal's watchdog under the `superfund_import` channel.

```bash
# View recent log entries
drush watchdog:show --type=superfund_import
```

Events logged:
- Import started / completed (rows + tables count)
- Skipped tables (table not found in DB)
- Ignored CSV columns (column not found in table)
- Download / extraction / database errors

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| "No ZIP URL configured" | Set the URL at `/admin/config/superfund-import` |
| Table skipped | Confirm `superfund_<csvname>` exists in your database |
| Columns ignored | Check that CSV headers exactly match DB column names (case-insensitive) |
| PHP `zip` extension missing | `sudo apt install php-zip && service apache2 restart` |
| Download fails on HTTPS | Ensure PHP `openssl` extension is enabled and the remote cert is valid |

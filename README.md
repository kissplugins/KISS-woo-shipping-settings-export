=== KISS Woo Shipping Settings Exporter ===
Contributors: your-name-here
Tags: woocommerce, shipping, export, csv, shipping zones, shipping methods, backup, audit, simple
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 0.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, robust tool for store managers to export all WooCommerce shipping settings—zones, methods, rates, and classes—to a single, timestamped CSV file.

== Description ==

WooCommerce shipping settings can be complex. **KISS Woo Shipping Settings Exporter** provides a one-click utility to get a complete, bird's-eye view of your entire shipping setup in a single, easy-to-read CSV file.

The plugin adds a new page under **Tools → KISS Shipping Exporter** in your WordPress admin area, as well as a convenient **"Export Settings"** link directly on the plugins page. With a single click, it generates and downloads a CSV containing every shipping zone, its assigned locations, all configured shipping methods with their costs, and a full list of your store's shipping classes.

The export is streamed directly to your browser to ensure it works on any server without hitting memory limits or leaving temporary files behind.

== Key Features ==

* **Convenient Access:** Export directly from the plugins page via the "Export Settings" link, or navigate to the Tools menu.
* **One-Click Export:** Simple interface to download your data instantly.
* **Comprehensive Data:** Exports zones, locations, methods, rates, tax status, per-class costs, and a full shipping class catalog.
* **Timestamped Filenames:** Each export is named with your site's title and a current timestamp (e.g., `my-store-shipping-2025-06-26-203000.csv`), perfect for archiving.
* **Server Friendly:** Streams the file directly, preventing PHP timeouts and memory exhaustion on sites with a large number of shipping zones.
* **No Configuration Needed:** Just install, activate, and use.

== Practical Use Cases ==

* **1. Comprehensive Shipping Audits:** Review all rates in one place for consistency and profitability.
* **2. Configuration Backup & Archiving:** Create timestamped snapshots of your setup to use as a reference for manual restoration.
* **3. Migrating and Staging Websites:** Use the export as a definitive checklist to accurately replicate settings on a new site, reducing human error.
* **4. Troubleshooting Customer Issues:** Quickly filter the CSV by a customer's location to diagnose shipping availability or pricing problems.
* **5. Onboarding and Team Training:** Use the export as a single document to explain the store's complete shipping logic to new team members or clients.

**Important Note:** This is an **export-only** tool. It does not provide functionality to import data from a CSV file. Its primary purpose is for auditing, backup, and reference.

== Installation ==

1.  Upload the `kiss-woo-shipping-settings-exporter` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  That's it!

== How It Works ==

1.  After activating, either:
    * Navigate to your Plugins page and click the **"Export Settings"** link under the plugin's name.
    * OR navigate to **Tools → KISS Shipping Exporter** in your WordPress admin dashboard.
2.  Click the **"Download CSV"** button.
3.  Your browser will download a CSV file containing all shipping data.

== Changelog ==

= 0.3.0 =
* **Enhancement:** Plugin renamed to "KISS Woo Shipping Settings Exporter" for clarity.
* **Enhancement:** Added a convenient "Export Settings" link on the main plugins page for one-click access.
* **Enhancement:** Renamed the Tools menu item for consistency.
* **Refactor:** Updated class names, function names, and text domain to align with the new plugin name. Version incremented.

= 0.2.0 =
* **Major Stability Update:** Added `set_time_limit(0)` to prevent PHP timeouts on large exports.
* **Robustness:** The plugin now checks if WooCommerce is active before running, preventing fatal errors.
* **Robustness:** Added a `try...catch` block and output buffering to prevent "headers already sent" errors and ensure graceful failure.
* **Enhancement:** Now uses `wp_date()` to ensure filenames and timestamps correctly use the site's configured timezone.
* **Enhancement:** Improved data retrieval logic to be more consistent with modern WooCommerce practices.

= 0.1.0 =
* Initial release.

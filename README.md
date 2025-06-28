=== KISS Woo Shipping Settings Exporter ===
Contributors: KISS Plugins
Tags: woocommerce, shipping, export, csv, shipping zones, shipping methods, backup, audit, simple
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.5.5
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

= 1.5.5 =
* **Enhancement:** The "Custom Rules Scanner" analysis is now more concise and readable.
* **Enhancement:** Conditions using `||` (OR) on the same variable (e.g., `$state == 'CA' || $state == 'CO'`) are now consolidated into a single line.
* **Enhancement:** Improved `has_term` analysis to correctly extract category names passed as a single string, in addition to arrays of strings.

= 1.5.4 =
* **Feature:** The scanner can now detect direct comparisons on the `$state` and `$postcode` variables within `if` statements. The analysis will now show the specific value being compared (e.g., "IF the $state is == 'CA'").

= 1.5.3 =
* **Feature:** The "Custom Rules Scanner" now detects arrays used in `in_array()` conditions. It will display the name of the array and list its values in the human-readable analysis, providing a much clearer picture of location-based and other group restrictions.

= 1.5.2 =
* **Fix:** Restored the UI-based shipping settings preview table to the main admin page. The table was accidentally removed in a previous version.

= 1.5.1 =
* **Fix:** Resolved a fatal error caused by PHP-Parser class dependencies loading out of order. All class definitions are now nested inside the main `kiss_wse_initialize_exporter` function to ensure dependencies are available before being called.
* **Enhancement:** The "Custom Rules Scanner" now uses the much more robust PHP-Parser library to traverse the Abstract Syntax Tree (AST) of scanned files. This provides a significantly more accurate and context-aware analysis of `if` conditions and `add` actions.
* **Enhancement:** The scanner now displays the raw code snippet for each rule it detects, providing direct context for the analysis.
* **Enhancement:** Added a collapsible "Debugging Information" section that shows the parser's status and a dump of the AST, aiding in troubleshooting complex or unrecognized code patterns.
* **Enhancement:** The scanner now targets both `functions.php` and a theme-specific `inc/shipping-restrictions.php` file.

= 0.5.0 =
* **Feature:** Added a new "Custom Rules Scanner" that performs a basic token-based scan of the theme's `inc/shipping-restrictions.php` file to find and display hard-coded shipping rules.
* **Enhancement:** The admin page is now split into two sections: the new custom scanner and the existing UI settings exporter.
* **Refactor:** The admin page rendering logic is now more organized.

= 0.4.0 =
* **Feature:** Added a preview table on the admin page that displays the first 10 shipping zone configurations, giving a quick overview without needing to download the full CSV.
* **Enhancement:** Improved the export logic to include more details and handle edge cases for shipping methods without costs.

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
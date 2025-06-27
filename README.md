=== KISS - Woo Shipping Exporter ===
Contributors: KISS Plugins
Tags: woocommerce, shipping, export, csv, shipping zones, shipping methods, backup, audit
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, robust tool for store managers to export all WooCommerce shipping configuration data—zones, methods, rates, and classes—to a single, timestamped CSV file.

== Description ==

WooCommerce shipping settings can be complex and spread across many different screens. **Woo Shipping Exporter** provides a one-click utility to get a complete, bird's-eye view of your entire shipping setup in a single, easy-to-read CSV file.

The plugin adds a new page under **Tools → Shipping CSV Export** in your WordPress admin area. With a single click, it generates and downloads a CSV containing every shipping zone, its assigned locations, all configured shipping methods with their costs, and a full list of your store's shipping classes.

The export is streamed directly to your browser to ensure it works on any server without hitting memory limits or leaving temporary files behind.

== Key Features ==

* **One-Click Export:** Simple interface under "Tools" to download your data instantly.
* **Comprehensive Data:** Exports zones, locations, methods, rates, tax status, per-class costs, and a full shipping class catalog.
* **Timestamped Filenames:** Each export is named with your site's title and a current timestamp (e.g., `my-store-shipping-2025-06-26-193000.csv`), making it perfect for archiving.
* **Server Friendly:** Streams the file directly, preventing PHP timeouts and memory exhaustion on sites with a large number of shipping zones.
* **No Configuration Needed:** Just install, activate, and use.

== Practical Use Cases ==

Why would you need to export all your shipping data? This plugin is invaluable for:

* **1. Comprehensive Shipping Audits**
    WooCommerce shipping rules can become complicated over time. Use the exported CSV to:
    * Review all your shipping rates in one place to check for consistency and accuracy.
    * Quickly identify which zones have Free Shipping enabled.
    * Analyze your per-class shipping costs to ensure they are still profitable.
    * Present a full report of shipping options to management or clients.

* **2. Configuration Backup & Archiving**
    Accidentally delete a shipping zone or mess up a complex set of rates? While this plugin is **export-only**, the generated CSV acts as a perfect human-readable backup.
    * Regularly export your settings before making significant changes.
    * Archive the timestamped files as a "known-good" reference point. If something breaks, you have a complete blueprint to manually restore the settings accurately.

* **3. Migrating and Staging Websites**
    Moving a WooCommerce store from a staging environment to a live server can be stressful. Manually recreating shipping zones and methods is tedious and error-prone.
    * Export the CSV from your staging site.
    * Use the file as a definitive "checklist" on another screen while you configure the live site, ensuring no zone, method, or rate is missed. This dramatically reduces the chance of human error during a go-live launch.

* **4. Troubleshooting Customer Issues**
    When a customer reports "I can't ship to my location" or "The shipping cost seems wrong," debugging can be difficult.
    * Open the CSV in any spreadsheet program.
    * Quickly filter or search by the customer's country, state, or postcode to see exactly which shipping zones and methods apply (or don't apply) to them. This is much faster than clicking through each zone in the WooCommerce UI.

* **5. Onboarding and Team Training**
    When a new team member joins, you can provide them with the exported CSV as a single document that explains the entire shipping logic for the store. It's an excellent piece of documentation for client hand-offs as well.

**Important Note:** This is an **export-only** tool. It does not provide functionality to import data from a CSV file. Its primary purpose is for auditing, backup, and reference.

== Installation ==

1.  Upload the `woo-shipping-exporter` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  That's it! There are no settings to configure.

== How It Works ==

1.  After activating, navigate to **Tools → Shipping CSV Export** in your WordPress admin dashboard.
2.  Click the **"Download CSV"** button.
3.  Your browser will download a CSV file containing all shipping data, named with your site's title and the current date and time.

== Changelog ==

= 0.2.0 =
* **Major Stability Update:** Added `set_time_limit(0)` to prevent PHP timeouts on large exports.
* **Robustness:** The plugin now checks if WooCommerce is active before running, preventing fatal errors.
* **Robustness:** Added a `try...catch` block and output buffering to prevent "headers already sent" errors and ensure graceful failure.
* **Enhancement:** Now uses `wp_date()` to ensure filenames and timestamps correctly use the site's configured timezone.
* **Enhancement:** Improved data retrieval logic to be more consistent with modern WooCommerce practices.

= 0.1.0 =
* Initial release.

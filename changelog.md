# Changelog

## 1.0.7
* **Fix:** Eliminated "Undefined array key zone_id" by iterating zone IDs (and explicitly adding zone ID 0 for Rest of World).
* **UX:** Price outputs in the Zones & Methods preview now render as clean text (no Woo price HTML).
  * Added `price_to_text()` helper and smarter Flat Rate handling (numeric vs expression).
  * Tidied method lines spacing; badges and titles are consistent.

## 1.0.6
* **Feature:** Restored the “Shipping Zones & Methods Preview” table with deep links to edit zones/methods.
* **UX:** Added owner-friendly enhancements:
  * Status badges (Enabled/Disabled), per-zone enabled/disabled counts.
  * Warnings for common issues (e.g., zone with no enabled methods; Free Shipping with no requirement).
  * Quick filters: “Only show zones with issues” and “Show only enabled methods”.
  * Concise locations summary (e.g., “US (2 states), CA (3 provinces) … +N more”).
  * Preview is capped to 100 rows; shows “And X more rows…” if applicable.

## 1.0.5
* **UX:** More human-friendly descriptions:
  * “Free Shipping” detection now reads as “the rate is a Free Shipping method”.
  * Common variable names are translated (e.g., has_drinks → “the cart contains drinks”, adjusted_total → “the non-drink subtotal”).
  * Comparisons like `adjusted_total < 20` render as “the non-drink subtotal is under $20”.
  * Resolves simple in-scope string assignments for variables (e.g., `{custom_rate_id}` → `drinks_shipping_flat`).
* **Fix:** Corrected a typo in action links registration.

## 1.0.4
* **UX:** Adds context from surrounding conditions for matches:
  * Shows WHEN-conditions for `unset($rates[...])`, `new WC_Shipping_Rate(...)`, and `add_fee()`.
  * Detects common patterns like `strpos($rate->method_id, 'free_shipping')` to say “free shipping rate”.
  * Includes IDs/labels/costs for new `WC_Shipping_Rate` where available.

## 1.0.3
* **UX:** Improved human-readable messages:
  * Extracts messages built with concatenation, `sprintf()`, and interpolated strings for `$errors->add()`.
  * Shows dynamic placeholders (e.g., `{restricted_states[$state]}`) when parts are non-literal.
  * Attempts to display the key used in `unset($rates[...])` even when dynamic, via readable placeholders.
* **Fix:** Avoid duplicate output by relying on a single instantiation path (no extra instantiation at file end).

## 1.0.2
* **UX:** Renamed the `$errors->add()` section to “Checkout validation ($errors->add)”.
* **UX:** Scanner now shows human-readable explanations:
  * Extracts and displays error message strings passed to `$errors->add()`.
  * Adds short plain-English descriptions for filters, fee hooks, `add_rate()`, `new WC_Shipping_Rate`, `unset($rates[])`, and `add_fee()`.

## 1.0.1
* **Security:** Added capability check (`manage_woocommerce`) and nonce verification to the CSV export handler, with proper CSV streaming headers and a hard `exit;` after output.
* **Security:** Restricted “additional file” scanning to the active child theme’s `/inc/` directory using `realpath` clamping and base‐path verification.
* **Developer Experience:** Settings page now automatically detects whether PHP-Parser is loaded and performs a self-test on page load, displaying the result via an admin notice.
* **Correctness:** Fixed `RateAddCallVisitor` imports and node usage:
  * Added missing `use` statements for `PhpParser\Node\Name` and `PhpParser\Node\Identifier`.
  * Corrected `Unset_` to `PhpParser\Node\Stmt\Unset_` (was incorrectly under `Expr`).
  * Declared typed array properties for collected node lists to avoid dynamic properties on newer PHP versions.

## 1.0.0
* **Refactor:** Switched from native `token_get_all()` scanning to a full PHP-Parser AST–based analysis for theme files.
* **Feature:** Dynamically discover and require `php-parser-loader.php` from any active plugin folder.
* **Enhancement:** Unified UI-settings export (CSV download and preview table) and AST-based code scanner into a single plugin file.
* **Improvement:** Updated the “Custom Rules Scanner” visitor to report precise `add_rate()` calls by AST node and line number.
* **Maintenance:** Bumped minimum WP/PHP requirements (WP 6.0+, PHP 7.4+) and versioned to 1.0.0 for the AST migration release.

## 0.7.0
* **Additional File Scanning:** On the "KISS Shipping Debugger" tools page, you will now find a field to enter the path to an additional file within your theme folder (e.g., `/inc/woo-functions.php`) to scan for rules.
* **Enhanced Rule Interpretation:** The scanner is now more powerful and can detect:
  * Functions that hook into `woocommerce_package_rates` to modify shipping prices.
  * Direct cost modifications (e.g., `$rate->cost = 10;`).
  * Cost additions/subtractions (e.g., `$rate->cost += 5;`).
  * Rules that programmatically `unset()` or remove a shipping method.
  * The creation of new shipping rates using `new WC_Shipping_Rate()`.
* **Improved UI:** The scanner results are now organized by the file they were found in, making the output clearer when scanning multiple files.

## 0.6.0
* **Enhancement:** The "Zone Name" in the UI settings preview table is now a direct link to the corresponding WooCommerce shipping zone editor page.
* **Enhancement:** The Custom Rules Scanner now provides a descriptive message for empty or placeholder translation strings, preventing empty bullet points in the output.

## 0.3.0
* **Enhancement:** Plugin renamed to "KISS Woo Shipping Settings Debugger" for clarity.
* **Enhancement:** Added a convenient "Export Settings" link on the main plugins page for one-click access.
* **Enhancement:** Renamed the Tools menu item for consistency.
* **Refactor:** Updated class names, function names, and text domain to align with the new plugin name. Version incremented.

## 0.2.0
* **Major Stability Update:** Added `set_time_limit(0)` to prevent PHP timeouts on large exports.
* **Robustness:** The plugin now checks if WooCommerce is active before running, preventing fatal errors.
* **Robustness:** Added a `try...catch` block and output buffering to prevent "headers already sent" errors and ensure graceful failure.
* **Enhancement:** Now uses `wp_date()` to ensure filenames and timestamps correctly use the site's configured timezone.
* **Enhancement:** Improved data retrieval logic to be more consistent with modern WooCommerce practices.

## 0.1.0
* Initial release.
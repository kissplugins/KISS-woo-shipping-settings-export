# Changelog

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

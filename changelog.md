Changelog


1.0.0 - Major Scanner Overhaul - 2025-07-24

This is a major architectural update that replaces the scanner's engine for vastly improved accuracy and reliability.
	•	Major Feature: Static Analysis with PHP-Parser: The Custom Rules Scanner no longer uses regular expressions or simple text matching. It is now powered by the nikic/PHP-Parser library, allowing it to perform a true static analysis of PHP code.
	•	Architectural Change: The plugin now parses scanned files into an Abstract Syntax Tree (AST). It then traverses this tree to identify code structures programmatically. This initial version replaces the detection of add_rate()calls.
	•	Benefit: Pinpoint Accuracy: This new method can distinguish between active code, commented-out code, and simple strings. This virtually eliminates the false positives of the previous scanner, ensuring that only actual add_rate() method calls are reported.
	•	New Dependency: The scanner now requires a separate, companion "PHP-Parser Loader" plugin to be installed and active. The debugger will show a notification if this dependency is not met.
	•	Technical Refactor: A new RateAddCallVisitor class has been introduced to handle the AST traversal logic, making the scanner more modular and prepared for future enhancements.

0.7.0

	•	Additional File Scanning: On the "KISS Shipping Debugger" tools page, you will now find a field to enter the path to an additional file within your theme folder (e.g., /inc/woo-functions.php) to scan for rules.
	•	Enhanced Rule Interpretation: The scanner is now more powerful and can detect:
	◦	Functions that hook into woocommerce_package_rates to modify shipping prices.
	◦	Direct cost modifications (e.g., $rate->cost = 10;).
	◦	Cost additions/subtractions (e.g., $rate->cost += 5;).
	◦	Rules that programmatically unset() or remove a shipping method.
	◦	The creation of new shipping rates using new WC_Shipping_Rate().
	•	Improved UI: The scanner results are now organized by the file they were found in, making the output clearer when scanning multiple files.

0.6.0

	•	Enhancement: The "Zone Name" in the UI settings preview table is now a direct link to the corresponding WooCommerce shipping zone editor page.
	•	Enhancement: The Custom Rules Scanner now provides a descriptive message for empty or placeholder translation strings, preventing empty bullet points in the output.

0.3.0

	•	Enhancement: Plugin renamed to "KISS Woo Shipping Settings Debugger" for clarity.
	•	Enhancement: Added a convenient "Export Settings" link on the main plugins page for one-click access.
	•	Enhancement: Renamed the Tools menu item for consistency.
	•	Refactor: Updated class names, function names, and text domain to align with the new plugin name. Version incremented.

0.2.0

	•	Major Stability Update: Added set_time_limit(0) to prevent PHP timeouts on large exports.
	•	Robustness: The plugin now checks if WooCommerce is active before running, preventing fatal errors.
	•	Robustness: Added a try...catch block and output buffering to prevent "headers already sent" errors and ensure graceful failure.
	•	Enhancement: Now uses wp_date() to ensure filenames and timestamps correctly use the site's configured timezone.
	•	Enhancement: Improved data retrieval logic to be more consistent with modern WooCommerce practices.

0.1.0

	•	Initial release.

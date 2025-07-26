=== KISS Woo Shipping Settings Debugger ===
Contributors: KISS Plugins  
Tags: woocommerce, shipping, export, csv, shipping zones, shipping methods, backup, audit, simple  
Requires at least: 6.0  
Tested up to: 6.8  
Stable tag: 1.0.7  
Requires PHP: 7.4  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, robust tool for store managers and developers to **audit, preview, and export** WooCommerce shipping settings—and to **scan custom theme code** for shipping logic via PHP-Parser (AST).

---

## == Description ==

WooCommerce shipping settings can be complex. **KISS Woo Shipping Settings Debugger** provides:

- A **one-click CSV export** of zones, methods, costs, and shipping classes.
- A **live “Shipping Zones & Methods Preview”** table with quick links to edit each zone/method, owner-friendly warnings (e.g., zones with no enabled methods), and quick filters.
- A **Custom Rules Scanner** that parses your theme files **using PHP-Parser (AST)** to surface code that alters shipping (e.g., `unset($rates[...])`, `new WC_Shipping_Rate(...)`, `add_fee()`, checkout validations via `$errors->add`, etc.) with **human-readable explanations**.

You’ll find everything under **Tools → KISS Shipping Debugger**. There’s also a convenient **“Export Settings”** link on the Plugins screen.

The CSV export is streamed to the browser—no big memory spikes and no temporary files left behind.

---

## == Key Features ==

- **Shipping Zones & Methods Preview**
  - Zone locations summarized (e.g., `US:CA, US:NY, … +N more`).
  - Per-zone counts: **“X enabled / Y disabled”**.
  - Per-method badges: **Enabled** / **Disabled**.
  - Useful details where available (e.g., **Flat Rate** cost, **Free Shipping** requirement like “minimum order amount: $20.00”).
  - **Quick filters:** *Only show zones with issues* / *Show only enabled methods*.
  - Built-in **warnings** (e.g., “Free Shipping has no requirement”).
  - Capped to **100 rows** for snappy rendering, with “And X more rows…” if needed.
  - **Deep links** to edit each zone and method.

- **Custom Rules Scanner (AST)**
  - Parses specific theme files to find shipping-related code and explains what it likely does in plain English.
  - Detects and describes:
    - `add_filter( 'woocommerce_package_rates', … )`
    - `add_action( 'woocommerce_cart_calculate_fees', … )`
    - `unset( $rates[...] )` (with context such as *“when the rate is Free Shipping and subtotal is under $20”*).
    - `new WC_Shipping_Rate(...)` (shows id/label/cost and when it runs).
    - `$cart->add_fee(...)` (fee name, amount, and conditions).
    - `$errors->add(...)` (checkout validation messages that block checkout).
  - **Automatic parser self-test** runs on page load and shows a green notice if PHP-Parser is available and working.

- **One-Click, Server-Friendly CSV Export**
  - Exports zones, locations, methods (including selected details), and all shipping classes.
  - Timestamped filename for easy archiving.

---

## == Practical Use Cases ==

1. **Comprehensive Shipping Audits:** See all rates and requirements in one place.  
2. **Configuration Backup & Archiving:** Create timestamped snapshots of your setup.  
3. **Migrating / Staging:** Use the CSV + preview as a definitive checklist for replication.  
4. **Troubleshooting:** Quickly verify if Free Shipping should appear for a given subtotal.  
5. **Onboarding / Training:** Explain the store’s shipping logic to new teammates or clients.  
6. **Code Visibility:** Understand custom theme logic that modifies shipping without reading the entire codebase.

> **Note:** This is an **export and visibility** tool. It does **not** import settings.

---

## == Installation ==

1. Upload the `kiss-woo-shipping-settings-debugger` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Open **Tools → KISS Shipping Debugger**.

---

## == How It Works ==

### 1) Admin Page & Export
- The plugin registers a page under **Tools → KISS Shipping Debugger**.
- Clicking **Download CSV of UI Settings** posts to a secure export handler which:
  - Verifies **capability** (`manage_woocommerce`) and **nonce**.
  - Streams the CSV directly to the browser and **exits**.

### 2) Zones & Methods Preview
- Uses WooCommerce APIs (`WC_Shipping_Zones`) to list all zones including the **Rest of the world** zone (ID 0).
- For each method, we show a quick badge and a short detail (e.g., Free Shipping minimum / Flat Rate cost).
- Includes **deep links** to edit the zone or a specific method instance.
- Owner-friendly warnings help surface common misconfigurations (e.g., “no enabled methods”).
- Preview is capped to **100 rows** for performance.

### 3) Custom Rules Scanner (AST)
- Leverages **PHP-Parser** to parse PHP files into an abstract syntax tree and walk it with a custom visitor (`lib/RateAddCallVisitor.php`).
- By default scans the child theme file:
  - `/wp-content/themes/{active-child}/inc/shipping-restrictions.php`
- You may optionally scan **one** more file **inside the same `/inc/` directory** (e.g., `extra.php` or `subdir/custom.php`).
- The scanner prints findings grouped by type, with **human-readable** descriptions and line numbers.

---

## == Developer Onboarding (Semi-Technical) ==

### Architecture at a Glance
- **Main file:** `kiss-woo-shipping-settings-debugger.php`
  - Registers the Tools page, export handler, and settings UI.
  - Renders the Zones & Methods table (with filters/warnings).
  - Runs a PHP-Parser **self-test** and invokes the AST scanner.
- **AST Visitor:** `lib/RateAddCallVisitor.php`
  - A `PhpParser\NodeVisitorAbstract` implementation that collects target nodes:
    - `add_filter( 'woocommerce_package_rates', … )`
    - `add_action( 'woocommerce_cart_calculate_fees', … )`
    - `$package->add_rate()`, `new WC_Shipping_Rate(...)`
    - `unset( $rates[...] )`, `$cart->add_fee(...)`
    - `$errors->add(...)` (checkout validation)
  - Focused on **readability** and **low false positives**. It does not execute user code.

### Dependency: PHP-Parser Loader
This plugin **requires** PHP-Parser to be available at runtime. We recommend installing:

- **KISS PHP-Parser Loader:**  
  https://github.com/kissplugins/wp-php-parser-loader

The loader plugin ensures PHP-Parser classes are autoloaded for WordPress.  
If you prefer your own loader, that’s fine—so long as `\PhpParser\ParserFactory` is available.

**How we detect it:**
- On init, we check `class_exists(\PhpParser\ParserFactory::class)`.
- If not found, we try to `require_once` a `php-parser-loader.php` file from active plugin folders.
- On the Tools page, we run a **tiny parse self-test** and display a green/amber notice with the result.

### Security Considerations
- **Capability:** All actions gated by `manage_woocommerce`.
- **Nonce:** Export handler uses `check_admin_referer()`.
- **CSV Streaming:** Proper headers, direct output, immediate `exit;`.
- **Realpath Clamping (Scanner):** The optional “additional file” is only accepted if it resolves **inside the active child theme’s `/inc/` directory** using `realpath()` checks.  
  This prevents directory traversal and arbitrary file access.
- **Escaping:** Admin HTML output and URLs are escaped/sanitized (`esc_html__`, `esc_url`, `wp_kses_post`, etc.).

### Performance Notes
- **Zones & Methods Preview** caps to **100 rows** for snappy admin rendering.
- **AST Scanner** only parses the known files (default `shipping-restrictions.php` plus an optional extra file under `/inc/`).
- **CSV Export** is streamed—safe on large datasets.

### Extending the AST Scanner
- The visitor collects nodes in `RateAddCallVisitor`; the admin class then turns those into human-readable strings.
- To add a new pattern:
  1. Extend `RateAddCallVisitor` to capture the nodes you care about.
  2. Add a `describe_node()` branch to render a friendly explanation, optionally inspecting surrounding conditions:
     - We attach `ParentConnectingVisitor` to traverse upward and summarize `if (...)` chains.
  3. Keep descriptions **conservative** (no code execution), using placeholders like `{var}` when needed.

### Error Handling & Troubleshooting
- **Parser not found / self-test fails:**  
  Install/activate the loader plugin above or ensure your autoloader provides PHP-Parser classes.
- **“Additional file not found/invalid”:**  
  Ensure the filename exists **under the child theme’s `/inc/`** directory. Only one extra file is allowed per scan.
- **No findings in scanner:**  
  Not all shipping customizations are AST-detectable with current heuristics; open an issue or extend the visitor.

---

## == How To Use (Store Owners) ==

1. Go to **Tools → KISS Shipping Debugger**.
2. Review the **“Shipping Zones & Methods Preview”**. Use filters to focus on issues or enabled methods only.
3. In **“Custom Rules Scanner”**, optionally type a file path **relative to your child theme’s `/inc/`** (e.g., `extra.php`) and click **Scan**.
4. To archive settings, click **Download CSV of UI Settings** to get a timestamped export.

---

## == FAQ ==

**Does this plugin change my shipping settings?**  
No. It only reads, summarizes, and exports.

**Can I import settings from the CSV?**  
No. This is intended for audits, backups, and developer visibility.

**What if I don’t have the PHP-Parser loader installed?**  
The Zones & Methods preview and CSV export still work. The **AST scanner** and **parser self-test** will be unavailable until a loader provides PHP-Parser classes.

**Why is the preview capped to 100 rows?**  
To keep the admin fast and responsive on stores with many zones/methods. The CSV export has the complete data.

---

## == Changelog ==

See `changelog.md` for detailed version history. Highlights:

- **1.0.7** – Fixed zone warning; cleaner price text; refined method details.  
- **1.0.6** – Restored Zones & Methods Preview with filters, warnings, and deep links.  
- **1.0.5+** – Improved human-readable AST summaries; security hardening; realpath clamping; parser self-test.

---
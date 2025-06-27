# Woo Shipping Exporter

Export every WooCommerce **shipping zone, method, rate, and shipping class** to a
clean CSV—complete with the site name and a timestamp header—directly from the
WordPress admin.

| Plugin | Minimum | Tested up to |
|--------|---------|--------------|
| **Woo Shipping Exporter** | WordPress 6.0 | WordPress 6.5 · WooCommerce 9.0 |

---

## ✨ Features

* **One-click CSV** under **Tools → Shipping CSV Export**  
* CSV starts with the **store name** and **generation timestamp** for easy
  archiving
* Captures:  
  * Shipping **zones** (ID, name, locations)  
  * Zone **methods** (title, type, cost, tax status)  
  * Per-class costs for flat-rate methods  
  * Full **shipping-class catalogue** (ID, slug, description)
* Streams output—no temp files left on the server
* Respects `manage_woocommerce` capability, nonce-verified

---

## 📦 Installation

1. Download or clone the repository to your machine.  
2. Copy the folder to `wp-content/plugins/woo-shipping-exporter/`.  
3. Activate **Woo Shipping Exporter** from **Plugins** in the WP admin.

> **Composer install?**  
> `composer require your-vendor/woo-shipping-exporter` then activate as usual.

---

## 🔧 Usage

1. In the WP admin, navigate to **Tools → Shipping CSV Export**.  
2. Click **Download CSV**.  
3. A file named like  
   `my-store-shipping-2025-06-26-103218.csv` downloads to your browser.

### CSV layout


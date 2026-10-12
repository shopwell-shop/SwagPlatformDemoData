# Shopwell Demo Data Plugin

**Not for production use!** This plugin is intended for development and testing environments only.

## Overview

The **Shopwell Demo Data Plugin** provides realistic sample data for Shopwell: activation imports a
demo catalogue (products with images, categories, properties, rules, shipping methods, a CMS landing
page) and demo customers. The imported data may overwrite existing data.

Demo content currently shipped:

- **30 products** across the usual Chinese e-commerce categories (apparel, digital, home, food, beauty),
  each with **at least 3 images**, bilingual product names/descriptions (`zh-CN` + `en-GB`)
- **30 customers** with realistic names, phone numbers, e-mail addresses and Chinese addresses
- Categories, property groups, cart rules, shipping methods and a demo landing page

This plugin is a Shopwell-maintained fork of
[`shopware/SwagPlatformDemoData`](https://github.com/shopware/SwagPlatformDemoData). See [NOTICE](NOTICE)
for upstream attribution.

## Installation

### Option 1: Using the Shopwell Administration

1. Download the ZIP file.
2. Upload the extension via the **Shopwell Administration**.
3. Install and activate the plugin.

### Option 2: Using the Command Line

1. Clone the repository into the `custom/plugins` directory of your Shopwell installation:

   ```sh
   git clone <repository-url> custom/plugins/SwagPlatformDemoData
   ```

2. Run the following commands from the Shopwell root directory:

   ```sh
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagPlatformDemoData
   ```

The demo data is imported on activation and removed again on deactivation.

### Every demo customer

All demo customers share the password `shopwell123` and can be used to log in on the storefront.

## Demo images

The 91 product and banner images shipped in `src/Resources/media` were downloaded from
[Pexels](https://www.pexels.com) and may be used free of charge under the
[Pexels License](https://www.pexels.com/license/). They are demo content only —
replace them before going live.

## License

This project is licensed under the **Apache License 2.0**. See the [LICENSE](LICENSE) file for details.

It contains software derived from `shopware/SwagPlatformDemoData`, which is licensed under the MIT
License. The upstream license text is preserved in [NOTICE](NOTICE).

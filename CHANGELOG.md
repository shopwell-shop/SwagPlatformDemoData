# 1.0.0
- Forked from `shopware/SwagPlatformDemoData` and adapted to the Shopwell platform.
- Renamed all core references from `Shopware\Core\*` to `Shopwell\Core\*`; the plugin now requires `shopwell/core`.
- Relicensed to Apache-2.0; the upstream MIT license is preserved in `NOTICE`.
- Replaced all demo data with realistic bilingual (`zh-CN` / `en-GB`) content:
  - 30 products across common Chinese e-commerce categories, each with at least 3 images
  - 30 customers with realistic names, phone numbers, e-mail addresses and Chinese addresses
  - localized categories, properties, rules, shipping methods and landing page
- Migrated to the single `name` field on `customer` / `customer_address` (the split `firstName`/`lastName` fields no longer exist).

---

# Upstream history — shopware/SwagPlatformDemoData

# NEXT
- Replaced native time reads in ProductProvider with an injected clock (Psr\Clock\ClockInterface)
- #15568 - Switched to PHP Symfony service definitions

# 2.1.0
- NEXT-40431 - Compatibility with Shopware 6.7.
- NEXT-40431 - Increased the minimum required Shopware version to 6.6.x or 6.7.x.
- NEXT-40431 - Implemented staged execution for demo data generation and deletion to avoid foreign key constraint violations.

# 2.0.1
- NEXT-32194 - Compatibility with Shopware 6.6

# 2.0.0
- NEXT-25677 - Compatibility with Shopware 6.5
- NEXT-25677 - Demodata is now deleted on deactivation

# 1.0.9
- Add pipeline
- Add dev tooling
- Fix plugin validation errors
- Fixed plugin activation by CMS page renaming

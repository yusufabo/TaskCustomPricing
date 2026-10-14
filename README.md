# Customer-specific prices for Shopware 6.7 (`TaskCustomPricing` plugin)

Gives individual customers their own price for a product. When the customer is logged in, the custom price is shown on the product page, in listings and in search, and it is also the price **charged in the cart and the order**. The normal price is shown crossed out next to it.

| | |
|---|---|
| Shopware | 6.7 |
| PHP | 8.4+ |
| Plugin class | `TaskCustomPricing\TaskCustomPricing` |
| Entity | `customer_custom_price` |
| Console command | `taskcustompricing:import-prices` |

## Contents

1. [Features](#features)
2. [Installation](#installation)
3. [Importing prices](#importing-prices)
4. [How it works](#how-it-works)
5. [Tests](#tests)
6. [Structure](#structure)
7. [Limitations](#limitations)

---

## Features

| Area | What it does |
|---|---|
| **Data model** | Entity `customer_custom_price`: one price per customer and product (unique key), `customPrice` ≥ 0 (database `CHECK`). Deleting a customer or a product also deletes its custom prices. |
| **Storefront** | Logged-in customers see their custom price on the product page, in listings, search and sliders. If it is lower than the normal price, the normal price is shown crossed out ("Sale price", with Shopware's default templates). |
| **Cart and checkout** | The custom price is the price that is charged: cart, checkout and order use it. |
| **Other customers and guests** | See the normal price. |
| **HTTP cache** | Customers with custom prices get their own cache hash, so a cached page with one customer's price is never shown to anyone else. |
| **Import** | A console command imports prices from JSON. Running it again updates existing prices instead of creating duplicates. |
| **API** | The entity is registered in the DAL, so prices can also be managed with the Admin API (`/api/customer-custom-price`). |

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate TaskCustomPricing
bin/console cache:clear
```

Uninstalling with `--keep-user-data` keeps the table. Without it, the table `customer_custom_price` is dropped.

## Importing prices

The file is a JSON list. `custom_price` is the **gross price in the default currency**:

```json
[
    { "customer_email": "john@example.com", "product_number": "SW10001", "custom_price": 10 },
    { "customer_email": "ahmed@example.com", "product_number": "SW10000", "custom_price": 20.5 }
]
```

An example is in [`src/Resources/import/prices.example.json`](src/Resources/import/prices.example.json).

```bash
bin/console taskcustompricing:import-prices path/to/prices.json
```

```
Row 1: alice@example.com / SW10001 = 80.00

 [WARNING] Row 2: 0 customer account(s) with email "unknown@example.com" found,
           expected exactly one.

 [OK] Imported 1 price(s), skipped 1 row(s).
```

- **Re-importing:** the price id is derived from customer + product, so importing a file again updates the prices.
- **Skipped rows:** rows with an unknown customer or product, a missing or negative price, or an email used by more than one customer account are skipped with a warning. Guest accounts are ignored. The valid rows are still imported.
- **Exit code:** `0` if every row was imported. `1` if at least one row was skipped, or the file is missing or isn't valid JSON.
- **Cache:** after the import, the cache of the affected products is invalidated.

## How it works

The plugin decorates Shopware's `ProductPriceCalculator` (`CustomerPriceProductCalculator`). Shopware uses this one service to calculate product prices everywhere: product page, listings, search, and the cart. So one decorator covers both what the customer sees and what is charged.

For a logged-in customer, before Shopware calculates the prices:

1. The custom prices for the loaded products are read with one SQL query (`CustomPriceLoader`).
2. For each product with a custom price, the product's `price` is replaced:
   - gross = custom price, net = gross / (1 + tax rate),
   - the normal price becomes the **list price** if it is higher, so the default templates show it crossed out,
   - advanced prices (quantity/rule prices) and the indexed cheapest price are removed for this customer, so the custom price wins.
3. Shopware's own calculator then does the rest as usual: currency conversion, net/gross display, rounding and reference prices.

The original price and the custom price are also available in templates as `product.extensions.customPricing` (`originalPrice`, `customPrice`).

**HTTP cache.** Shopware's cache key doesn't include the customer. `HttpCacheSubscriber` adds the customer id to the cache hash (`HttpCacheCookieEvent`), but only for customers who have custom prices, so all other customers still share the cache.

## Tests

Run from the project root:

```bash
docker compose exec web vendor/bin/phpunit --testsuite TaskCustomPricing --testdox
```

On the host, pass the database URL, because Symfony ignores `.env.local` in the test environment:

```bash
DATABASE_URL=mysql://<user>:<password>@127.0.0.1:33306/shopware vendor/bin/phpunit --testsuite TaskCustomPricing --testdox
```

| Test | Type | What it checks |
|---|---|---|
| `Integration/CustomerCustomPriceTest` | Integration | With the real Shopware services: the customer sees the custom price with the normal price as list price, other customers and guests see the normal price, the cart charges the custom price (2 × 80 = 160), and deleting a product deletes its custom prices. |
| `Integration/Command/ImportCustomPricesCommandTest` | Integration | Import creates a price and a second import updates it (no duplicate). Invalid rows are skipped while valid ones are imported. Invalid JSON fails. |
| `Unit/.../CustomerPriceProductCalculatorTest` | Unit | Price replacement, net calculation, list price only when the custom price is lower, advanced prices removed, a second calculation keeps the original list price, and guests trigger no query. |
| `Unit/Subscriber/HttpCacheSubscriberTest` | Unit | Only customers with custom prices get their own cache hash. |

PHPStan (level max) and PHP-CS-Fixer also cover this plugin, and `bin/console dal:validate` reports no errors.

## Structure

```
custom/plugins/TaskCustomPricing/
├── src/
│   ├── TaskCustomPricing.php                         drops the table on uninstall
│   ├── Command/ImportCustomPricesCommand.php         JSON import
│   ├── Core/Content/
│   │   ├── CustomerCustomPrice/                      definition, entity, collection, CustomPriceLoader
│   │   ├── Extension/                                reverse associations on customer and product (cascade delete)
│   │   └── Product/SalesChannel/Price/
│   │       └── CustomerPriceProductCalculator.php    decorates ProductPriceCalculator
│   ├── Migration/                                    table, foreign keys, unique key, CHECK
│   ├── Subscriber/HttpCacheSubscriber.php            customer-specific cache hash
│   └── Resources/
│       ├── config/services.php
│       └── import/prices.example.json
└── tests/
    ├── Unit/
    └── Integration/
```

## Limitations

- **One price per customer and product.** There are no quantity tiers and no validity period.
- **Variants.** A custom price applies to exactly the product number it was imported for. A price for a parent product isn't inherited by its variants.
- **Prices are stored in the default currency (gross).** Other currencies are converted with the currency factor.
- **No administration UI.** Prices are managed with the import command or the Admin API.

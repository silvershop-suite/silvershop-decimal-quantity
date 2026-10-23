# SilverShop — Decimal quantity

Per-product **decimal / fractional order quantities** for SilverShop (CMS 6). Sell some products by weight,
length or area (kg, m², m) while others stay whole-unit — **in one catalogue**. A product opts in with a
flag; everything else (integer products) behaves exactly as core does today.

```
  Carpet (m²)   →  2.30  ✓        Mug           →  3  ✓   (unchanged)
  Cable  (m)    →  4.50  ✓        T-shirt (S/M/L) → 1  ✓   (unchanged)
  Coffee (kg)   →  0.75  ✓
```

## Contents
- [How it works](#how-it-works) · [Install](#install) · [Per-product setup](#per-product-setup) ·
  [Pricing & rounding](#pricing--rounding) · [Storefront](#storefront) · [Stock](#stock) ·
  [REST / GraphQL API](#rest--graphql-api) · [What's safe / what's conservative](#whats-safe--whats-conservative) ·
  [Configuration](#configuration) · [Requirements](#requirements)

## How it works

Core stores an order line's `Quantity` as an **integer** and hard-casts it to `int` before any extension
can intervene (and its cart hooks are `int`-typed under `strict_types`). So this module does **not** retype
that column. Instead it:

- adds **`QuantityDecimal`** (`Decimal(12,4)`) beside the integer `Quantity`, holding the exact fractional
  amount (0 on integer lines);
- shadows reads with an extension **`getQuantity()`** — SilverStripe's `__get` prefers a getter, so
  `$item->Quantity` returns the decimal for a decimal line and the untouched integer for everything else;
- keeps the integer `Quantity` column as a **`ceil` mirror**, so core's cart plumbing, the stock module's
  int-typed hooks and raw-SQL aggregations stay valid;
- recomputes the **line total** from the decimal via the `updateTotal` hook, and no-ops on integer lines —
  so a whole-number product's line is **byte-identical** to core.

No core method, and no cart controller, is overridden.

## Install

```bash
composer require silvershop-suite/decimal-quantity
```

Run `vendor/bin/sake db:build flush=all`. The extensions are inert on a product until you tick its
decimal flag, so installing shop-wide is safe.

## Per-product setup

In the product's CMS **Quantity** tab:

| Field | Meaning |
|---|---|
| **Sold in decimal/fractional quantities** (`QuantityIsDecimal`) | the opt-in |
| **Unit of measure** (`QuantityUnit`) | display label — `m²`, `kg`, `m`, `L` (optional) |
| **Decimal places** (`QuantityPrecision`) | 0 → the module default (2) |
| **Step increment** (`QuantityStep`) | e.g. `0.25`; 0 → `10^-precision` |
| **Minimum quantity** (`QuantityMin`) | 0 → one step |
| **Maximum quantity** (`QuantityMax`) | 0 → unlimited |

A variation inherits its product's rules (a product is decimal → all its variations are). Everything is
resolved through one accessor, `Product::getDecimalQuantityRules()`, which folds in the config defaults.

## Pricing & rounding

The line total is `round(unitPrice, 2) × quantityDecimal`, rounded once — **money precision (2 dp) is
independent of quantity precision (up to 4 dp)**. The business **step** (e.g. 0.25) is enforced server-side
by `DecimalQuantityValidator` (snap to the nearest step, clamp to min/max, round to precision); the HTML
input's `step` reflects the precision, so the browser accepts any value at that precision and the server
snaps it.

## Storefront

`OrderItem::QuantityField()` returns an Injector-overridden `DecimalShopQuantityField` that renders a
decimal `<input>` (step/min/max from the product) **for decimal lines**, and falls straight back to the
integer widget for everything else. A dedicated **`setdecimalquantity`** cart action lets the storefront
post just the decimal in a `quantitydecimal` var — the server validates/snaps it and derives the integer
ceil mirror; the piggyback path (sending `quantitydecimal` alongside core's `setquantity`) also works.
Override the `DecimalQuantity/Form/DecimalShopQuantityField` template in your theme to restyle; theme JS
reads the input's `data-setquantity-link` and posts the decimal.

## Stock

With **`silvershop/stock`** installed, the core stock extension is swapped (via Injector, guarded) for a
decimal-aware subclass. Stock's quantity column is already a `Varchar`, so fractional levels need **no
migration** — the subclass just drops the `(int)` truncation on the write path: `setStockLevel()` stores a
fractional level, and decrement/increment on placement/cancel move stock by the **exact decimal** ordered.
Integer products are unchanged. (Availability — `hasAvailableStock`/`canPurchase` — stays on core's
integer/ceil path, so a decimal order is conservatively **never oversold**.)

## REST / GraphQL API

With **`silvershop-suite/api`** installed, the order/cart serializer gains **`quantityDecimal`** (the exact
amount) and **`quantityNice`** (e.g. `"2.5 m²"`) on each line; `quantity` stays the whole-unit mirror (so
the GraphQL `Int` type and existing consumers are unaffected). `silvershop-suite/api-graphql` reuses the
same serializer and adds those fields to the line type, plus a **`quantityDecimal: Float`** argument to the
`cartAdd` / `cartUpdate` mutations (the resolver derives the ceil mirror and carries the decimal in the
filter). For the REST cart endpoints, send `quantitydecimal` alongside the request — it flows through to the
same path.

## What's safe / what's conservative

- **Discounts** — read `->Quantity` (the decimal via the getter), so per-item and qty-threshold discounts
  see the fractional amount. Safe.
- **Weight-based shipping** — scales with `->Quantity` (decimal). Safe.
- **Per-item / parcel-count shipping** — uses the integer ceil mirror (you ship whole parcels).
- **Tax** — operates on the (correct) line total. Safe.
- **Bundles** — v1 does not support a decimal product as a bundle parent/component.
- **Availability** — conservative (ceil-based): never oversells; may, at the boundary, decline a fractional
  order the exact on-hand would allow.

## Configuration

```yaml
SilverShop\DecimalQuantity\Extension\ProductDecimalQuantityExtension:
  default_precision: 2   # decimal places when a product doesn't set its own
  default_step: ''       # '' → 10^-precision
  default_min: ''        # '' → one step
```

## Requirements

- PHP `^8.3` · `silverstripe/framework` `^6` · `silverstripe/cms` `^6` · `silvershop/core` `^6.2`

Optional (guarded, per feature): `silvershop/stock` (decimal stock), `tractorcow/silverstripe-fluent`
(unit-label localisation), `silvershop-suite/storefront` (theme input), `silvershop-suite/api` (API output).

## Credits

Authored by Remy Vaartjes and the SilverShop-Suite Contributors. Addresses the long-standing core request
silvershop/silvershop-core#761. Licensed BSD-3-Clause.

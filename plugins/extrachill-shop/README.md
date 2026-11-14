# Extra Chill Shop

WooCommerce integration for the Extra Chill platform.

## What It Does

Extra Chill Shop powers the merch store at shop.extrachill.com:

- **Lifetime Membership** — Ad-free benefit across the network
- **Artist Marketplace** — Artists sell merch with Stripe Connect payouts
- **Shipping Labels** — Integrated USPS label generation via Shippo

## How It Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    SHOP     │ ──▶ │  PURCHASE   │ ──▶ │   FULFILL   │
│   Browse,   │     │  Checkout,  │     │   Labels,   │
│   Cart      │     │  Stripe     │     │   Tracking  │
└─────────────┘     └─────────────┘     └─────────────┘
```

Customers shop on **shop.extrachill.com**. Artists fulfill orders via the shop manager on **artist.extrachill.com**.

## Features

| Feature | Description |
|---------|-------------|
| **Lifetime Membership** | One-time purchase, ad-free on all network sites |
| **Stripe Connect** | Artist payouts via Express onboarding |
| **Shipping Labels** | Shippo integration, $5 flat-rate USPS |
| **Theme Integration** | Dark mode, breadcrumbs, cart icon |

## Products

### Lifetime Membership
- SKU: `ec-lifetime-membership`
- Cross-domain benefit (purchase on shop, ad-free on main site)
- Community username linking at checkout

### Artist Products
Artists manage their own products via the Artist Shop Manager block on artist.extrachill.com.

## Integrations

| System | Purpose |
|--------|---------|
| **Stripe Connect** | Seller payments as direct charges on the seller's Express account, with the commission taken as an application fee |
| **Shippo** | USPS shipping labels |
| **extrachill-users** | Membership storage and validation |
| **Extrachill Theme** | Styling, breadcrumbs, dark mode |

## Requirements

- WordPress 5.0+ (multisite)
- PHP 7.4+
- WooCommerce
- Extrachill theme

## Development

```bash
# Install dependencies
composer install

# Check code standards
composer run lint:php
```

## Documentation


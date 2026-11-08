# Seller shop manager

Shop owns one manager for every seller type at `shop.extrachill.com/manage`. It is the `extrachill/shop-manager` block, built with `@wordpress/scripts` from `src/blocks/shop-manager/` and registered from `build/blocks/shop-manager/block.json`.

## Linking in

Other sites link to the manager; they don't embed it:

```
https://shop.extrachill.com/manage/?seller=artist:12
https://shop.extrachill.com/manage/?seller=venue:1524
https://shop.extrachill.com/manage/?seller=promoter:8
```

An explicit `?seller=` must be one the user can manage. An invalid one shows an error and never falls back to another seller. Without `?seller=`, the manager opens the last seller chosen (`_ec_shop_recent_seller` user meta, written only by the `extrachill/shop-select-seller` ability), then the first seller the user manages.

## Entry points

All of them build the URL with `ec_get_shop_manager_url( $seller )` (Users), or `extrachill_shop_get_shop_manager_url()` on Shop:

- Avatar menu and artist-site nav: plain `/manage/`.
- Artist storefront "Manage Shop" button: `?seller=artist:{id}`.
- Events venue settings and promoter workspace: "Manage shop" with `?seller=venue:{id}` / `?seller=promoter:{id}`, shown only when the shop rollout has reached the user and they hold finance authority for that seller.
- Seller order emails and the Stripe onboarding return URL: the seller's own shop.
- `artist.extrachill.com/manage-shop/` (retired) 302-redirects here with the user's latest artist preselected, keeping `stripe_refresh`.

## Authority

- Discovery: `ec_get_manageable_shop_sellers()` in Users lists artist memberships plus the venues and promoters Events' `extrachill/list-commerce-sellers` reports with finance authority.
- `ec_can_manage_shop()` keeps the `shop` rollout gate and requires at least one manageable seller.
- Shop stores no "who manages what". Every ability re-checks the seller it is given against the owning site.

## Transport

Same-origin from the Shop site, with a REST nonce, and every call names its seller:

- Products, orders, shipping and seller selection: core `/wp-abilities/v1/abilities/extrachill/shop-*/run`.
- Image uploads and Stripe Connect: the existing `extrachill/v1/shop/*` adapters.

## Page

The `/manage` page is created once per site (guarded by the `extrachill_shop_manager_page_id` option). An existing `manage` page is adopted, not overwritten, and a page an editor removes is not recreated.

## Build

```sh
npm ci
npm run build
```

## Navigation and storefronts

- **Storefronts.** Every seller's public storefront lives at `/<type>/<slug>/` on
  the Shop: `shop.extrachill.com/venue/lo-fi-brewing/` pairs with
  `events.extrachill.com/venue/lo-fi-brewing/`, and artists use their profile
  slug (`/artist/<slug>/`). `ec_get_shop_storefront_url()` in Users builds it
  from `{type, id, slug}`; Shop resolves the slug back through the owner
  (Artist profile, or Events `get-commerce-seller` by `slug`), cached an hour.
  The route renders the catalog filtered to the seller with a header (name,
  public page link, "Manage Shop" for people who run it), titles the page
  "<Seller> Merch" and canonicalizes to itself. Unknown slugs 404. The filter
  bar lists every seller with published products; legacy `?seller=type:id`
  links (and the dropdown) redirect to the path, and "All Sellers" goes home.
- **Back link.** The manager header links back to where the seller is managed:
  Manage Artist, or Venue/Promoter Settings (`manage_url`, supplied by Events
  through `extrachill/get-commerce-seller` and `list-commerce-sellers`).
- **Owner pages.** Venue and promoter calendar pages show "Shop Merch" when the
  seller has published products (`ec_shop_seller_has_products()`, cached 10 minutes).
- **Public identity.** `extrachill/get-commerce-seller` resolves name and URLs for
  anonymous visitors; `can_manage_finances` is false for them. Shop caches public
  identity for an hour.
- **Tickets.** Tickets are not Shop products. They are set up on each event and
  sold from the event page; the Payments tab notes that the same Stripe account
  receives ticket sales.

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

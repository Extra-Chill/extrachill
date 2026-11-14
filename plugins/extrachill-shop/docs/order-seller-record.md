# Order seller record

A merch order holds products from at most one seller: checkout refuses mixed
carts, and platform products (no seller, or the platform artist) never mix with
seller products. Every seller fact is one scalar order meta key, written through
WooCommerce CRUD (HPOS and post storage alike):

| Key | Value | Written by |
|---|---|---|
| `_extrachill_seller` | `type:id` seller key | order creation / line-item edits, then checkout |
| `_extrachill_stripe_account` | connected account the order is charged on (`''` for platform orders) | checkout |
| `_extrachill_application_fee` | platform fee in minor units (cents) | checkout |
| `_extrachill_shipment` | `{label_url, tracking_number, tracking_url, carrier, service, cost, purchased_at, …}` | label purchase, "mark shipped" tracking |

`_extrachill_seller` is derived from line items when an order is created
(classic and Store API checkout) or its items are edited in wp-admin. Once
checkout binds the order to a Stripe account (`_extrachill_checkout_session`) the
seller never changes, even if a product later moves to another seller.

Readers (all in `inc/core/order-seller-index.php`):

- `extrachill_shop_get_order_seller( $order )` → seller ref or null.
- `extrachill_shop_order_has_seller( $order, $seller )`.
- `extrachill_shop_get_order_seller_record( $order )` → `seller`,
  `stripe_account`, `total`, `application_fee`, `seller_payout`
  (total − fee) and `items`. Orders that never reached checkout report the fee
  checkout would charge.
- `extrachill_shop_get_order_shipment( $order )` / `extrachill_shop_update_order_shipment()`.
- `extrachill_shop_scope_order_query_to_seller( $args, $seller )` scopes
  `wc_get_orders()`: HPOS uses a native meta query, the post data store the
  `extrachill_seller` query var.

Order listings, label abilities, refunds and seller order emails all read
these; nothing else stores per-seller order data.

## Product seller

Products store their seller the same way for every seller type:
`_extrachill_shop_seller_type` (`artist|venue|promoter`) and
`_extrachill_shop_seller_id`. Products without them are platform products,
managed in wp-admin only.

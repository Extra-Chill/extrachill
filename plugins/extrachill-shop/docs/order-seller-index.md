# Seller-scoped listings

`extrachill/shop-list-orders` and `extrachill/shop-list-products` accept
`seller: {type: "artist"|"venue"|"promoter", id: integer}` or legacy `artist_id`.
Both require seller management permission. `page` defaults to 1 and `per_page`
to 20 (maximum 100). Products retain their array response; orders retain their
existing envelope and artist payout field names, including for other seller types.

Orders store distinct `_extrachill_order_seller` scalar rows (`type:id`) via
WooCommerce CRUD. Classic checkout, Store API checkout, order saves, and admin
line-item edits refresh the index independently of payment processing. Line-item
parent product IDs resolve variation ownership. Platform products are excluded.
HPOS uses native meta queries; the post data store uses a query adapter.

On deployment, `init` schedules a WP-Cron backfill in batches of 100 orders,
including all registered statuses. No CLI is needed. The page checkpoint is
`extrachill_shop_order_seller_index_page`; completion is recorded in
`extrachill_shop_order_seller_index_complete`. Each batch can safely replay.
Existing orders appear in seller listings as their batches finish. Sites that
disable WP-Cron must run their normal scheduled cron runner. To deliberately
rebuild, remove both options; the next request schedules a fresh pass.

The index reflects current product ownership when written, not an immutable
historical seller snapshot. Deleted products cannot supply seller ownership for
a backfill. Existing payment projections remain the source of payout amounts;
without a projection the response includes only the seller's line-item totals
and reports zero payout.

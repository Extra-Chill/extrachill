# Extra Chill Shop abilities

Extra Chill Shop registers 30 abilities, all in the `extrachill-shop` category ("Shop operations: orders, products, shipping, Stripe Connect, and taxonomy counts"). They cover seller products and images, seller orders and refunds, shipping addresses and Shippo labels, Stripe Connect onboarding, seller-settled event tickets, shop manager seller selection, a public product taxonomy count, and operator-only tools for running the shop. Every file registers on `wp_abilities_api_init`; `inc/abilities/register.php` loads them and registers the category.

The abilities are the domain layer. `extrachill-api` routes, `wp extrachill shop` commands, the shop manager block (through core `/wp-abilities/v1/abilities/<name>/run`) and other network sites execute them.

## Common inputs and permissions

- **`seller`**: a typed seller reference, `{ type: "artist" | "venue" | "promoter", id: integer >= 1 }`, both required, no other properties (`extrachill_shop_seller_input_schema()`).
- **Seller permission** (`extrachill_shop_authorize_seller_input`): the input must contain a valid `seller`, and `extrachill_shop_can_manage_seller()` must allow the current user. Network admins (`manage_options`) pass. Everyone else needs the `shop` feature (`ec_feature_available( 'shop' )`). Artist sellers then need `ec_can_manage_artist()`. Venue and promoter sellers need `can_manage_finances` from the Events ability `extrachill/get-commerce-seller`, asked as that user. Results are cached for the request. Some schemas leave `seller` optional, but this callback still refuses a missing `seller` with `missing_seller` (400).
- **Product permission** (`extrachill_shop_authorize_product_input`): `id` must be a `product` post that has a seller. The user must manage that seller. If the input also names a `seller`, the user must manage that seller as well. Products with no seller (platform products) are refused with `product_has_no_seller` (403).
- **Operator permission** (`extrachill_shop_operator_permission`): `current_user_can( 'manage_woocommerce' )` on the Shop site.

The [seller shop manager](shop-manager.md) doc explains how the manager uses these abilities. The [order seller record](order-seller-record.md) doc covers the order data they read.

## Products

### `extrachill/shop-list-products`

- **Purpose:** List one seller's products in `publish`, `pending` or `draft` status, newest ID first.
- **Input:** `seller` (object, enforced by the permission callback), `page` (integer, default 1), `per_page` (integer, default 20, clamped to 1–100).
- **Output:** An array of product objects: `id`, `name`, `description`, `price`, `sale_price`, `manage_stock`, `stock_quantity`, `status`, `permalink`, `seller`, `image {id,url}`, `gallery[]`, `images[]`, `sizes[] {name,stock}` and `ships_free`.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `GET /extrachill/v1/shop/products` ([products routes](../../extrachill-api/docs/routes/shop/products.md)).

### `extrachill/shop-get-product`

- **Purpose:** Read one product in the same shape as the list.
- **Input:** `id` (integer, required).
- **Output:** One product object (see `shop-list-products`). Returns `product_not_found` (404) when the ID is not a product.
- **Permission:** Product permission.
- **Exposure:** `show_in_rest` true. Route `GET /extrachill/v1/shop/products/{id}` ([products routes](../../extrachill-api/docs/routes/shop/products.md)).

### `extrachill/shop-create-product`

- **Purpose:** Create a draft WooCommerce product owned by a seller.
- **Input:** `seller` (object, required), `name` (string, required), `price` (number, required), `sale_price` (number, kept only when greater than 0 and less than `price`), `description` (string), `manage_stock` (boolean), `stock_quantity` (integer), `image_id` (integer), `gallery_ids` (integer[], first 4 kept), `sizes` (array of `{name: string, stock: integer}`), `ships_free` (boolean), `status` (string, enum `draft` only).
- **Output:** The new product object (see `shop-list-products`).
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `POST /extrachill/v1/shop/products` ([products routes](../../extrachill-api/docs/routes/shop/products.md)).
- **Side effects:** Inserts a `product` post (always `draft`) and sets the seller, price, stock, image, gallery and `_ships_free` meta. When `sizes` is set, creates size variations and the `pa_size` attribute, then clears the WooCommerce variation and attribute transients. If the seller can't be assigned, the post is deleted.

### `extrachill/shop-update-product`

- **Purpose:** Update an existing product. Only the fields you send change.
- **Input:** `id` (integer, required), `seller` (object; moves the product to that seller), `name` (string), `price` (number, applied when greater than 0), `sale_price` (number; 0, or a value at or above the regular price, clears it), `description` (string), `manage_stock` (boolean), `stock_quantity` (integer), `status` (string, `draft` or `publish`), `image_id` (integer, 0 clears it), `image_ids` (integer[], new order: first is featured, max 5, all must be attached to the product), `gallery_ids` (integer[], first 4 kept), `sizes` (array of `{name, stock}`; an empty array converts the product to simple), `ships_free` (boolean).
- **Output:** The updated product object (see `shop-list-products`).
- **Permission:** Product permission. When `seller` is sent, the user must manage both the current and the new seller.
- **Exposure:** `show_in_rest` true. Route `PUT /extrachill/v1/shop/products/{id}` ([products routes](../../extrachill-api/docs/routes/shop/products.md)).
- **Side effects:** Updates the post and its meta, variations and seller. `publish` requires a featured image, a seller, and a Stripe account that can receive payments. If the cached Stripe record isn't `active`, it is checked live with Stripe and the seller's Stripe record is updated. When product approval is required, `extrachill_shop_resolve_product_status()` can turn a seller's publish request into `pending`.

### `extrachill/shop-delete-product`

- **Purpose:** Move a product to the trash.
- **Input:** `id` (integer, required).
- **Output:** `{ deleted: true, product_id }`.
- **Permission:** Product permission.
- **Exposure:** `show_in_rest` true. Route `DELETE /extrachill/v1/shop/products/{id}` ([products routes](../../extrachill-api/docs/routes/shop/products.md)).
- **Side effects:** `wp_trash_post()`. The product is trashed, not deleted permanently.

### `extrachill/shop-upload-product-image`

- **Purpose:** Upload images to a product, up to 5 images in total. The first image becomes the featured image and the rest go to the gallery.
- **Input:** `id` (integer, required) and `files` (object[], documented as "handled by transport layer"). The callback ignores `files` and reads the upload from `$_FILES['files']`, so it only works in a multipart request.
- **Output:** The updated product object (see `shop-list-products`).
- **Permission:** Product permission.
- **Exposure:** `show_in_rest` true. Route `POST /extrachill/v1/shop/products/{id}/images` ([product images routes](../../extrachill-api/docs/routes/shop/product-images.md)).
- **Side effects:** Accepts JPEG, PNG, GIF or WebP files up to 5 MB each. Uses `wp_handle_upload()` to write each file, creates attachments with the product as parent, generates attachment metadata, and updates the featured image and `_product_image_gallery`. Refuses with `image_limit_reached` when the product already has 5 images.

### `extrachill/shop-delete-product-image`

- **Purpose:** Remove one image from a product. A product must keep at least one image.
- **Input:** `id` (integer, required) and `attachment_id` (integer, required).
- **Output:** The updated product object (see `shop-list-products`).
- **Permission:** Product permission.
- **Exposure:** `show_in_rest` true. Route `DELETE /extrachill/v1/shop/products/{id}/images/{attachment_id}` ([product images routes](../../extrachill-api/docs/routes/shop/product-images.md)).
- **Side effects:** Reorders the featured image and gallery, then deletes the attachment and its file permanently (`wp_delete_attachment( $id, true )`). Refuses the last image (`cannot_delete_last_image`) and any attachment that isn't attached to the product.

### `extrachill/shop-taxonomy-counts`

- **Purpose:** Count published products per `artist` taxonomy term, either for one term or for the top terms.
- **Input:** `taxonomy` (string, required, enum `artist`), `slug` (string; returns that single term), `limit` (integer, default 8, 1–50).
- **Output:** With `slug`, `{ term_id, slug, name, count, url }`, or `null` when the term is missing or has no published products. Without `slug`, an array of those term objects.
- **Permission:** None (`__return_true`). The ability is public.
- **Exposure:** `show_in_rest` true. The route `GET /extrachill/v1/shop/taxonomy-counts` ([taxonomy counts route](../../extrachill-api/docs/routes/shop/taxonomy-counts.md)) does **not** call this ability. It runs the same logic itself and calls `extrachill/taxonomy-post-counts` directly. No CLI command was found.
- **Side effects:** Bulk queries read and write the `ec_shop_counts_{taxonomy}` transient (6 hours). On a cold cache the ability runs `extrachill/taxonomy-post-counts` when it is registered. Otherwise it falls back to `get_terms()`.

## Seller orders and refunds

### `extrachill/shop-list-orders`

- **Purpose:** The seller's paginated order list for fulfillment. Ticket orders are excluded.
- **Input:** `seller` (object, enforced by the permission callback), `status` (string, `all` | `needs_fulfillment` | `completed`, default `all`), `page` (integer, default 1), `per_page` (integer, default 20, clamped to 1–100).
- **Output:** `{ orders[], total, total_pages, page, per_page, needs_fulfillment_count }`. Each order has `id`, `number`, `status`, `date_created`, `customer {name,email,address}`, `items[]`, `seller_payout`, `order_total`, `disputed`, `refunded_total`, `refundable`, `tracking_number`, `label_url`, `label_cost`, `ships_free_only`, `shipping_held` and `label_parcel`. `all` covers processing, completed, refunded and on-hold orders. `needs_fulfillment` is processing and on-hold orders, without disputed ones. `completed` is completed and refunded orders.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `GET /extrachill/v1/shop/orders` ([orders routes](../../extrachill-api/docs/routes/shop/orders.md)).

### `extrachill/shop-update-order-status`

- **Purpose:** Mark a seller's order shipped (`completed`) and set or replace its tracking number.
- **Input:** `id` (integer, required), `seller` (object, required), `status` (string, required, enum `completed`), `tracking_number` (string).
- **Output:** The updated order in the `shop-list-orders` order shape.
- **Permission:** Seller permission. The callback also refuses orders that don't contain the seller's products (403) and disputed orders.
- **Exposure:** `show_in_rest` true. Route `PUT /extrachill/v1/shop/orders/{id}/status` ([orders routes](../../extrachill-api/docs/routes/shop/orders.md)).
- **Side effects:** Saves tracking to the order shipment and sets the order to `completed`, which sends WooCommerce's Completed order email. If the order is already completed and the tracking is new, a customer note is added instead. If the order is completed with no Extra Chill label, the shipping hold is returned to the seller with a Stripe `ApplicationFee::createRefund` call (`extrachill_shop_release_shipping_hold()`).

### `extrachill/shop-refund-order`

- **Purpose:** Refund a seller's order, in full or in part. The platform application fee is returned in proportion.
- **Input:** `id` (integer, required), `seller` (object, required), `reason` (string), `amount` (number in dollars; defaults to everything still refundable and can't exceed it).
- **Output:** `{ success, order_id, refund_amount, remaining }`.
- **Permission:** Seller permission. The callback also refuses orders that don't contain the seller's products, disputed orders and fully refunded orders.
- **Exposure:** `show_in_rest` true. Route `POST /extrachill/v1/shop/orders/{id}/refund` ([orders routes](../../extrachill-api/docs/routes/shop/orders.md)) and `wp extrachill shop order refund` (`plugins/extrachill-cli/inc/Commands/Shop/OrderCommand.php`).
- **Side effects:** Holds a MySQL `GET_LOCK` per order and calls `wc_create_refund( refund_payment: true )`. The gateway then creates a Stripe refund on the connected account and returns the prorated application fee (see `inc/stripe/refunds.php`). WooCommerce sends its refund emails.

## Shipping

### `extrachill/shop-get-shipping-address`

- **Purpose:** Read a seller's ship-from address and their shipping times and return policy.
- **Input:** `seller` (object, enforced by the permission callback).
- **Output:** `{ seller, address {name,street1,street2,city,state,zip,country}, is_set, return_policy }`. `is_set` is true when both `name` and `street1` are present.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `GET /extrachill/v1/shop/shipping-address` ([shipping address routes](../../extrachill-api/docs/routes/shop/shipping-address.md)) and `wp extrachill shop seller status` (`SellerCommand.php`).

### `extrachill/shop-update-shipping-address`

- **Purpose:** Save a seller's ship-from address, their return policy, or both.
- **Input:** `seller` (object, required), `name`, `street1`, `street2`, `city`, `state`, `zip` (strings), `country` (string, default `US`; the callback always stores `US`), `return_policy` (string). Send `return_policy` alone to update only the policy. Once any address field is sent, `name`, `street1`, `city`, `state` and `zip` are all required.
- **Output:** `{ success, seller, address, return_policy }`.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `PUT /extrachill/v1/shop/shipping-address` ([shipping address routes](../../extrachill-api/docs/routes/shop/shipping-address.md)).
- **Side effects:** Writes the `extrachill_shop_shipping_seller_{type}:{id}` option on the Shop site.

### `extrachill/shop-create-shipping-label`

- **Purpose:** Quote or buy a USPS label through Shippo for a seller's order. The cheapest rate for the parcel is compared with the shipping the buyer paid.
- **Input:** `order_id` (integer, required), `seller` (object, required), `parcel` (object: `weight` number, `mass_unit` `oz`|`lb`, `length`/`width`/`height` numbers in inches; defaults to the order's product dimensions), `quote` (boolean; price only, no purchase), `confirm_overage` (boolean; shop managers only).
- **Output:** On purchase: `{ success, order_id, seller, label_url, tracking_number, tracking_url, carrier, service, cost, shipping_held, parcel }`. When a label already exists: `{ success, reprint: true, ... }` and nothing is bought. With `quote`: `{ success, quote: true, order_id, seller, parcel, carrier, service, cost, shipping_held, exceeds_shipping, can_purchase, requires_confirmation }`.
- **Permission:** Seller permission. The callback also requires a saved ship-from address, a configured Shippo integration, an order containing the seller's products, no dispute, a US destination and at least one item that doesn't ship free. A label that costs more than the shipping hold needs `manage_woocommerce` plus `confirm_overage`.
- **Exposure:** `show_in_rest` true. Route `POST /extrachill/v1/shop/shipping-labels` ([shipping labels routes](../../extrachill-api/docs/routes/shop/shipping-labels.md)).
- **Side effects:** Calls the Shippo API to quote and buy the label. Stores the shipment (label, tracking, cost, rate and transaction IDs, parcel, buyer, hold and overage) on the order. Sets the order to `completed` with a note, which triggers WooCommerce's Completed email. Sends the acting user a branded "Shipping Label Ready" email through `ec_send_email()` when that function exists.

### `extrachill/shop-get-shipping-label`

- **Purpose:** Read the existing label for one of a seller's orders.
- **Input:** `order_id` (integer, required), `seller` (object, required).
- **Output:** `{ order_id, seller, has_label }` merged with the order shipment fields (`label_url`, `tracking_number`, `tracking_url`, `carrier`, `service`, `cost`, ...). Returns `order_not_found` (404) when the order doesn't contain the seller's products.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `GET /extrachill/v1/shop/shipping-labels/{order_id}` ([shipping labels routes](../../extrachill-api/docs/routes/shop/shipping-labels.md)).

### `extrachill/shop-list-shipping-labels`

- **Purpose:** List every purchased label across a seller's non-ticket orders, newest first.
- **Input:** `seller` (object, required).
- **Output:** `{ labels[] }`. Each label has `order_id`, `order_number` and `seller`, plus the shipment fields. Orders without a label are skipped.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. No `extrachill/v1` route ([noted in the shipping labels routes doc](../../extrachill-api/docs/routes/shop/shipping-labels.md)). No CLI command was found.

## Stripe Connect

### `extrachill/shop-stripe-status`

- **Purpose:** Report a seller's Stripe Connect account status and the current fee rates.
- **Input:** `seller` (object, enforced by the permission callback).
- **Output:** `{ connected, status (string|null), can_receive_payments, charges_enabled, payouts_enabled, details_submitted, fees }`. With no account, `connected` is false and every flag is false.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `GET /extrachill/v1/shop/stripe-connect/status` ([Stripe Connect routes](../../extrachill-api/docs/routes/shop/stripe-connect.md)) and `wp extrachill shop seller status` (`SellerCommand.php`).
- **Side effects:** When an account exists, fetches its status from Stripe and updates the seller's cached Stripe record (status, onboarding, `status_synced_at`, `fee_payer`).

### `extrachill/shop-stripe-onboarding-link`

- **Purpose:** Return a Stripe Connect onboarding link for a seller, creating the Express account if needed. The current Seller Agreement must be accepted first.
- **Input:** `seller` (object, enforced by the permission callback) and `accept_seller_agreement` (boolean; records acceptance of the current version first).
- **Output:** `{ success, url, seller_agreement_version }`. Returns `seller_agreement_required` until the acting user has accepted the current Seller Agreement for this seller.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `POST /extrachill/v1/shop/stripe-connect/onboarding-link` ([Stripe Connect routes](../../extrachill-api/docs/routes/shop/stripe-connect.md)).
- **Side effects:** Can record Seller Agreement acceptance. Creates the Stripe Express account (Stripe API) and stores it on the seller's record if none exists. Creates a Stripe account link.

### `extrachill/shop-stripe-dashboard-link`

- **Purpose:** Return a Stripe Express dashboard login link for a seller's connected account.
- **Input:** `seller` (object, enforced by the permission callback).
- **Output:** `{ success, url }`. Returns `no_stripe_account` (400) when the seller has no account.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Route `POST /extrachill/v1/shop/stripe-connect/dashboard-link` ([Stripe Connect routes](../../extrachill-api/docs/routes/shop/stripe-connect.md)).
- **Side effects:** Creates a Stripe login link (Stripe API). It is annotated read-only and stores nothing.

## Shop manager

### `extrachill/shop-select-seller`

- **Purpose:** Remember which seller the current user last managed, so the shop manager reopens on that seller (see [seller shop manager](shop-manager.md)).
- **Input:** `seller` (object, required).
- **Output:** `{ seller }`, the `type:id` key.
- **Permission:** Seller permission.
- **Exposure:** `show_in_rest` true. Called by the shop manager block (`src/blocks/shop-manager/api.js`) through core `/wp-abilities/v1`. No `extrachill/v1` route or CLI command was found.
- **Side effects:** Writes the `_ec_shop_recent_seller` user meta (`EXTRACHILL_SHOP_RECENT_SELLER_META`).

## Tickets

[Seller-settled tickets](tickets.md) covers ticket fees, storage, refund ordering and disputes in depth.

### `extrachill/start-ticket-checkout`

- **Purpose:** Start a seller-settled Stripe Checkout session for event tickets.
- **Input:** `event` (integer, required, minimum 1), `quantity` (integer, required, 1–10), `name` (string, required, 1–200 characters), `email` (string, required, email format), `terms_accepted` (boolean; must be true or the ability returns the `terms_required` error), `ticket_type` (string, optional when the show sells one type, max 40), `code` (string, optional, max 32, case-insensitive).
- **Output:** `{ checkout_url, session_id }`.
- **Permission:** `__return_true`. Admission (Turnstile and rate limiting) is enforced by the API route, not by the ability.
- **Exposure:** `show_in_rest` **false**, so the core ability REST runner can't reach it. Route `POST /extrachill/v1/events/tickets/checkout` runs it locally ([ticket checkout route](../../extrachill-api/docs/routes/events/ticket-checkout.md)).
- **Side effects:** Reads the offer from the Events ability `extrachill/get-event-ticket-offer` (with the code) and requires an open sale, a buyable chosen type, enough remaining inventory for the show and the type after holds, a code that applies to the type and has uses left (`ticket_code_invalid`, `ticket_code_not_applicable`, `ticket_code_used_up`, `ticket_code_free`, `ticket_type_unavailable`), and a seller who can receive payments. Charges the type's discounted `unit_price_minor`; fees use the discounted subtotal. The order stores the type, code and discount. Creates a pending WooCommerce ticket order with the amounts and Terms consent. Creates a Stripe Checkout Session on the seller's connected account with an application fee; the session expires after 30 minutes. Stores the session ID as the order reference. If Stripe fails, the order is deleted.

### `extrachill/get-event-ticket-holds`

- **Purpose:** Report how many tickets for an event are held in open ticket checkouts.
- **Input:** `event` (integer, required, minimum 1).
- **Output:** `{ held, by_type }`: `held` (integer, minimum 0) and `by_type`, held tickets per ticket type ID (orders with no type count as `general`). Counts only, no order or buyer details.
- **Permission:** `__return_true`.
- **Exposure:** `show_in_rest` true, read-only and idempotent. The Events event page reads it across sites through `/wp-abilities/v1` and caches the count for 60 seconds, so "N remaining" and the quantity limit leave out held tickets. Checkout start still enforces holds on its own.
- **Side effects:** None. Counts pending ticket orders whose hold hasn't expired (`extrachill_shop_held_ticket_quantities()`).

### `extrachill/refund-ticket-order`

- **Purpose:** Refund one ticket order that has no checked-in pass.
- **Input:** `order_reference` (string, required, min length 1). This is the Checkout Session ID.
- **Output:** `{ refunded: true }`. Already refunded orders also return true.
- **Permission:** `extrachill_shop_ticket_refund_permission`: the order must exist and the user must manage the order's seller.
- **Exposure:** `show_in_rest` true. Route `POST /extrachill/v1/events/tickets/orders/{reference}/refund` forwards to it through `/wp-abilities/v1` ([guest digest and refunds routes](../../extrachill-api/docs/routes/events/guest-digest-and-refunds.md)).
- **Side effects:** Asks Events (`get-ticket-order-status`) whether a pass was redeemed and refuses if so. Marks the order refund-pending, revokes the passes through Events (`revoke-ticket-passes`), then refunds the payment on Stripe. If the Stripe call fails, the order stays `refund_pending` and the ability can be retried. Disputed orders are refused.

### `extrachill/refund-event-ticket-orders`

- **Purpose:** Refund every paid ticket order for a show, skipping orders with checked-in passes.
- **Input:** `event_id` (integer, required, minimum 1).
- **Output:** `{ refunded, checked_in, failed }` counts.
- **Permission:** `extrachill_shop_event_ticket_refund_permission`: reads the show's ticket offer from Events and requires the user to manage the offer's seller.
- **Exposure:** `show_in_rest` true. Called cross-site through `/wp-abilities/v1` by the Events ability `extrachill/cancel-show-ticket-sales` (`plugins/extrachill-events/inc/Abilities/TicketSalesAbilities.php`). No `extrachill/v1` route or CLI command was found.
- **Side effects:** Runs `extrachill/refund-ticket-order` logic for each order, with the same Stripe refunds and pass revocation. Refunded orders drop out of the query, so a re-run only processes what is left.

### `extrachill/get-event-ticket-order-report`

- **Purpose:** Every paid ticket order for one show, for its sales report and door list export (extrachill#1746). Orders that took money are included: paid, completed, on hold (disputed) and refunded. Unpaid, expired and failed checkouts are left out.
- **Input:** `event_id` (integer, required, minimum 1). No other properties.
- **Output:** `{ orders }`, oldest first. Each order: `order_reference`, `name`, `email`, `ticket_type` (`''` on orders from before ticket types), `code` (`''` for none), `quantity`, `subtotal_minor` (tickets after any code discount), `service_fee_minor`, `discount_minor`, `total_minor` (what the buyer paid), `fees_minor` (Extra Chill's application fee; 0 on a refunded order, whose fee came back to the seller), `refunded_minor` (the whole total on a refunded order, else any partial refund), `currency` and `state`.
- **Permission:** `extrachill_shop_event_ticket_refund_permission`: reads the show's ticket offer from Events and requires the user to manage the offer's seller. Buyer emails and money stay with seller managers.
- **Exposure:** `show_in_rest` true, read-only and idempotent. Called cross-site through `/wp-abilities/v1` as the current user by the Events ability `extrachill/get-show-ticket-report` and the door list CSV export (`plugins/extrachill-events/inc/Abilities/ShowTicketReportAbilities.php`). No `extrachill/v1` route or CLI command.
- **Side effects:** None.

### `extrachill/list-my-ticket-orders`

- **Purpose:** List the current user's ticket orders and their passes.
- **Input:** None (empty object, no additional properties).
- **Output:** `{ orders[] }`. Each order has `reference`, `order_id`, `event_id`, `event_title`, `event_url`, `quantity`, `status`, `created_at` and `passes[] {code,status,url}`.
- **Permission:** `is_user_logged_in`.
- **Exposure:** `show_in_rest` true. No route, CLI command or client caller was found. The account Tickets tab calls the underlying helper directly.

## Operator tools

These abilities read and recover across all sellers. They are for shop operators. The seller-scoped abilities above are the seller-facing surface.

### `extrachill/shop-list-sellers`

- **Purpose:** List every seller that has Shop products or a Stripe Connect record, with cached payment and ship-from state.
- **Input:** `type` (string, `artist` | `venue` | `promoter`; optional filter).
- **Output:** `{ sellers[] }` sorted by type and ID. Each seller has `seller`, `key`, `name`, `platform` (the platform artist), `products` (count across publish, draft, pending and private), `stripe_account`, `stripe_status`, `onboarding_complete` and `ship_from`.
- **Permission:** Operator permission.
- **Exposure:** `show_in_rest` true. `wp extrachill shop sellers list` (`SellersCommand.php`).

### `extrachill/shop-operator-list-orders`

- **Purpose:** List orders across all sellers, optionally for one seller or one WooCommerce status.
- **Input:** `seller` (object, optional), `status` (string, enum `any`, `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`; default `any`), `page` (integer, default 1), `per_page` (integer, default 20, 1–100).
- **Output:** `{ orders[], total, total_pages, page }`. Each order has `id`, `number`, `status`, `date_created`, `seller` (key), `customer` (billing email), `total` and `currency`.
- **Permission:** Operator permission.
- **Exposure:** `show_in_rest` true. `wp extrachill shop orders list` (`OrdersCommand.php`).

### `extrachill/shop-get-order`

- **Purpose:** Read one order with its seller, Stripe references, refunds and Priority Boost receipts.
- **Input:** `id` (integer, required, minimum 1).
- **Output:** `{ id, number, status, date_created, date_paid, seller, customer {name,email}, payment_method, stripe_account, checkout_sessions[], payment_intent, currency, total, shipping_total, total_refunded, items[], refunds[] }`. Priority Boost items include `priority_boost {event,title,granted}`.
- **Permission:** Operator permission.
- **Exposure:** `show_in_rest` true. `wp extrachill shop order show` and `wp extrachill shop order refund` (`OrderCommand.php`).

### `extrachill/shop-replay-webhook`

- **Purpose:** Fetch a Stripe event from the Stripe API and route it through the Shop webhook router again.
- **Input:** `event_id` (string, required, pattern `^evt_…`), `account` (string, pattern `^acct_…`; for Connect events).
- **Output:** `{ event_id, type, account, livemode, routed: true }`.
- **Permission:** Operator permission.
- **Exposure:** `show_in_rest` true. `wp extrachill shop webhook replay` (`WebhookCommand.php`).
- **Side effects:** Calls the Stripe API (`Event::retrieve`) and runs `extrachill_shop_route_webhook_event()`. That runs whatever the handler does for the event type (order, refund, dispute, ticket and email updates). Handlers are meant to be idempotent.

### `extrachill/get-commerce-fee-override`, `extrachill/set-commerce-fee-override`, `extrachill/clear-commerce-fee-override`

- **Purpose:** Read, set or clear the commerce fee override of a seller or one event. Rates resolve network setting → seller → event, field by field ([Fee overrides](tickets.md#fee-overrides)).
- **Input:** `scope` (`event` | `seller`, required), `event_id` (integer, for `event`), `seller` (object, for `seller`). Set also takes `fees` (object, required): any of `merch_percent`, `ticket_percent`, `ticket_buyer_fee_per_ticket_minor`, `ticket_buyer_fee_flat_minor`, `ticket_buyer_fee_percent`, `processing_percent`, `processing_fixed_minor`. A number overrides the field; `null` stops overriding it. Fields not given are unchanged.
- **Output:** `{ scope, event_id, seller, override, global, resolved }`. For an event, `seller` is the event's ticket seller and `resolved` includes that seller's override, as checkout does.
- **Permission:** `manage_network_options` (network admins). Shop operators (`manage_woocommerce`) and sellers are refused.
- **Exposure:** `show_in_rest` true. `wp extrachill shop fees get|set|clear` (`FeesCommand.php`).
- **Side effects:** Set and clear write the Shop blog option or the event post meta. Only checkouts started afterwards are affected.

### `extrachill/shop-retry-priority-boost`

- **Purpose:** Grant every Priority Boost item on an order that has no receipt yet.
- **Input:** `order_id` (integer, required, minimum 1).
- **Output:** `{ order_id, items[] }`. Each item has `item_id`, `event` and `result`: `already_granted`, `granted`, or `error` with an `error` code. Returns `no_priority_boost` (404) when the order has no boost items.
- **Permission:** Operator permission.
- **Exposure:** `show_in_rest` true. `wp extrachill shop boost retry` (`BoostCommand.php`).
- **Side effects:** Calls the Events owner to grant each boost (`extrachill_shop_grant_event_priority_boost()`). Saves `_extrachill_priority_boost_receipt_{item_id}` order meta and adds an order note for each grant or failure.

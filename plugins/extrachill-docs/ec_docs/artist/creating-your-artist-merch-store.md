# How to Create Your Artist Merch Store

The Shop Manager lets you sell merchandise directly to your fans. You can create products, manage inventory, process orders, purchase shipping labels, and handle payments - all from one easy interface.

## Accessing the Shop Manager

Go to `/manage-shop/` to open the Shop Manager. You will see a tabbed interface with four sections:

- Products - Create and manage your merchandise
- Orders - View and fulfill customer orders
- Shipping - Configure your shipping address
- Payments - Set up payment processing

The top of the page shows your artist selector. Use this dropdown if you manage multiple artists to switch between their shops. The "View Shop" button takes you to your public storefront.

## Products Tab

### Creating a Product

Click "Add Product" or "Create your first product" to start. Fill in the product details:

1. **Name** - Your product name (required)
2. **Price** - The selling price in dollars (required, must be greater than zero)
3. **Sale Price** - Optional discounted price (leave blank if not on sale)
4. **Description** - Details about your product for customers
5. **Status** - Choose "Draft" or "Published"

### Adding Product Images

Upload up to 5 images for each product. The first image becomes your main product photo. You can drag images to reorder them.

To add images:
- Click "Choose Images" and select files from your computer
- Images display in a preview grid
- Use the trash icon to remove unwanted images

Published products require at least one image.

### Managing Size Variants

Check "This product has sizes" to offer size options:

- Available sizes: XS, S, M, L, XL, XXL
- Enter stock quantity for each size
- Out-of-stock sizes display as unavailable on product cards

If your product does not have sizes, leave this unchecked and enter a single stock quantity. Leave the stock field blank for unlimited inventory.

### Editing and Deleting Products

Your existing products display in a grid. Each product card shows the image, name, price, status, and size availability. Use the edit icon to modify details or the trash icon to delete a product.

## Orders Tab

### Viewing Orders

Orders appear in a list showing the order number, customer name, date, item count, and your payout amount. Use filters to view:

- All orders
- Orders needing fulfillment
- Completed orders

Click any order card to see full details.

### Order Details

When you select an order, you see:

- Customer name and email
- Shipping address
- Items ordered with quantities and prices
- Your payout amount
- Tracking number (if shipped)

### Purchasing Shipping Labels

For orders needing fulfillment, you can purchase a shipping label directly:

- Click "Print Shipping Label" (available before a tracking number is set)
- The label costs $5 flat rate for USPS
- A new window opens with the printable label
- The tracking number automatically fills into the tracking field

You can reprint labels anytime from the order detail view.

### Marking Orders as Shipped

After shipping an order:

- Enter a tracking number manually (optional if you purchased a label)
- Click "Mark as Shipped"
- The order status changes to "completed"
- The tracking number saves to the order record

### Refunding Orders

To refund an order:

- Click "Refund Order"
- Confirm the full refund amount
- The customer receives a refund to their original payment method
- The order status changes to "refunded"

## Shipping Tab

Set up your shipping address before you can fulfill orders. This is where your packages ship from.

Required fields:
- Name (for shipping label)
- Street address
- City
- State (dropdown selection)
- ZIP code

The country is automatically set to United States for domestic shipping only. Click "Save Address" to store your information.

## Payments Tab

The Payments tab manages your Stripe Connect account. Stripe handles all payment processing and payouts.

### Connecting Stripe

Click "Connect Stripe" to set up payment processing:

- You are redirected to Stripe to create or link your account
- Complete the onboarding process (provides business details and bank information)
- After approval, return to the Shop Manager

### Account Status

Your Stripe status displays with details:

- Connection status (not connected, connected, pending, restricted)
- Charges enabled
- Payouts enabled
- Details submitted
- Can receive payments

Products must be published only after your account can receive payments.

### Managing Your Stripe Account

Click "Open Stripe Dashboard" to access your full Stripe account. From there you can:

- View transaction history
- Manage payouts
- Update bank information
- View detailed financial reports

Use "Refresh Status" to check the latest connection state after making changes in Stripe.

## Requirements

Products must meet these criteria before going live:

- Stripe account connected and able to receive payments
- Product name entered
- Price set above zero
- At least one image uploaded
- Stock configured (if tracking inventory)

## Support

For help with your merch store, visit the [contact page](https://extrachill.com/contact/) or post in the [tech support forum](https://community.extrachill.com/r/tech-support).

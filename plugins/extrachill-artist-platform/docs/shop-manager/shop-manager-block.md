# Artist Shop Manager Block

Complete shop product management Gutenberg block with integrated Stripe payment processing and order fulfillment.

**Location**: `src/blocks/artist-shop-manager/`

**Block Type**: React-based Gutenberg block registered as `extrachill/artist-shop-manager`

## Overview

The Artist Shop Manager block provides a comprehensive interface for artists to:
- Create and manage shop products with images and pricing
- Track inventory with size variants and stock levels
- Manage customer orders and fulfillment
- Configure shipping addresses for order delivery
- Set up and manage Stripe Connect payments
- Purchase USPS shipping labels for orders
- Process refunds and track order status

The block appears on the `/manage-shop/` page (created automatically on plugin activation) and uses a tabbed interface for organizing distinct workflows.

## Tab Structure

### 1. ProductsTab

Complete product management interface for creating, editing, and deleting shop products.

**Location**: `src/blocks/artist-shop-manager/components/tabs/ProductsTab.js`

**Key Features**:

**Product Creation**:
- Product name (required)
- Price field with decimal support (required, must be > 0)
- Sale price field for discounted pricing
- Ships Free toggle for small items (stickers, patches, etc.)
- Product description textarea
- Status selector (Draft or Published)
- Automated validation with helpful error messages

**Image Management**:
- Upload up to 5 images per product
- Drag-and-drop reordering via DraggableList component
- First image displays as featured image on product cards
- Image preview generation during upload
- Delete individual images with automatic cleanup
- Pending image upload queue display
- Batch upload with selected files list

**Image Constraints**:
- Maximum 5 images per product
- Drag-and-drop reordering for published products
- Image URLs cached and cleaned up on deletion
- File preview URLs revoked to prevent memory leaks

**Inventory Management**:
- Toggle between simple stock quantity and size variants
- Simple Mode: Single stock_quantity field for uniform inventory
- Size Variant Mode: Per-size inventory with STANDARD_SIZES array (XS, S, M, L, XL, XXL)
- Auto-calculated total inventory across sizes
- Size stock display on product cards with out-of-stock indicators
- Switching between modes auto-calculates inventory totals

**Size Variants**:
- Fixed size set: XS, S, M, L, XL, XXL
- Per-size stock tracking
- Toggle checkbox to enable/disable size variant mode
- Auto-total calculation when switching modes
- Visual indicator for out-of-stock sizes on product cards

**Product Publishing**:
- Draft status for work-in-progress products
- Published status for public shop display
- Automatic validation before publishing:
  - Product name required
  - Price greater than zero
  - At least one image required
  - Stripe Connect required (can_receive_payments)
- Helpful prompts to complete Stripe setup before publishing
- Status-aware messaging about Stripe requirements

**Product Listing Display**:
- Grid layout of existing products
- Product thumbnail with fallback placeholder
- Product name and pricing display
- Sale price shows when available
- Status badge (draft/published)
- Size variant badges with stock status
- Quick action buttons for edit and delete
- Loading and error state handling

**Stripe Integration** (Products):
- Automatic validation of Stripe payment capability
- Prevents publishing until `can_receive_payments` is true
- Clear messaging when Stripe setup is needed
- Links to PaymentsTab for account configuration
- Status checking with helpful notes about pending/restricted states

**Form Validation**:
- Product name required (non-empty check)
- Price validation (must be number > 0)
- At least one image for published products
- Stripe requirement for publishing
- Auto-scrolling to errors in form
- Clear error messages for each validation type

**State Management**:
- Draft object holds form data during editing
- Separate imagesDraft and pendingImageFiles for image handling
- Editor ID tracks whether creating new or editing existing
- Local error state for form-level validation
- Saving state during API operations
- Show/hide form toggle for list vs. edit view

**REST API Operations**:
```javascript
// Create new product
await createShopProduct(payload);

// Update existing product
await updateShopProduct(productId, payload);

// Delete product (move to trash)
await deleteShopProduct(productId);

// Upload product images
await uploadShopProductImages(productId, fileArray);

// Delete specific product image
await deleteShopProductImage(productId, attachmentId);
```

### 2. OrdersTab

Order management interface for viewing, fulfilling, and processing customer orders.

**Location**: `src/blocks/artist-shop-manager/components/tabs/OrdersTab.js`

**Key Features**:

**Order Filtering**:
- All: Show all orders
- Needs Fulfillment: Processing/On-Hold status requiring action
- Completed: Fulfilled and shipped orders
- Filter buttons update displayed order list
- Refresh button to reload order data

**Order Listing**:
- Card-based layout with order summary
- Order number (#ID format)
- Order status badge with color coding
- Customer name and order date
- Item count with pluralization
- Artist payout calculation and display
- Click to view order details

**Order Detail View**:
- Back button to return to order list
- Order number and status display
- Tabbed sections for organized information

**Customer Information Section**:
- Customer name (bold display)
- Customer email address
- Shipping address with full formatting:
  - Street address (primary and secondary)
  - City, state, ZIP code
  - Country
- Properly formatted address with line breaks

**Items Section**:
- Table display of ordered items
- Product name column
- Quantity column
- Total per item column
- Footer showing artist payout amount

**Shipping & Fulfillment**:
- Shipping label purchasing (USPS, $5 flat rate)
- "Ships Free" handling: Orders containing only free-shipping items do not require platform labels.
- Tracking number entry field
- Status-dependent display:
  - Processing/On-Hold: Show label purchase button
  - Completed: Show existing tracking info
  - Auto-populate tracking after label purchase
- Label reprinting capability via URL link
- External label PDF opening in new tab

**Shipping Label Integration**:
```javascript
// Purchase shipping label
// Endpoint: POST /extrachill/v1/shop/shipping-labels
const result = await purchaseShippingLabel(orderId, artistId);
// Returns: { tracking_number, label_url, tracking_url, carrier, service, cost }
```

**Label Fulfillment Flow**:
1. Artist verifies customer shipping address in **OrdersTab**
2. Artist checks if order is "Ships Free Only" (manual fulfillment required)
3. Artist clicks "Purchase Shipping Label" ($5.00 flat rate charged to platform) if a label is required
4. API selects cheapest USPS rate via Shippo
4. Tracking number is automatically added to order
5. Order status updates to "Completed"
6. Label PDF opens in new tab for printing

**Status Management**:
- Mark as Shipped action (available for processing orders)
- Refund Order action (available for non-refunded orders)
- Refund confirmation dialog with amount display
- Status updates via API with error handling

**Refund Processing**:
- Refund button for eligible orders
- Confirmation dialog showing refund amount
- Full refund processing
- Order status update to refunded
- Clear success/error messaging

**REST API Operations**:
```javascript
// Mark order as shipped with optional tracking
await onMarkShipped(orderId, trackingNumber);

// Process order refund
await onRefund(orderId);

// Purchase USPS shipping label
await purchaseShippingLabel(orderId, artistId);

// Refresh order data
await onRefresh();
```

**State Management**:
- Selected order state for detail view
- Filter state (all/needs_fulfillment/completed)
- Tracking number input field
- Action loading state during operations
- Label purchasing state for async operations
- Label success confirmation state
- Error state for action failures

### 3. PaymentsTab

Stripe Connect account setup and payment configuration interface.

**Location**: `src/blocks/artist-shop-manager/components/tabs/PaymentsTab.js`

**Key Features**:

**Connection Status Display**:
- Current connection status: connected/not connected
- Charges enabled indicator (yes/no)
- Payouts enabled indicator (yes/no)
- Details submitted indicator (yes/no)
- Can receive payments indicator (yes/no)

**Stripe Account States**:
- `not connected`: No Stripe account linked
- `connected`: Account linked and active
- `pending`: Setup in progress (details being verified)
- `restricted`: Account has restrictions preventing payments

**Account Actions**:
- Connect Stripe button (when not connected)
  - Initiates OAuth flow with Stripe
  - Redirects to Stripe login/authorization
  - Handles redirect back to dashboard
- Open Stripe Dashboard button (when connected)
  - Direct link to artist's Stripe account dashboard
  - Allows manual account management
- Refresh Status button
  - Polls current Stripe status
  - Updates all indicators
  - Useful after setup completion

**Informational Notes**:
- Products require Stripe setup before publishing
- Clear messaging about account restrictions
- Helpful guidance for pending accounts
- Notes display only when relevant

**REST API Operations**:
```javascript
// Check Stripe connection status
// Returns: { connected, status, charges_enabled, payouts_enabled, details_submitted, can_receive_payments }

// Initiate Stripe Connect flow
await onConnect();

// Open Stripe dashboard
window.open(stripeDashboardUrl);
```

**Artist Context**:
- Requires artist selection to display options
- Empty state message when no artist selected
- Context-aware messaging about setup requirements

### 4. ShippingTab

Shipping address configuration for order fulfillment.

**Location**: `src/blocks/artist-shop-manager/components/ShippingTab.js`

**Key Features**:

**Address Form Fields**:
- Full name (required)
- Street address 1 (required)
- Street address 2 (optional for suite/apartment)
- City (required)
- State dropdown (required, US states)
- ZIP code (required)
- Country (fixed to US)

**Form Validation**:
- All required fields validated on save
- Clear error messages for each field
- Prevents submission with empty required fields
- Dedicated error display
- Success confirmation after save

**State Dropdown**:
- Comprehensive list of 50 US states plus DC
- Alphabetically sorted
- Default "Select State" option
- Native dropdown for accessibility

**Data Persistence**:
- Address automatically loaded on mount
- Previous address values pre-populated
- Changes persist via REST API
- Success feedback notification
- Error handling with user messaging

**REST API Operations**:
```javascript
// Fetch current shipping address
const data = await getArtistShippingAddress(artistId);
// Returns: { address: { name, street1, street2, city, state, zip } }

// Save/update shipping address
await updateArtistShippingAddress(artistId, address);
```

**Loading States**:
- Initial load state while fetching address
- Saving state during form submission
- Disabled form controls during save
- Success flash notification

## Block Architecture

### File Structure

```
src/blocks/artist-shop-manager/
├── block.json                    # Block metadata
├── index.js                      # Block registration
├── edit.js                       # Block editor component (stub)
├── render.php                    # Server-side rendering
├── editor.scss                   # Editor styles
├── style.scss                    # Block styles
├── view.js                       # Frontend view script
├── components/
│   ├── tabs/
│   │   ├── ProductsTab.js       # Product management
│   │   ├── OrdersTab.js         # Order management
│   │   ├── PaymentsTab.js       # Stripe integration
│   │   └── ShippingTab.js       # Address configuration
│   └── [shared components]      # Reusable components
└── [context/hooks if needed]    # State management
```

### Key Dependencies

**WordPress Packages**:
- `@wordpress/element`: React and hooks
- `@wordpress/api-fetch`: REST API client setup
- `@wordpress/blocks`: Block registration
- `@wordpress/components`: UI components

**External Libraries**:
- None (uses shared API client from `src/blocks/shared/api/client.js`)

### Edit Component

**Location**: `src/blocks/artist-shop-manager/edit.js`

The edit component is a stub that renders the shop manager UI. The main Edit component should:
- Import all tab components (ProductsTab, OrdersTab, PaymentsTab, ShippingTab)
- Manage top-level state (selected artist, current tab, data)
- Handle tab switching
- Provide tab callbacks for data operations
- Load and refresh shop data
- Integrate artist switcher component

### State Management Pattern

The block uses React hooks for state management:

**Props Flow**:
```
Edit (parent)
  ├── currentTab, setCurrentTab
  ├── selectedArtist, setSelectedArtist
  ├── products, orders, stripeStatus, address
  ├── loading, error states
  └── Pass to relevant tab component

Each Tab Component
  ├── Receives parent state
  ├── Manages local form state
  ├── Calls REST API via shared client
  ├── Updates parent on success
  └── Shows errors to user
```

## REST API Integration

**API Client Location**: `src/blocks/shared/api/client.js`

All API calls use the unified client with automatic nonce handling and error management.

### Available Methods

**Product Operations**:
```javascript
createShopProduct(payload)
updateShopProduct(productId, payload)
deleteShopProduct(productId)
uploadShopProductImages(productId, fileArray)
deleteShopProductImage(productId, attachmentId)
```

**Order Operations**:
```javascript
getShopOrders(artistId, filter)
markOrderShipped(orderId, trackingNumber)
refundOrder(orderId)
purchaseShippingLabel(orderId, artistId)
```

**Payment Operations**:
```javascript
getStripeStatus(artistId)
initiateStripeConnect(artistId)
```

**Shipping Operations**:
```javascript
getArtistShippingAddress(artistId)
updateArtistShippingAddress(artistId, address)
```

## Stripe Integration Details

### Connection Flow

1. Artist clicks "Connect Stripe" button
2. OAuth redirect to Stripe authorization
3. User logs in or creates Stripe account
4. Approves plugin to access account
5. Redirect back to shop manager with status
6. Status automatically refreshed and displayed

### Payment Capability Requirements

**For Publishing Products**:
- `connected` = true (account is linked)
- `can_receive_payments` = true (all setup complete)

**Setup Requirements**:
- Details submitted (KYC verification)
- Charges enabled (ability to accept payments)
- Payouts enabled (ability to receive funds)

### Account States

- **not connected**: No Stripe account linked
- **pending**: Awaiting account verification
- **restricted**: Account has limitations
- **connected**: Fully operational

### Validation Behavior

- Products cannot be published without `can_receive_payments`
- Error messaging guides users to complete setup
- PaymentsTab shows clear status indicators
- ProductsTab prevents publishing with helpful prompt

## Inventory Management

### Stock Tracking

**Simple Mode** (default):
- Single `stock_quantity` field
- Represents total available inventory
- Use for single-size products
- Optional (null = unlimited)

**Size Variant Mode**:
- Per-size inventory tracking
- Array of sizes with stock values
- Fixed size set: XS, S, M, L, XL, XXL
- Auto-calculated total display
- Recommended for apparel products

### Mode Switching

When toggling from Size Variant to Simple mode:
- Auto-calculates total from all sizes
- Populates stock_quantity field
- Clears sizes array

When toggling from Simple to Size Variant:
- Clears stock_quantity field
- Initializes all sizes with 0 stock
- User manually sets per-size values

## Image Management

### Upload Process

1. User selects image files (max 5 total)
2. Preview URLs generated via `URL.createObjectURL()`
3. Pending images shown in separate grid
4. User saves/creates product first
5. Pending images uploaded via `uploadShopProductImages()`
6. Preview URLs revoked to prevent memory leaks

### Ordering

- First image (index 0) displays as featured
- Gallery images (index 1+) display in order
- Drag-and-drop reordering updates order
- Reorder API call persists new order

### Cleanup

- Preview URLs revoked on unmount
- Old attachment IDs tracked and cleanup on reorder
- File inputs cleared after selection

## Build & Deployment

### Development

```bash
# Watch mode for active development
npm run start

# Builds block to build/blocks/artist-shop-manager/
# Watches for changes and rebuilds automatically
```

### Production Build

```bash
# Create minified production bundle
npm run build

# Output to build/blocks/artist-shop-manager/
# Ready for deployment
```

### Block Registration

```php
// Automatically registered on init
register_block_type( __DIR__ . '/build/blocks/artist-shop-manager' );
```

## Security Considerations

### Nonce Handling
- All REST requests include nonce from API client
- WordPress verifies nonce on endpoints
- No sensitive data in client-side state

### Permission Checks
- Artist context required (user must be associated)
- Backend validates artist ownership
- Only artists can view/edit own shop
- Admin can access if permitted

### Data Validation
- Product prices validated (> 0)
- Images scanned by WordPress media library
- Shipping address validated by form
- All REST payloads sanitized server-side

### File Uploads
- Images validated by WordPress media uploader
- File types checked (image/* only)
- File size limits enforced
- Old attachments cleaned up on delete

## Performance Optimization

### Image Handling
- Lazy preview generation
- Preview URL cleanup prevents memory leaks
- Drag-and-drop uses index-based updates
- Minimal re-renders during form editing

### API Operations
- Single refresh call combines all data
- Minimal API calls during editing
- Batch image operations supported
- Error recovery without full reload

### Component Optimization
- Tab components mounted only when active
- Form state isolated to tab components
- Context updates only affected tabs
- Memoization prevents unnecessary renders

## Accessibility

### Keyboard Navigation
- Tab through all form inputs
- Enter to submit forms
- Escape to close modals
- Arrow keys for dropdowns

### Screen Readers
- Proper labels on all inputs
- Status messages announced
- Error messages associated with fields
- Image alt text required

### Color Contrast
- All text meets WCAG AA standards
- Status indicators use text + color
- Buttons clearly labeled
- Focus states visible

## Troubleshooting

### Products Not Saving
1. Check browser console for JavaScript errors
2. Verify nonce is being sent in API requests
3. Ensure user has edit_post capability for artist
4. Check product validation (name, price > 0, image)

### Images Not Uploading
1. Verify image file size is within limit
2. Check WordPress media library is functional
3. Ensure proper user permissions
4. Check file is valid image format

### Stripe Not Connecting
1. Verify Stripe credentials are configured
2. Check OAuth redirect URI is correct
3. Ensure HTTPS is enabled (Stripe requirement)
4. Check browser console for redirect errors

### Orders Not Showing
1. Verify shop orders exist in database
2. Check artist association is correct
3. Ensure proper user permissions
4. Check order data in database

## Future Enhancements

- Product variants (color, material, etc.)
- Advanced inventory management
- Automated refund processing
- Email notifications for orders
- Product reviews and ratings
- Discount codes and coupons
- Multiple shipping options
- International shipping
- Custom product attributes
- Bulk order operations

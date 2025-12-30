# Event Submission Block

Frontend block for collecting event submissions that route into Data Machine flows.

## Block Details

- **Name**: `extrachill/event-submission`
- **Version**: 0.2.8
- **Category**: widgets
- **Icon**: calendar-alt

## Block Attributes

### headline
- **Type**: string
- **Default**: "Submit an Event"
- **Description**: Main heading displayed above the form

### description
- **Type**: string
- **Default**: "Share your show details and we'll review them for the events calendar."
- **Description**: Descriptive text explaining the submission process

### flowId
- **Type**: string
- **Default**: ""
- **Description**: Data Machine flow ID for processing submissions

### successMessage
- **Type**: string
- **Default**: "Thanks! We'll review your submission and reach out if we need anything else."
- **Description**: Message shown after successful submission

### buttonLabel
- **Type**: string
- **Default**: "Send Submission"
- **Description**: Text for the submit button

## Form Fields

### Conditional Contact Fields (for non-logged-in users)
- **Contact Name**: Required text field
- **Contact Email**: Required email field

### Event Details
- **Event Title**: Required text field
- **Event Date**: Required date picker
- **Event Time**: Optional time picker
- **Venue**: Optional text field
- **City / Region**: Optional text field
- **Lineup / Headliners**: Optional text field
- **Ticket or Info Link**: Optional URL field

### Additional Information
- **Additional Details**: Optional textarea (4 rows)
- **Flyer Upload**: File upload (JPG, PNG, WebP, PDF)

### Security
- **Cloudflare Turnstile**: Spam protection widget

## Form Behavior

### User State Handling
- **Logged-in Users**: Contact fields hidden, submission attributed to current user
- **Anonymous Users**: Contact fields required for identification

### Validation
- Required fields: Contact name/email (if shown), event title, event date
- Email format validation
- URL format validation for ticket links
- File type restrictions for uploads

### Submission Process
1. Form data collected via JavaScript
2. Cloudflare Turnstile token included
3. POST request to `/wp-json/extrachill/v1/event-submissions` endpoint
4. Data routed to specified Data Machine flow
5. Success/error messages displayed

## Styling

### CSS Classes
- `.ec-event-submission` - Main container
- `.ec-event-submission__inner` - Inner wrapper
- `.ec-event-submission__headline` - Form heading
- `.ec-event-submission__description` - Form description
- `.ec-event-submission__form` - Form element
- `.ec-event-submission__grid` - Field grid container
- `.ec-event-submission__field` - Individual field wrapper
- `.ec-event-submission__field--full` - Full-width field
- `.ec-event-submission__field--file` - File upload field
- `.ec-event-submission__turnstile` - Security widget container
- `.ec-event-submission__actions` - Submit button container
- `.ec-event-submission__status` - Status message area

### Responsive Design
- Grid layout adapts to screen size
- Fields stack on mobile devices
- Button maintains full width on small screens

## Dependencies

### Required Functions
- `ec_enqueue_turnstile_script()` - Loads Cloudflare Turnstile JavaScript
- `ec_render_turnstile_widget()` - Renders security widget

### REST API Endpoint
- **Route**: `/wp-json/extrachill/v1/event-submissions`
- **Method**: POST
- **Purpose**: Processes form submissions and routes to Data Machine flows

## File Structure

```
blocks/event-submission/
├── block.json          # Block registration and attributes
├── render.php          # Server-side rendering
├── style.css           # Frontend and editor styles
├── view.js             # Frontend JavaScript
├── src/
│   ├── index.js        # Block registration (compiled)
│   └── edit.js         # Editor interface (compiled)
└── build/              # Compiled assets (generated)
```

## Usage

1. Add the "Event Submission Form" block to any page
2. Configure the block settings in the editor sidebar
3. Set the Flow ID to route submissions to the correct Data Machine flow
4. Customize headline, description, and button text as needed
5. The form handles the rest automatically</content>
<parameter name="filePath">docs/blocks/event-submission.md
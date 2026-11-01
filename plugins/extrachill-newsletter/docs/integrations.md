# Newsletter Integrations

The Extra Chill Newsletter system uses a declarative integration architecture that allows different parts of the platform to register subscription contexts and render forms consistently.

## Subscription Contexts

A "context" defines the configuration for a newsletter form, including which Sendy list it subscribes to and how the form is styled.

The following contexts are registered by default:

- `homepage`: Main hero section subscription form on the blog homepage.
- `navigation`: Minimal form in the site navigation/footer (displayed on every page).
- `content`: Subscription section displayed after post content.
- `archive`: Form displayed on the newsletter archive page.
- `contact`: Checkbox integration for contact forms (uses a separate list).

## Member preferences and account deletion

The logged-in abilities `extrachill/newsletter-get-my-subscriptions` (empty input)
and `extrachill/newsletter-set-my-subscription` (`{ "list": "<key>", "subscribed": true }`)
are REST-visible through core `/wp-abilities/v1`. Both use the current account's
email exclusively and reject extra input fields, including email and user ID.
The getter returns `{ "lists": [{ "key": "newsletter", "name": "Extra Chill newsletter", "subscribed": true }] }`.

Campaigns go to every network list (`all_lists`), so each signup form's list is
a source of the same newsletter, not a separate newsletter. Members therefore see
one preference, `newsletter`:

- **Subscribed** means the account email is `Subscribed` on any configured list.
- **On** subscribes it to the registration list (falling back to homepage).
- **Off** unsubscribes it from every configured list, deduplicated by Sendy ID.

Per-form keys and raw Sendy IDs are not accepted, and no Sendy IDs or credentials
are returned. Sendy status failures return errors rather than falsely reporting
an opt-out. The `lists` array keeps room for genuinely separate newsletters later.

Account deletion listens to `extrachill_user_self_deleted` and unsubscribes the
supplied pre-deletion email from **all** configured integration lists, including
non-member lists, once per distinct Sendy ID. Each failure is logged without PII
and does not prevent other lists being attempted or account deletion proceeding.
The optional users-plugin unsubscriber callable is intentionally not implemented
in addition to the action, avoiding duplicate requests during deletion.

Credentials come only from Data Machine Business. Subscription and status use its
existing Sendy primitive/client; unsubscribe follows the same form-encoded HTTP
contract against `/unsubscribe`. Admin settings abilities are unchanged.

Smoke coverage (requires the installed Data Machine Business source):

```sh
php tests/member-subscriptions-smoke.php /path/to/data-machine-business/inc/Sendy/SendyClient.php
```

## Registering New Integrations

To add a new integration context, use the `newsletter_form_integrations` filter. This adds the context to the Newsletter Settings page on `newsletter.extrachill.com`, where an administrator can map it to a Sendy List ID.

```php
/**
 * Register a custom newsletter integration
 */
add_filter( 'newsletter_form_integrations', function( $integrations ) {
    $integrations['my_feature'] = array(
        'label'       => __( 'My Feature Form', 'text-domain' ),
        'description' => __( 'Subscription form for my custom feature', 'text-domain' ),
        'list_id_key' => 'my_feature_list_id', // Key used in settings storage
    );
    return $integrations;
});
```

## Rendering Forms

Once a context is registered (and configured in settings), you can render the corresponding form using the `extrachill_render_newsletter_form` action.

```php
/**
 * Render the form in a template
 */
do_action( 'extrachill_render_newsletter_form', 'my_feature' );
```

### Context Presets

You can define default display arguments for your context by filtering `extrachill_newsletter_form_args`.

```php
add_filter( 'extrachill_newsletter_form_args', function( $args, $context ) {
    if ( $context === 'my_feature' ) {
        $args = array(
            'wrapper_class' => 'my-feature-newsletter',
            'heading'       => __( 'Join the List', 'text-domain' ),
            'description'   => __( 'Subscribe for custom updates.', 'text-domain' ),
            'layout'        => 'section', // 'section' or 'inline'
            'button_text'   => __( 'Sign Up', 'text-domain' ),
        );
    }
    return $args;
}, 10, 2 );
```

## Contact Form Integration

The `contact` context is specialized. Instead of rendering a full form, it is typically used by the `extrachill-contact` plugin to add a "Subscribe to newsletter" checkbox to contact forms.

When the contact form is submitted, the contact plugin calls:
```php
extrachill_multisite_subscribe( $email, 'contact' );
```

## REST API Integration

All frontend forms rendered via `extrachill_render_newsletter_form` are automatically handled by `assets/js/newsletter.js`, which submits data to the REST API:

- **Endpoint**: `POST /wp-json/extrachill/v1/newsletter/subscribe`
- **Payload**: `{ "email": "...", "context": "..." }`

# Integration Patterns

## Hook-Based Extensibility
The platform uses WordPress filters and actions for cross-plugin communication and extensibility.

## Filter Patterns
- `extrachill_ai_tools` - Register AI tools across plugins
- `extrachill_template_homepage` - Override homepage templates
- `extrachill_breadcrumbs_*` - Customize breadcrumb navigation
- `newsletter_form_integrations` - Register newsletter contexts

## Action Hooks
- `extrachill_artist_created` - Trigger actions when artists are created
- `extrachill_user_registered` - User lifecycle events
- Theme hooks for UI integration (`extrachill_header_*`, `extrachill_sidebar_*`)

## Function Existence Checks
Always verify cross-plugin functions exist before calling:
```php
if (function_exists('ec_get_artist_data')) {
    $data = ec_get_artist_data($artist_id);
}
```

## Shared Services
- `extrachill-ai-client` - Centralized AI provider management
- `extrachill-users` - Network-wide user management
- `extrachill-multisite` - Cross-site data access
- `extrachill-api` - REST API infrastructure
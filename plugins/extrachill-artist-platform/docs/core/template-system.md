# Template System

The platform implements a dual template architecture supporting both full page rendering and modular component templates.

## Page Templates

### ExtraChillArtistPlatform_PageTemplates Class

Singleton class handling full page template loading and routing.

```php
// Get instance
$templates = ExtraChillArtistPlatform_PageTemplates::instance();
```

### Template Hierarchy

The system integrates with WordPress template hierarchy:

- **Artist Profiles**: `archive-artist_profile.php`, `single-artist_profile.php`
- **Link Pages**: `single-artist_link_page.php` via custom routing
- **Management Pages**: Custom template loading for admin interfaces

### Template Locations

Templates are organized by functionality:

```
inc/artist-profiles/frontend/templates/
├── archive-artist_profile.php
├── single-artist_profile.php
├── artist-profile-card.php
└── manage-artist-profiles.php

inc/link-pages/live/templates/
├── single-artist_link_page.php
└── extrch-link-page-template.php

inc/link-pages/management/templates/
└── components/
    ├── link-item-editor.php
    └── social-item-editor.php
```

## Component Templates

### ec_render_template() System

Unified template rendering function for modular components:

```php
/**
 * Render template component
 * 
 * @param string $template_name Template file name (without .php)
 * @param array $args Variables to extract in template
 * @return string Rendered HTML
 */
$html = ec_render_template('social-icon', [
    'social_data' => $social_link,
    'social_manager' => $manager
]);
```

### Template Functions

Specialized rendering functions for common components:

```php
// Render single link
$link_html = ec_render_single_link($link_data, $args);

// Render link section with multiple links
$section_html = ec_render_link_section($section_data, $args);

// Render social icon
$social_html = ec_render_social_icon($social_data, $social_manager);

// Render social icons container
$container_html = ec_render_social_icons_container($social_links, 'above', $social_manager);
```

## Template Data Flow

### Data Preparation

Templates receive processed data from the centralized data system:

```php
// Get comprehensive data
$data = ec_get_link_page_data($artist_id, $link_page_id);

// Extract for template
extract($data);

// Access in template
echo $display_title;
echo $bio;
```

### CSS Variables Integration

Templates automatically include CSS variable generation:

```php
// Generate CSS block
$css_block = ec_generate_css_variables_style_block($css_vars, 'link-page-custom-vars');

// Output in template
echo $css_block;
```

## Live Preview Integration

The Gutenberg block editor in `src/blocks/link-page-editor/` provides live preview of changes through React components. The `Preview.js` component displays real-time updates as artists make changes to their link pages.

```php
// Preview data uses same function as templates
$preview_data = ec_get_link_page_data($artist_id, $link_page_id);
```

## Template Arguments

### Standard Arguments

Common arguments passed to templates:

```php
$template_args = [
    'artist_id' => 123,
    'link_page_id' => 456,
    'display_title' => 'Artist Name',
    'bio' => 'Artist biography',
    'profile_img_url' => 'image-url.jpg',
    'css_vars' => [...],
    'link_sections' => [...],
    'social_links' => [...],
    'settings' => [...]
];
```

### Component-Specific Arguments

Templates receive context-appropriate data:

```php
// Link component arguments
$link_args = [
    'link_url' => 'https://example.com',
    'link_text' => 'Visit Site',
    'link_description' => 'Optional description'
];

// Social component arguments
$social_args = [
    'social_data' => ['type' => 'spotify', 'url' => '...'],
    'social_manager' => $manager_instance
];
```

## Template Security

All templates implement proper escaping:

```php
// Escaped output
echo esc_html($display_title);
echo esc_url($link_url);
echo esc_attr($css_class);

// Safe CSS output
echo ec_generate_css_variables_style_block($css_vars);
```

## Template Customization

Templates support WordPress filters for customization:

```php
// Filter template output
$html = apply_filters('extrch_template_output', $html, $template_name, $args);

// Filter template arguments
$args = apply_filters('extrch_template_args', $args, $template_name);
```
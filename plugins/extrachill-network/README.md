# ExtraChill Network

Network-activated WordPress plugin providing the network administration foundation for the ExtraChill Platform.

## Overview

This WordPress network plugin serves as the **network administration foundation** for the ExtraChill multisite network, providing essential network-wide functionality that supports all 11 active sites (Blog IDs 1–5, 7–12).

Current native subsites include Blog, Community, Shop, Artist Platform, Events, Newsletter, Docs, Wire, and Studio.

## Features

- **Network Admin Menu Consolidation** - Centralized top-level network admin menu for all ExtraChill Platform settings and configuration
- **Cloudflare Turnstile Integration** - Network-wide captcha management and configuration accessible from all network sites
- **OAuth Provider Settings** - Network-wide Google OAuth configuration with helper functions for extrachill-users integration
- **Payment Provider Settings** - Network-wide Stripe configuration for extrachill-shop integration

## Purpose

This plugin maintains focused responsibility for network administration infrastructure. Historical features like user management, search, and newsletter integration have been successfully migrated to specialized plugins (extrachill-users, extrachill-search, extrachill-newsletter) following the platform's single responsibility principle.



## Architecture

- **Network Activated** - Single plugin serving all sites in the multisite network
- **Cross-Site Data Access** - Uses `switch_to_blog()` / `restore_current_blog()` for cross-site operations
- **Performance Optimized** - Central blog ID + domain map helpers for fast cross-site resolution
- **Cross-Site Routing** - Route and ability ownership resolution, plus the in-process/HTTP-loopback dispatcher — see [`docs/cross-site-routing.md`](docs/cross-site-routing.md) for the full model
- **Centralized Configuration** - Network-wide settings stored via `get_site_option()` accessible from all sites
- **Modular Organization** - Core functionality in `inc/core/`, site-specific features in dedicated directories, admin interface in `admin/`
- **Security First** - Comprehensive admin access control, Cloudflare Turnstile integration, and capability checks

## Requirements

- WordPress 5.0+
- WordPress Multisite installation
- PHP 7.4+

## Email helpers

Centralized branded mail for the EC platform. Consumer plugins call
`ec_send_email()` instead of raw `wp_mail()` and get:

- EC-branded HTML wrapper (header, link grid, footer) via the
  `extrachill/branded` template registered against
  `datamachine_email_templates`.
- Automatic `switch_to_blog()` to an SMTP-configured site via
  `mail_site_id`, so sends originating on a subsite without local SMTP
  credentials no longer silently fail.

### Quick usage

```php
ec_send_email( array(
    'to'      => $user_email,
    'subject' => 'Extra Chill Got Your Message',
    'context' => array(
        'recipient_name' => $user_name,
        'body_html'      => '<p>Thanks for reaching out!</p>',
        'preheader'      => 'We received your message.',
    ),
) );

// Queued / non-blocking variant (Action Scheduler):
ec_send_email_queued( array(
    'to'      => $user_email,
    'subject' => 'Welcome to Extra Chill',
    'context' => array(
        'recipient_name' => $user_name,
        'body_html'      => '<p>Welcome aboard.</p>',
        'cta_url'        => $onboarding_url,
        'cta_label'      => 'Complete your account',
    ),
) );
```

### Templates

- `extrachill/branded` — full EC wrapper with platform link grid + footer.
  Use for welcome emails, confirmations, anything non-urgent.
- `extrachill/minimal` — stripped wrapper without the link grid.
  Use for transactional sends (password reset, 2FA, settings changes).

Templates live as PHP partials under `templates/email/` and accept the
following context keys (all optional):

| Key              | Type   | Notes                                           |
|------------------|--------|-------------------------------------------------|
| `subject_html`   | string | Pre-escaped subject for the `<title>` tag.      |
| `body_html`      | string | Sanitized main HTML body content.               |
| `recipient_name` | string | Greeting personalization (`Hey {name},`).       |
| `cta_url`        | string | Optional primary call-to-action URL.            |
| `cta_label`      | string | Optional primary call-to-action button label.   |
| `preheader`      | string | Hidden preview text for Gmail / Apple Mail.     |

Override markup without forking by filtering
`extrachill_email_template_path` and returning a different absolute
path for the template ID you want to swap.

### Helpers

- `extrachill_mail_site_id()` / `ec_mail_site_id()` — return the blog ID
  of the closest SMTP-configured site (current site if configured, else
  `ec_get_blog_id('main')`). Short-circuits on a single per-site probe.
  Safe to call from any subsite context.
- `extrachill_site_has_smtp( int $blog_id ): bool` — runtime probe of the
  per-site `easy_wp_smtp` option. Returns true when the site has a
  non-default mailer selected (anything other than PHP's built-in
  `mail`) **and** non-empty credentials for that provider. Result is
  cached for 15 minutes in a network transient
  (`ec_site_has_smtp_{$blog_id}`) and busted automatically when the
  underlying option is added / updated / deleted.
- Ability `extrachill/mail-site-id` exposes the same resolution through
  the Abilities API for WP-CLI / REST / chat tooling.
- Function `extrachill_smtp_configured_sites()` returns the **live**
  list of SMTP-configured site IDs on the network — produced by probing
  every active site via `extrachill_site_has_smtp()`, not a hardcoded
  allowlist. The per-site probe is transient-cached so the network
  scan is cheap on warm cache.
- Filter `extrachill_smtp_configured_sites` is still a valid escape
  hatch — use it to force-add a site whose probe returns false (e.g.
  credentials live in `wp-config.php` constants) or to force-remove a
  site whose credentials are known broken at a higher layer. The
  default value passed to the filter is now the live probed list, not
  a static array.

### Layer dependency

These helpers are thin wrappers around Data Machine's
`datamachine/send-email` and `datamachine/send-email-queued` abilities.
They no-op gracefully (return `[ 'success' => false, 'error' => ... ]`)
when Data Machine is not active, so they are safe to add to consumer
plugins even on installs that ship without DM.

## VPS deployment notes

Reference nginx config for new VPS deployments lives in [`docs/nginx/`](docs/nginx/README.md) — bot/credential-scanner blocking, `/wp-json/` rate limiting, and `/wp-login.php` brute-force rate limiting. Codified after the 2026-05-10 DOS event so a server rebuild can restore the abuse-mitigation layer without re-mining logs.

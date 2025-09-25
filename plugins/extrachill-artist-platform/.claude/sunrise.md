# sunrise.php

When developing for a [WordPress multisite](https://docs.wpvip.com/wordpress-multisite/), it is often necessary to load some custom code very early in the WordPress application’s loading sequence, for example [custom domain mapping functionality](https://docs.wpvip.com/multisites/multiple-domains/).

The WordPress Core drop-in file `sunrise.php` loads _very_ early in the WordPress loading sequence, _before_ `mu-plugins`, any active plugins, and the active theme. Because of this, what can be done in `sunrise.php` is limited, and largely confined to executing pure PHP to set constants that override WordPress Core behavior.

In order to load additional code to `sunrise.php` on VIP, create a `client-sunrise.php` file in a site’s repository in the root [`vip-config` directory](https://docs.wpvip.com/wordpress-skeleton/vip-config-directory/). If a `client-sunrise.php` file exists in the `vip-config` directory, [it will be loaded automatically](https://github.com/Automattic/vip-go-mu-plugins/blob/d1b30bf63279665e96976a5c9cc7531bb855693c/lib/sunrise/sunrise.php#L88-L91).

## Guidelines

Coded added to `client-sunrise.php` should:

-   Be used only when early execution is absolutely required (e.g. for performance reasons or to override aspects of the request very early on). Code that can run later in execution should typically be written in the plugin or theme context and leverage the WordPress Plugin API.
-   Be written with (as much as possible) pure PHP.
-   Not require database access or access to any persistent data, but often makes decisions or performs actions based on intrinsic characteristics of the request, such as the domain or URI. Because it executes on every single request handled by WordPress, make sure that use of `sunrise.php` does not introduce the kinds of performance penalties associated with queries or other expensive operations.
-   Be limited to specific conditions under which the code executes. Code executed within `sunrise.php` can have far-reaching effects, so reducing the probability of unintended side-effects is recommended (e.g. limiting it to only certain URLs or certain domains).

## Configuration for local development

For local development with the [VIP Local Development Environment](https://docs.wpvip.com/vip-local-development-environment/) is being used for local development, `client-sunrise.php` will load automatically if it is present in an application’s codebase.

For all other local development applications, the following steps will be necessary for `client-sunrise.php` to load as expected:

1.  Copy or symlink `wp-content/mu-plugins/lib/sunrise/sunrise.php` to `wp-content/sunrise.php`.
2.  Add `define('SUNRISE', true)` to `wp-config.php`.
3.  Copy or symlink `vip-config/client-sunrise.php` to `ABSPATH` / `vip-config/client-sunrise.php`

Last updated: November 15, 2024
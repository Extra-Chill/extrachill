# Configure multiple domains to resolve to the same network site

Custom code added to `[client-sunrise.php](https://docs.wpvip.com/wordpress-on-vip/multisites/sunrise-php/)` can enable a network site to be accessible from more than one URL, or for a domain to serve a specific part of a site.

By default, each network site on a WordPress multisite environment must have a unique Site Address (URL) value. The value can have [a subdomain or a subdirectory structure](https://docs.wpvip.com/wordpress-on-vip/multisites/subdomains-subdirectories/), and can be updated with [the launch tooling that is built into the Network Sites panel](https://docs.wpvip.com/launch-a-site/begin-a-multisite-launch/) of the VIP Dashboard.

[Redirects](https://docs.wpvip.com/redirects/) enable a request to be forwarded from one domain to another, but more customized domain behavior might be required including:

-   Pointing a domain to a network site that does not match the domain value assigned to the network site’s Site Address (URL).
-   Allowing a network site to be accessible from more than one URL.
-   Serving a specific part of a site (e.g., the home page, the site’s REST API) at a second domain.

Custom code can be added to solve for these scenarios and enable multiple custom domains to resolve to the same network site, either temporarily or permanently. The custom code should be added to `client-sunrise.php` to handle this type of redirect without database lookups.

All [domains must be added](https://docs.wpvip.com/domains/map-a-domain/) to the environment’s VIP Dashboard before they can be used for launching network sites or to be routed to specific network sites via custom code. [A domain must also be verified](https://docs.wpvip.com/domains/verification/) and its [DNS must be pointed to WPVIP](https://docs.wpvip.com/domains/point-dns-to-vip/).

## Point a domain at a non-matching network site

Add custom code to `client-sunrise.php` to enable a domain to point to a network site that does not have a matching domain in the Site Address (URL).

In this example, network site ID 5 has the Site Address (URL) value `https://example.com/site/`. This example code maps the domain **new.example.com** to also point to network site ID 5. This enables requests to **new.example.com** to serve the content for network site ID 5, so a page with the slug `/about/` will resolve at `https://new.example.com/about/`.

client-sunrise.php

```
<?php

$extra_domains = [
	// 'domain' => blog_id
	'new.example.com' => 5
];

if (
	isset( $_SERVER['HTTP_HOST'] )
	&& array_key_exists( $_SERVER['HTTP_HOST'], $extra_domains )
) {
	$mask_domain = $_SERVER['HTTP_HOST'];

	// Set globals
	$blog_id      = $extra_domains[ $mask_domain ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$current_blog = get_site( $blog_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	
	// This should always be 1, unless you are running multiple WordPress networks.
	$current_site = get_network( 1 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	$origin_domain = $current_blog->domain . untrailingslashit( $current_blog->path );

	add_filter( 'home_url', function( $url ) use ( $mask_domain, $origin_domain ) {
		return str_replace( $origin_domain, $mask_domain, $url );
	} );

}
```

## Serve a specific part of a site from multiple domains

Add custom code to `client-sunrise.php` to serve a specific part of a site (e.g., the home page, the site’s REST API) at a second domain. This code example shows a method for serving the WP REST API for the main site (ID 1) from multiple domains: **example.com**, **example.blog**, or **example.go-vip.net**.

client-sunrise.php

```
// Allow REST API requests to be served on one of several domains.
$clientslug_custom_sunrise_domains = array( 'example.com', 'example.blog', 'example.go-vip.net' );

// Cause each of these domains to load `site_id` 1.
$clientslug_custom_sunrise_site_id = 1;

if ( isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] )
	 && in_array( $_SERVER['HTTP_HOST'], $clientslug_custom_sunrise_domains, true )
	 && str_starts_with( $_SERVER['REQUEST_URI'], '/wp-json' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, this is safe here.
) {
	// These domains are each associated with `site_id` 1 of network 1.
	$current_blog = get_site( $clientslug_custom_sunrise_site_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	// This should always be 1, unless you are running multiple WordPress networks.
	$current_site = get_network( 1 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
```

## Errors caused by a non-matching domain

`ms_site_not_found` errors can occur and be observed in an environment’s [Runtime Logs](https://docs.wpvip.com/logs/runtime-logs/) if the DNS of a domain is pointed to a WordPress multisite environment and:

-   No network site on the environment matches the domain being requested.
-   No matching domain is configured in the environment’s `sunrise.php`.

To prevent the error from appearing in Runtime Logs, first identify the domain related to the logged `ms_site_not_found` error(s). Take one of the following actions best suited to resolve the issue:

-   If possible, update the domain’s DNS to no longer point at VIP.
-   [Remove the domain](https://docs.wpvip.com/domains/map-a-domain/#h-remove-a-domain) from the “**Domains & TLS**” panel of an environment’s VIP Dashboard. This will prevent requests for that domain from being directed to that environment.
-   If supported by the DNS provider, update the DNS to redirect to a supported domain.
-   Route the domain to an existing network site on the environment using one of the methods above.
-   Review the settings of any third-party services or scripts that request the non-matching domain and correct outdated domain information.

The `ms_site_not_found` errors sometimes occur for a short time after a site launch. This is normal and expected and will typically resolve on its own.
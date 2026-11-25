=== Extra Chill Partner ===
Contributors: chubes4
Tags: events, venue, tickets, booking
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display your venue's upcoming Extra Chill events as crawlable HTML and MusicEvent JSON-LD, with ticket links to hosted checkout.

== Description ==
For partner websites, not activation on the Extra Chill network. No network plugin dependencies or JavaScript build required.

The plugin sends your API key and requested event limit server-to-server to https://events.extrachill.com/wp-json/extrachill/v1/partner/events. It receives your venue and public events. Event images may load from the supplied image URLs. The optional booking block loads the venue's hosted Extra Chill booking page in an iframe, passing this site's HTTPS origin. Ticket buttons navigate to the API-provided checkout URL. No visitor data is sent by the events API client.

Service information: https://extrachill.com/
Privacy policy: https://extrachill.com/privacy-policy/

== Installation ==
1. Upload and activate extrachill-partner on your venue's WordPress website.
2. Open Settings → Extra Chill and save your partner API key. A successful connection shows your venue's name; the stored key is never displayed.
3. Add the Upcoming Events block or [extrachill_events limit="10"] to a page.
4. Optionally add Booking Embed. The venue must have booking enabled and your exact HTTPS origin allowed by Extra Chill. Standard HTTPS port only.

== Frequently Asked Questions ==

= What happens during an outage? =
Events cache for 15 minutes. The last successful copy remains available during outages, potentially showing older events. Administrators see a small connection notice; visitors see stale events or nothing. Failed requests back off for one minute. The first uncached request can wait up to five seconds.

= Can I customize presentation? =
Override the extrachill-partner-* CSS classes in your theme. The extrachill_partner_api_base filter changes the API base (through /partner, without /events). The extrachill_partner_cache_ttl filter changes the 900-second success TTL. The API key stays server-side in a non-autoloaded option; changing it isolates all cached data from the previous key.

= How do I change the API key? =
Paste a replacement and save. Blank input retains the current key; failed validation keeps the previous key. Deactivate and delete the plugin if no longer using the service.

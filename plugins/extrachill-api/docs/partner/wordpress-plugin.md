# WordPress plugin setup

Display your venue's Extra Chill shows on your WordPress site as crawlable HTML, with structured event data included automatically.

## Get your venue key

For now, ask the Extra Chill team. A venue owner issues the key; the team runs:

```sh
wp extrachill venues partner-key issue <venue>
```

One key connects one venue, so there is no venue picker. Ask the team to rotate or revoke a key if it is exposed or you change website providers. Treat it as a secret: do not put it in a page, browser script, screenshot, or public repository.

## Install and connect

1. Obtain the Extra Chill partner plugin ZIP from the Extra Chill team.
2. On your own site (WordPress 6.5+ and PHP 7.4+), open **Plugins → Add New Plugin → Upload Plugin**. Upload the ZIP, install it, and activate it.
3. Open **Settings → Extra Chill**, paste the key, and save. The settings validate the key and show the connected venue name. The saved key is not displayed in full.

This is a standalone plugin for partner sites; it does not require the Extra Chill network plugins.

## Add upcoming events

Edit the page where you want your schedule and insert the **Upcoming events** block (`extrachill-partner/upcoming-events`). Or add a Shortcode block containing:

```text
[extrachill_events limit="10"]
```

Use a limit from 1 to 50. Publish the page and check it while signed out. The block and shortcode share the same server renderer: visitors and crawlers receive event HTML and one JSON-LD script per event, even if you place the block twice. Ticket buttons use the supplied Extra Chill links. A successful response with no events shows a no-upcoming-shows state.

## Optional booking embed

Add the booking embed block (`extrachill-partner/booking-embed`) if you want booking enquiries on the page. It appears only when the venue has a booking embed URL; otherwise the editor shows a notice and visitors see nothing.

Ask the Extra Chill team to add your site's HTTPS origin (for example, `https://www.example.com`) to the venue's booking embed allowlist. The plugin supplies that origin as `parent-origin` when rendering the iframe. Booking is the only iframe; upcoming events are never iframed.

## Caching and outages

The plugin caches responses for **15 minutes** by default (filterable by your developer). This local plugin cache is distinct from the API's five-minute cache guidance for custom builds. Changes to a show may take a cache cycle to appear.

If the API is unavailable, visitors see the last-known-good event list when one exists, or no event section if there is no saved list. They do not see API errors or key details. Logged-in administrators see a small notice, including for an invalid key (401). Check Settings → Extra Chill and contact the team if the connection cannot be restored.

For a non-WordPress site, use the [custom-build guide](custom-builds.md).

# Partner Websites

Your shows, on your own site, listed once on Extra Chill. Publish your venue's events on Extra Chill and display that same upcoming schedule on your website without maintaining a second calendar.

## Choose your setup

- **WordPress:** [install the partner plugin](wordpress-plugin.md), paste your venue key, and add the Upcoming events block.
- **Custom website:** use the [partner API](api.md) from your server. The [custom-build guide](custom-builds.md) includes a Next.js App Router example and a checklist for any server language.

Building a public app or directory rather than a venue website? Use the [public calendar feed](public-calendar-feed.md), which does not require a key.

## Your page gets the search credit

Events are server-rendered as real HTML on your page, with each event's Schema.org JSON-LD. They are never iframed or dependent on a browser-only fetch. Crawlers such as Google and CitySpark can read the shows directly from your venue's page: your page gets the search credit, rather than an embedded calendar page.

Ticket and booking actions stay hosted by Extra Chill. Buy links use the supplied ticket URL, leading to Extra Chill checkout or a first-party redirect to an outside ticket seller. The optional booking embed is the **only iframe surface**; it is not an event calendar.

Each key connects one venue. Ask the Extra Chill team for a key and keep it on your server, never in browser code. See [authentication and key management](api.md).

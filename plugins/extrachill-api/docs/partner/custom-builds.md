# Custom website builds

Fetch events on your server and render them into the initial page HTML alongside their JSON-LD. This lets Google and CitySpark read the venue's own page without running a browser-only integration. Do not iframe the events.

Read the [API reference](api.md) for every response field, errors, and booking embed rules.

## Next.js App Router

Set `EXTRACHILL_PARTNER_KEY` in your hosting provider's server environment. For local development, use an uncommitted `.env.local`. Never prefix the variable with `NEXT_PUBLIC_`.

This minimal `app/events/page.jsx` is a **server component**: do not add `"use client"`. Fetch results are cached server-side with `revalidate: 300`. Use this on a single-venue deployment; a multi-tenant service must isolate caches by venue key and request arguments.

```jsx
import 'server-only';

const endpoint =
  'https://events.extrachill.com/wp-json/extrachill/v1/partner/events?limit=20';

async function getEvents() {
  const key = process.env.EXTRACHILL_PARTNER_KEY;
  if (!key) throw new Error('Missing server-side partner configuration');

  const response = await fetch(endpoint, {
    headers: { 'X-Extrachill-Partner-Key': key },
    next: { revalidate: 300 },
  });
  if (!response.ok) {
    // Keep diagnostics server-side; never log the key.
    throw new Error(`Partner API returned ${response.status}`);
  }
  return response.json();
}

function jsonLd(schema) {
  // Remote strings may contain </script>; do not let them close this tag.
  return JSON.stringify(schema).replace(/</g, '\\u003c');
}

export default async function EventsPage() {
  let data;
  try {
    data = await getEvents();
  } catch {
    // Do not present an outage as "no upcoming shows".
    return <main><p>The event list is temporarily unavailable.</p></main>;
  }

  return (
    <main>
      <h1>Upcoming events at {data.venue.name}</h1>
      {data.events.length === 0 && <p>No upcoming shows.</p>}
      <ul>
        {data.events.map((event) => (
          <li key={event.id}>
            <h2><a href={event.url}>{event.title}</a></h2>
            <time dateTime={event.start}>
              {new Intl.DateTimeFormat('en-US', {
                timeZone: event.timezone,
                dateStyle: 'medium',
                timeStyle: 'short',
              }).format(new Date(event.start))}
            </time>
            {event.performers.length > 0 && (
              <p>{event.performers.join(', ')}</p>
            )}
            {event.price && <p>{event.price}</p>}
            {event.excerpt && <p>{event.excerpt}</p>}
            {event.image && (
              <img src={event.image} alt="" loading="lazy" />
            )}
            {event.ticket.url && (
              <a href={event.ticket.url} rel="noopener">
                {event.ticket.label}
              </a>
            )}
            <script
              type="application/ld+json"
              dangerouslySetInnerHTML={{ __html: jsonLd(event.schema) }}
            />
          </li>
        ))}
      </ul>
    </main>
  );
}
```

React escapes the visible text; the JSON-LD script uses `JSON.stringify` plus `<` escaping as `\u003c` so remote content cannot break out of the script tag. Do not put the API key or raw API response into a client component. The page renders each returned event once, including its complete `event.schema` object.

The example uses the current five-minute API TTL. If `cache_ttl` or `Cache-Control` changes, adjust your cache policy accordingly. For production, validate the response shape and HTTP(S) URLs, add monitoring without credentials, and consider a per-venue last-known-good server cache for outages. Do not cache a failed response as fresh data or mix keys in a shared public response cache.

## Any server language checklist

- Keep the partner key in server-only configuration and send it in `X-Extrachill-Partner-Key` over HTTPS.
- Request a `limit` between 1 and 50; check HTTP status before decoding success data.
- Respect `cache_ttl` and `Cache-Control: private, max-age=300`, isolating caches by key and arguments.
- Render semantic event HTML on the server, including `<time datetime="…">`, in the venue's `timezone`. Escape text and attributes; treat all API data as untrusted.
- Render each event's complete `schema` as its own `<script type="application/ld+json">` once per page. JSON-encode it and escape `<` as `\u003c`; do not interpolate raw strings into script markup.
- Use `ticket.url` **as-is** for buy links, and `ticket.label` for their text. Omit a button when its URL is null.
- Distinguish a valid empty list from an outage; use a safe fallback or a last-known-good copy, without exposing keys or raw errors.
- If adding booking, use only the optional booking iframe and configure the HTTPS origin allowlist as described in the [API reference](api.md). Events are never iframed.

## Validate the published page

View the page source (not just the browser's live DOM): event text and JSON-LD must be present in the initial HTML. Test the public page URL with [Google's Rich Results Test](https://search.google.com/test/rich-results), resolve reported structured-data problems, and verify ticket links still route through Extra Chill. Crawlable HTML and valid markup make the page readable to crawlers; they do not guarantee indexing or placement.

# Event indexing retention

Single `data_machine_events` pages remain accessible, but receive `noindex, follow` once they ended more than 180 days ago. Core event XML sitemap URL queries and page-count queries exclude the same events in SQL. Missing date rows remain indexable.

Override the window with `extrachill_seo_event_retention_days` (nonnegative integer days). For example:

```php
add_filter( 'extrachill_seo_event_retention_days', static fn() => 90 );
```

The cutoff uses the site's local wall time, matching the owning plugin's event dates table. Equality at the cutoff remains indexable.

The policy uses the Data Machine Events date API (`EventDatesTable::get()` and `UpcomingFilter::past_where()`); there is no single-event ended-before ability. Both policies follow its past-event definition: start is before the cutoff and end is either absent or before the cutoff. No postmeta is read and no dates are duplicated. Sitemap exclusion uses a correlated `NOT EXISTS` lookup on the dates table's `post_id` primary key, without collecting IDs in PHP. Queries outside core event sitemaps are unchanged.

Data Machine Events' `prevent_sitemap_404()` remains responsible for allowing core sitemap rendering; core can still return its normal 404 for pages beyond the reduced sitemap page count.

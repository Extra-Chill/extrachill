(function () {
  const config = window.ecCalendarAnalytics;
  if (!config?.endpoint) {
    return;
  }

  const triggers = new Set([
    "search",
    "date_range",
    "scope",
    "taxonomy_filter",
    "clear",
    "pagination",
    "geo",
    "initial",
  ]);
  const searchTimers = new WeakMap();

  function send(eventData) {
    const data = JSON.stringify({
      input: {
        event_data: eventData,
        source_url: window.location.origin + window.location.pathname,
      },
    });
    if (
      navigator.sendBeacon?.(
        config.endpoint,
        new Blob([data], { type: "application/json" }),
      )
    ) {
      return;
    }
    fetch(config.endpoint, {
      method: "POST",
      body: data,
      headers: { "Content-Type": "application/json" },
      keepalive: true,
    }).catch(() => {});
  }

  function trackQuery(event) {
    const { trigger, query, archive, result_count: count } = event.detail || {};
    if (
      !triggers.has(trigger) ||
      !query ||
      typeof query !== "object" ||
      Array.isArray(query) ||
      !Number.isSafeInteger(count) ||
      count < 0
    ) {
      return;
    }

    const data = {
      trigger,
      scope: typeof query.scope === "string" ? query.scope : "",
      has_date_range: Boolean(query.date_start || query.date_end),
      result_count: count,
      route: config.route || "other",
    };
    const term = query.search_term ?? query.event_search;
    if (query.has_search !== false && typeof term === "string" && term) {
      data.search_term = Array.from(term).slice(0, 1024).join("");
    }
    if (query.tax_filter && typeof query.tax_filter === "object") {
      data.taxonomies = Object.fromEntries(
        Object.entries(query.tax_filter)
          .filter(
            ([taxonomy, ids]) =>
              /^[a-z0-9_-]{1,32}$/.test(taxonomy) && Array.isArray(ids),
          )
          .slice(0, 8)
          .map(([taxonomy, ids]) => [
            taxonomy,
            ids
              .map(Number)
              .filter((id) => Number.isSafeInteger(id) && id > 0)
              .slice(0, 25),
          ]),
      );
    }
    const archiveId = Number(archive?.term_id);
    if (
      typeof archive?.taxonomy === "string" &&
      Number.isSafeInteger(archiveId) &&
      archiveId > 0
    ) {
      data.archive_taxonomy = archive.taxonomy;
      data.archive_term = archiveId;
    }

    if (trigger !== "search") {
      send(data);
      return;
    }
    // Each calendar debounces independently; other controls send immediately.
    const calendar = event.target;
    clearTimeout(searchTimers.get(calendar));
    searchTimers.set(
      calendar,
      setTimeout(() => {
        searchTimers.delete(calendar);
        send(data);
      }, 500),
    );
  }

  // Data Machine #4076 may not be installed yet. Legacy detail-less events
  // are ignored, including when upstream reuses the content-updated signal.
  document.addEventListener("data-machine-calendar-query-changed", trackQuery);
  document.addEventListener(
    "data-machine-calendar-content-updated",
    trackQuery,
    true,
  );
})();

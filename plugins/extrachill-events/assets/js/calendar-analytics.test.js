/* global afterEach, beforeEach, expect, jest, test */

/**
 * External dependencies
 */
import fs from "fs";
import path from "path";

const script = fs.readFileSync(
  path.join(__dirname, "calendar-analytics.js"),
  "utf8",
);
let frame;
let calendar;
let fetchMock;

function dispatch(
  detail,
  target = calendar,
  name = "data-machine-calendar-query-changed",
) {
  target.dispatchEvent(new frame.CustomEvent(name, { bubbles: true, detail }));
}

function input(index = 0) {
  return JSON.parse(fetchMock.mock.calls[index][1].body).input;
}

beforeEach(() => {
  jest.useFakeTimers();
  const iframe = document.createElement("iframe");
  document.body.appendChild(iframe);
  frame = iframe.contentWindow;
  frame.setTimeout = setTimeout;
  frame.clearTimeout = clearTimeout;
  frame.ecCalendarAnalytics = {
    endpoint: "/track-calendar-query/run",
    route: "archive",
  };
  fetchMock = jest.fn().mockResolvedValue({});
  frame.fetch = fetchMock;
  calendar = frame.document.createElement("div");
  frame.document.body.appendChild(calendar);
  frame.eval(script);
});

afterEach(() => {
  jest.useRealTimers();
  document.body.innerHTML = "";
});

test.each([
  "search",
  "date_range",
  "scope",
  "taxonomy_filter",
  "clear",
  "pagination",
  "geo",
  "initial",
])("maps %s and records only supported dimensions", (trigger) => {
  dispatch({
    trigger,
    query: {
      scope: "tonight",
      date_start: "2026-06-15",
      has_search: true,
      event_search: "Jazz",
      tax_filter: { location: [7, "9"] },
      paged: 2,
      lat: 12,
    },
    archive: { taxonomy: "location", term_id: 3 },
    result_count: 0,
  });
  jest.runOnlyPendingTimers();
  expect(fetchMock).toHaveBeenCalledTimes(1);
  expect(fetchMock.mock.calls[0][0]).toBe("/track-calendar-query/run");
  expect(input()).toEqual({
    source_url: frame.location.origin + frame.location.pathname,
    event_data: {
      trigger,
      scope: "tonight",
      has_date_range: true,
      search_term: "Jazz",
      taxonomies: { location: [7, 9] },
      archive_taxonomy: "location",
      archive_term: 3,
      result_count: 0,
      route: "archive",
    },
  });
});

test("debounces search per calendar without delaying other triggers", () => {
  function search(term, target) {
    dispatch(
      {
        trigger: "search",
        query: { search_term: term },
        result_count: 1,
      },
      target,
    );
  }
  const other = frame.document.body.appendChild(
    frame.document.createElement("div"),
  );
  search("ja");
  search("rock", other);
  jest.advanceTimersByTime(300);
  search("jazz");
  jest.advanceTimersByTime(199);
  expect(fetchMock).not.toHaveBeenCalled();
  dispatch({ trigger: "scope", query: {}, result_count: 3 });
  expect(input().event_data.trigger).toBe("scope");
  jest.advanceTimersByTime(1);
  expect(input(1).event_data.search_term).toBe("rock");
  jest.advanceTimersByTime(300);
  expect(input(2).event_data.search_term).toBe("jazz");
  expect(fetchMock).toHaveBeenCalledTimes(3);
});

test("accepts query detail on a non-bubbling content-updated event", () => {
  calendar.dispatchEvent(
    new frame.CustomEvent("data-machine-calendar-content-updated", {
      detail: { trigger: "initial", query: {}, result_count: 12 },
    }),
  );
  expect(input().event_data).toMatchObject({
    trigger: "initial",
    result_count: 12,
    has_date_range: false,
  });
});

test("ignores absent events, absent detail, and incomplete events from legacy calendars", () => {
  jest.runOnlyPendingTimers();
  expect(fetchMock).not.toHaveBeenCalled();
  calendar.dispatchEvent(
    new frame.Event("data-machine-calendar-query-changed", {
      bubbles: true,
    }),
  );
  calendar.dispatchEvent(
    new frame.Event("data-machine-calendar-content-updated"),
  );
  dispatch();
  dispatch({});
  dispatch({ trigger: "initial", query: {} });
  jest.runOnlyPendingTimers();
  expect(fetchMock).not.toHaveBeenCalled();
});

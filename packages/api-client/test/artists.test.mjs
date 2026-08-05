import assert from 'node:assert/strict';
import test from 'node:test';

import { ArtistsResource } from '../dist/index.mjs';

function analyticsPath(options) {
  let request;
  const resource = new ArtistsResource({
    request(value) {
      request = value;
      return Promise.resolve({});
    },
  });

  if (options === undefined) {
    resource.getAnalytics(42);
  } else {
    resource.getAnalytics(42, options);
  }

  return request.path;
}

test('preserves numeric date-range requests', () => {
  assert.equal(analyticsPath(90), 'extrachill/v1/artists/42/analytics?date_range=90');
  assert.equal(analyticsPath(), 'extrachill/v1/artists/42/analytics?date_range=30');
});

test('serializes exact date pairs', () => {
  assert.equal(
    analyticsPath({ start_date: '2026-07-01', end_date: '2026-07-31' }),
    'extrachill/v1/artists/42/analytics?start_date=2026-07-01&end_date=2026-07-31'
  );
});

test('accepts the legacy range in an options object', () => {
  assert.equal(
    analyticsPath({ date_range: 14 }),
    'extrachill/v1/artists/42/analytics?date_range=14'
  );
});

test('omits undefined options', () => {
  assert.equal(
    analyticsPath({ date_range: undefined, start_date: '2026-07-01', end_date: undefined }),
    'extrachill/v1/artists/42/analytics?start_date=2026-07-01'
  );
  assert.equal(analyticsPath({}), 'extrachill/v1/artists/42/analytics');
});

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

function rosterInviteRequest(invitee) {
  let request;
  const resource = new ArtistsResource({
    request(value) {
      request = value;
      return Promise.resolve({});
    },
  });

  resource.inviteRosterMember(42, invitee);

  return request;
}

test('invites a typed email address', () => {
  const request = rosterInviteRequest('typed@example.com');
  assert.equal(request.path, 'extrachill/v1/artists/42/roster');
  assert.deepEqual(request.body, { email: 'typed@example.com' });
});

test('invites a picked member by user ID without an email', () => {
  const request = rosterInviteRequest({ user_id: 7 });
  assert.equal(request.path, 'extrachill/v1/artists/42/roster');
  assert.deepEqual(request.body, { user_id: 7 });
});

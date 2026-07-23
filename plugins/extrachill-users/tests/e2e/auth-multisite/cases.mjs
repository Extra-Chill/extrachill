import { createHash } from 'node:crypto';

export function buildCasePlan(seed = 'extrachill-auth-fuzz') {
  const normalizedSeed = String(seed || 'extrachill-auth-fuzz');
  const suffix = createHash('sha256').update(normalizedSeed).digest('hex').slice(0, 12);

  return {
    schema: 'extrachill-users/auth-fuzz-plan/v1',
    seed: normalizedSeed,
    replay: `AUTH_FUZZ_SEED=${normalizedSeed}`,
    generated_email: `rest-${suffix}@example.test`,
    browser_email: 'browser-auth-fuzz@example.test',
    browser_username: 'traveler_auth_fuzz',
    invalid_registrations: [
      { id: 'invalid-email', email: 'not-an-email', password: 'valid-pass-248', password_confirm: 'valid-pass-248', device_id: uuid(suffix, 1) },
      { id: 'password-mismatch', email: `mismatch-${suffix}@example.test`, password: 'valid-pass-248', password_confirm: 'different-pass-248', device_id: uuid(suffix, 2) },
      { id: 'short-password', email: `short-${suffix}@example.test`, password: 'short', password_confirm: 'short', device_id: uuid(suffix, 3) },
      { id: 'invalid-device', email: `device-${suffix}@example.test`, password: 'valid-pass-248', password_confirm: 'valid-pass-248', device_id: 'not-a-uuid' },
      { id: 'array-email', email: ['array@example.test'], password: 'valid-pass-248', password_confirm: 'valid-pass-248', device_id: uuid(suffix, 4) },
      { id: 'null-password', email: `null-${suffix}@example.test`, password: null, password_confirm: null, device_id: uuid(suffix, 5) },
      { id: 'oversized-email', email: `${'a'.repeat(320)}@example.test`, password: 'valid-pass-248', password_confirm: 'valid-pass-248', device_id: uuid(suffix, 6) },
    ],
    redirect_cases: [
      'https://outside.example/escape',
      '//outside.example/escape',
      'http://localhost/community/',
      'javascript:alert(1)',
      'https://extrachill.com.outside.example/escape',
      'https://outside.example/?next=https://extrachill.com/',
      'https://extrachill.com@outside.example/escape',
    ],
    handoff_redirect_cases: [
      'https://outside.example/escape',
      'https://extrachill.com.outside.example/escape',
      'https://extrachill.link/escape',
      '//community.extrachill.com/relative',
      'javascript:alert(1)',
    ],
    invalid_onboarding_usernames: ['ad', 'admin', 'auth_fuzz_existing', '!!!', 'x'.repeat(61)],
  };
}

function uuid(suffix, index) {
  const tail = `${suffix}${index}`.padEnd(12, '0').slice(0, 12);
  return `00000000-0000-4000-8000-${tail}`;
}

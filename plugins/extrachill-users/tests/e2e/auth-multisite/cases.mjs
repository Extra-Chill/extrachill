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
    ],
    redirect_cases: [
      'https://outside.example/escape',
      '//outside.example/escape',
      'http://localhost/community/',
      'javascript:alert(1)',
    ],
  };
}

function uuid(suffix, index) {
  const tail = `${suffix}${index}`.padEnd(12, '0').slice(0, 12);
  return `00000000-0000-4000-8000-${tail}`;
}

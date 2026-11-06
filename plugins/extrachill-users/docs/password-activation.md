# Password registration activation

Password REST registration now returns `status: pending_activation` and a check-email message, not user/session/token fields. Form registration queues the same core multisite signup. Google and Apple flows are unchanged.

Signup meta holds a WordPress password hash and registration/device context, never the plaintext password. Duplicate pending requests preserve the original password, consent and invitation context; submitting registration again resends after a one-minute cooldown. Links expire after 24 hours. Registering after expiry replaces the signup and invalidates the previous key.

The branded `/login/?ec_activate=...` page requires a confirmation POST so mail scanners cannot consume links. Activation uses `wpmu_activate_signup()` under an email-scoped database advisory lock shared with signup/resend. Core's public activation endpoint is closed to prevent expiry bypass. Activation provisions community membership and canonical registration effects. The user then signs in normally on their device; activation deliberately does not create any cookies or tokens. Core's generated-password welcome email is suppressed because the chosen password is restored instead.

## Validation and remaining work

Added `tests/unit/RegistrationActivationTest.php` for pending storage/session boundaries, duplicate/resend immutability, notification links, expiry/replacement, activation and replay. PHP syntax and project PHPCS checks pass. The local Homeboy PHPUnit attempt failed before execution: WP Codebox emitted an empty recipe execution plan (`recipe_run_no_executions`, zero tests). These tests are not yet runtime-verified.

This is a partial delivery of issue #903. Existing registration/invitation/consent tests and downstream native clients still need migration to the pending response contract and regression execution. Provisioning failure recovery (including failed membership/password writes and invitation outcome reporting), notification delivery failure reporting, and concurrent activation fault-injection coverage need hardening before release. Database advisory locks require a MySQL-compatible deployment; acquisition fails closed. No version or changelog changes are included.

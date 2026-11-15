# fix(users): route team writers to Studio instead of wp-admin

Closes Extra-Chill/extrachill#1505.

## Changes
- Gate wp-admin and the admin bar to local administrators and team editorial reviewers.
- Resolve Studio through the network blog map, including after default/admin login redirects; preserve explicit front-end continuations.
- Keep AJAX, admin-post.php, REST and front-end requests outside the redirect gate.
- Remove the unused access_admin_bar role capability; the existing init role reconciliation removes it from persisted roles.

## Editor detection
Reuse ec_studio_review_queue_user_can_review_main() for the current user when Studio loads it. Studio is active only on its subsite, so elsewhere use the same edit_others_posts capability in the mapped main-site context. For login, construct a fresh WP_User in main's context so the supplied user's cached subsite capabilities cannot accidentally grant or deny access. Always restore the previous blog. Team membership alone and subsite-only editorial capability do not grant access. Administrators retain their manage_options exception.

## Verification
- PASS: php tests/admin-access-control-smoke.php. Exercises writers, main-site editors, administrators, subsite-only editors and ordinary users across five sites; admin redirects/bar, login user context, mapped Studio destination, front-end continuations, real Studio helper reuse, request exemptions and preserved team capabilities.
- PASS: PHP syntax checks and git diff --check.
- Updated WordPress TeamRoleTest to assert access_admin_bar is absent.
- homeboy review test extrachill-users did not finish within the 60-second command timeout; full WordPress suite results are not available.
- Grep verified Studio render/compose REST still use team membership and compose uploads use upload_files; Events TeamAuthorization and SubmissionTrustPolicy use access_events_admin; API reports use access_studio. None of those capabilities or permission callbacks changed. Repository-wide access_admin_bar search found no runtime consumer outside the retired role entry (test/journey fixtures also mention it).

## Publication
The checkout's origin push URL is homeboy-blocked://agent-task-attempt-may-not-push. This PR body is staged for publication by the controller; the agent did not bypass the push restriction.

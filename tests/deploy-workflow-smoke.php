<?php
/**
 * Shape checks for the push-based network deploy workflow and its checked-in
 * Homeboy config. Line-based on purpose: no ext-yaml dependency.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

$root     = dirname( __DIR__ );
$workflow = $root . '/.github/workflows/deploy.yml';
$project  = $root . '/deploy/homeboy/projects/extrachill-site/extrachill-site.json';
$server   = $root . '/deploy/homeboy/servers/hetzner.json';
$failures = array();

function dws_assert( bool $ok, string $label ): void {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

dws_assert( is_file( $workflow ), 'deploy.yml exists' );
$yaml  = (string) file_get_contents( $workflow );
$lines = preg_split( '/\R/', $yaml ) ?: array();

dws_assert( (bool) preg_match( '/^\s+repository_dispatch:\s*$/m', $yaml ), 'repository_dispatch trigger present' );
dws_assert( (bool) preg_match( '/^\s+types:\s*\[component-released\]/m', $yaml ), 'repository_dispatch listens for component-released' );
dws_assert( (bool) preg_match( '/^\s+workflow_dispatch:\s*$/m', $yaml ), 'workflow_dispatch trigger present' );
dws_assert( (bool) preg_match( '/^\s+schedule:\s*$/m', $yaml ), 'schedule trigger present' );
dws_assert( (bool) preg_match( '/^concurrency:\s*\n\s+group:\s*deploy-extrachill-site\s*\n\s+cancel-in-progress:\s*false/m', $yaml ), 'deploys queue under one concurrency group' );

// permissions block contains only contents: read.
preg_match( '/^permissions:\s*\n((?:\s{2,}\S.*\n)+)/m', $yaml, $perm );
$perm_lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $perm[1] ?? '' ) ?: array() ) ) );
dws_assert( $perm_lines === array( 'contents: read' ), 'permissions is exactly contents: read' );

$uses_with_expr = array_filter( $lines, static fn( $l ) => preg_match( '/^\s*-?\s*uses:.*\$\{\{/', $l ) === 1 );
dws_assert( empty( $uses_with_expr ), 'no uses: line contains an expression' );

// Every homeboy-action step carries the SSH inputs.
$action_steps = preg_split( '/(?=^\s*-\s*name:)/m', $yaml ) ?: array();
$action_steps = array_filter( $action_steps, static fn( $s ) => preg_match( '/^\s*uses: Extra-Chill\/homeboy-action@v2\s*$/m', $s ) === 1 );
$missing_ssh  = array_filter( $action_steps, static fn( $s ) => ! str_contains( $s, 'ssh-key: ${{ secrets.EXTRACHILL_DEPLOY_SSH_KEY }}' ) || ! str_contains( $s, 'ssh-known-hosts: ${{ secrets.EXTRACHILL_DEPLOY_KNOWN_HOSTS }}' ) );
dws_assert( count( $action_steps ) >= 2, 'at least deploy and verify homeboy-action steps' );
dws_assert( empty( $missing_ssh ), 'every homeboy-action step passes ssh-key and ssh-known-hosts' );
// Checkout-less resolution reads release metadata from each component's own
// repository; with no github.com token Homeboy resolves zero components and
// the run fails closed (homeboy#14813).
$missing_token = array_filter( $action_steps, static fn( $s ) => ! str_contains( $s, 'GH_TOKEN: ${{ github.token }}' ) );
dws_assert( empty( $missing_token ), 'every homeboy-action step exports a github.com token for checkout-less resolution' );

dws_assert( str_contains( $yaml, 'HOMEBOY_CONFIG_ROOT: ${{ github.workspace }}/deploy/homeboy' ), 'HOMEBOY_CONFIG_ROOT points at the checked-in config (homeboy#14783)' );
// runner.* and github.workspace are job-scoped contexts; a workflow-level env
// referencing them makes GitHub reject the whole file (zero-job failed run).
preg_match( '/^env:\s*\n((?:\s{2,}\S.*\n)+)/m', $yaml, $top_env );
dws_assert( ! preg_match( '/\$\{\{\s*(runner\.|github\.workspace)/', $top_env[1] ?? '' ), 'workflow-level env does not use job-scoped contexts (runner.*, github.workspace)' );
// runner.* is step-scoped only: not valid in workflow- or job-level env.
preg_match( '/^    env:\s*\n((?:\s{6,}\S.*\n)+)/m', $yaml, $job_env );
dws_assert( ! preg_match( '/\$\{\{\s*runner\./', $job_env[1] ?? '' ), 'job-level env does not use the step-scoped runner context' );
$clone_steps = array_filter( $lines, static fn( $l ) => preg_match( '/^\s*repository:\s*\$\{\{/', $l ) === 1 );
dws_assert( empty( $clone_steps ), 'workflow never clones a component (checkout-less deploy, homeboy#14782)' );
dws_assert( str_contains( $yaml, 'deploy extrachill-site --outdated' ), 'scheduled --outdated poll is enabled' );
// homeboy#14813: an all-skipped poll reports "No outdated components found",
// which reads like success. A silent no-op loop is worse than a loud failure.
dws_assert( str_contains( $yaml, 'Reject a poll that resolved nothing' ), 'an all-skipped poll is rejected rather than reported as up to date' );
dws_assert( str_contains( $yaml, 'homeboy#14813' ), 'the guard cites the upstream issue it compensates for' );
// Unattended runs must be gated behind an explicit repository variable so the
// schedule cannot start mutating the server the moment it is merged.
dws_assert( str_contains( $yaml, 'AUTOMATION: ${{ vars.DEPLOY_AUTOMATION }}' ), 'unattended deploys are gated on the DEPLOY_AUTOMATION repository variable' );
dws_assert( str_contains( $yaml, 'if [ "${AUTOMATION:-}" != "enabled" ]' ), 'anything other than "enabled" keeps unattended runs in plan-only mode' );
dws_assert( (bool) preg_match( '/workflow_dispatch\)\s*\n\s+component="\$\{M_COMPONENT\}"; version="\$\{M_VERSION\}"; dry_run="\$\{M_DRY_RUN\}"/', $yaml ), 'manual dispatch is never gated by DEPLOY_AUTOMATION' );
dws_assert( (bool) preg_match( '/name:\s*deploy-evidence-\$\{\{ github\.run_id \}\}/', $yaml ), 'evidence artifact uploaded' );
// The poller runs 48x/day and ~46 find nothing. Reporting every run would
// make the channel unreadable (homeboy#14833), so a healthy no-op must stay
// silent while real deploys and failures always report.
dws_assert( str_contains( $yaml, 'Report to Discord' ), 'deploy outcomes are reported to Discord' );
dws_assert( str_contains( $yaml, 'Healthy no-op; staying quiet.' ), 'a healthy no-op does not notify' );
// A red job is not always a failed deploy. Reporting a CI-plumbing failure as
// "deploy failed" sends people to inspect the server instead of the workflow,
// which is what happened throughout homeboy-action#483.
dws_assert( str_contains( $yaml, 'Deploy OK, workflow failed' ), 'a workflow failure after successful commands is reported distinctly' );
// A skipped component never deployed. Leaving that out of the report is the
// same class of defect as a poll that never runs: absence indistinguishable
// from health (#244).
dws_assert( str_contains( $yaml, 'deploy config needs fixing' ), 'skipped components are named in the notification' );
dws_assert( str_contains( $yaml, 'Nothing deployed - ${skipped} component(s) misconfigured' ), 'a run that only skipped is reported, not silenced' );
dws_assert(
	str_contains( $yaml, '[ "${skipped}" -eq 0 ]' ),
	'the silent no-op path requires zero skips as well as zero deploys'
);
dws_assert( str_contains( $yaml, 'missing extension|invalid_argument|local_path' ), 'structural skips are detected by reason' );
dws_assert(
	str_contains( $yaml, 'Deploy configuration is incomplete for these components' ),
	'a structural skip fails the run rather than warning inside a green one'
);
dws_assert( str_contains( $yaml, 'sort -u' ), 'skips reported by both invocations are deduplicated' );
// GitHub documents scheduled workflows as best-effort and names the start of
// every hour as a high-load period. Asking for :00 and :30 lost ~91% of slots
// over three days (#243), so the schedule must stay off those boundaries.
dws_assert(
	! str_contains( $yaml, "cron: '*/30" ) && ! str_contains( $yaml, 'cron: "*/30' ),
	'the schedule does not fire on the :00 and :30 peak boundaries'
);
dws_assert(
	(bool) preg_match( '/cron:\s*[\'"]\s*\d+(,\d+)*\s+\*/', $yaml ),
	'the schedule pins explicit off-peak minutes rather than a */N interval'
);
// A component that was not attempted is a configuration fault, not a property
// of the component. Wording that blames the component sends the reader to the
// wrong repository.
dws_assert(
	! str_contains( $yaml, 'cannot deploy at all' ) && ! str_contains( $yaml, 'component(s) cannot deploy' ),
	'reports do not describe components as inherently undeployable'
);
// A red run caused by undeployable components must not be described as a CI
// plumbing failure. The amber "investigate the run, not the deploy" wording is
// correct only when nothing was skipped.
dws_assert(
	str_contains( $yaml, '${skipped} component(s) misconfigured for deploy' ),
	'a run failed by structural skips names that as the cause'
);
dws_assert(
	str_contains( $yaml, '[ "${JOB_STATUS}" != "success" ] && [ "${skipped}" -gt 0 ]' ),
	'the skip-caused failure branch is evaluated before the generic workflow-failure branch'
);
dws_assert(
	str_contains( $yaml, 'no component was skipped; a later workflow step failed' ),
	'the CI-plumbing message states that it only applies when nothing was skipped'
);
dws_assert( str_contains( $yaml, 'commands_ok=false' ), 'the notification reads Homeboy own per-command success, not just job status' );
dws_assert( str_contains( $yaml, 'secrets.DISCORD_DEPLOY_WEBHOOK' ), 'the webhook comes from a secret, never a literal' );
dws_assert( ! preg_match( '#discord\.com/api/webhooks/[0-9]#', $yaml ), 'no webhook URL is hardcoded in the workflow' );
// Network rig pre-deploy gate (#264). Assert the real gate, not a comment
// marker: the placeholder this used to check was replaced by a working step,
// and the marker number moved with it (#288).
dws_assert( str_contains( $yaml, '- name: Network rig gate' ), 'the network rig gate step exists' );
dws_assert( str_contains( $yaml, 'homeboy rig up extrachill-network' ), 'the gate boots the extrachill-network rig' );
dws_assert( str_contains( $yaml, 'skip_network_gate' ), 'the gate has a logged emergency bypass input' );
// Regression: resolution must never be able to deploy. It previously appended
// --dry-run only when dry_run != "true", so a manual dry-run dispatch resolved
// with a real deploy (#271).
dws_assert( str_contains( $yaml, 'resolve_command="${command} --dry-run"' ), 'gate resolution is unconditionally a dry run' );
// Regression: dry-run resolve results are reported as "planned", never
// "deployed". Counting only "deployed" made the gate skip every run (#272).
dws_assert( str_contains( $yaml, 'select(.status == "planned" or .status == "deployed")' ), 'the gate counts planned resolve results' );

// Checked-in Homeboy config.
dws_assert( is_file( $project ), 'project config exists' );
dws_assert( is_file( $server ), 'server config exists' );
$p = json_decode( (string) file_get_contents( $project ), true );
$s = json_decode( (string) file_get_contents( $server ), true );
dws_assert( is_array( $p ) && is_array( $s ), 'project and server configs parse as JSON' );
dws_assert( ( $p['server_id'] ?? null ) === 'hetzner', 'project targets server hetzner' );
dws_assert( ( $p['base_path'] ?? null ) === '/var/www/extrachill.com', 'project base_path is the site root' );
$attachments = $p['components'] ?? null;
dws_assert( is_array( $attachments ) && count( $attachments ) >= 30, 'project attaches the deployable component set' );
// Only components whose repositories cut GitHub Releases can be polled.
$not_releasing = array_filter( (array) $attachments, static fn( $c ) => in_array( $c['id'], array( 'chubes-gallery-lightbox' ), true ) );
dws_assert( empty( $not_releasing ), 'components without a usable GitHub Release are not attached (homeboy#14813)' );
// local_path must be empty: the runner has no checkouts and Homeboy resolves
// each component from its GitHub Release (homeboy#14782). The key is still
// present as "" until homeboy#14795 ships serde(default).
$bad_attach = array_filter( (array) $attachments, static fn( $c ) => ! isset( $c['id'], $c['remote_path'] ) || '' !== ( $c['local_path'] ?? '' ) || ! preg_match( '#^wp-content/(plugins|themes|mu-plugins)/[a-z0-9-]+$#', $c['remote_path'] ) );
dws_assert( empty( $bad_attach ), 'every attachment has id + wp-content remote_path and an empty local_path' );
$registry_dir = $root . '/deploy/homeboy/components';
$missing_reg  = array_filter( (array) $attachments, static fn( $c ) => ! is_file( $registry_dir . '/' . $c['id'] . '.json' ) );
dws_assert( empty( $missing_reg ), 'every attachment has a standalone registry entry' );
$bad_reg = array();
foreach ( glob( $registry_dir . '/*.json' ) ?: array() as $f ) {
	$r = json_decode( (string) file_get_contents( $f ), true );
	if ( ! is_array( $r ) || ( $r['id'] ?? null ) !== basename( $f, '.json' ) || ! preg_match( '#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) ( $r['remote_url'] ?? '' ) ) || isset( $r['local_path'] ) ) {
		$bad_reg[] = basename( $f );
	}
}
dws_assert( empty( $bad_reg ), 'every registry entry has a GitHub remote_url, matching id, and no local_path' );
dws_assert( ( $s['id'] ?? null ) === 'hetzner' && array_key_exists( 'identity_file', $s ) && null === $s['identity_file'], 'server has null identity_file (key comes from ssh-key)' );
// CI must connect as the restricted deploy account, never as an operator
// account with broader reach than wp-content/{plugins,themes,mu-plugins}.
dws_assert( ( $s['user'] ?? null ) === 'deploy', 'server targets the restricted deploy user' );
dws_assert( '' === ( $p['database']['name'] ?? 'x' ) && '' === ( $p['database']['user'] ?? 'x' ), 'no database credentials committed' );
dws_assert( false === ( $p['api']['enabled'] ?? true ), 'project api disabled' );

if ( $failures ) {
	echo count( $failures ) . " failure(s).\n";
	exit( 1 );
}
echo "All deploy workflow checks passed.\n";

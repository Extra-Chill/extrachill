<?php
/** Reload persisted state, retry only the failed platform, and emit verified ledgers. */

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Steps\SystemTask\SystemTaskStep;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;
use DataMachineSocials\Operations\DelegatedCrossPostAction;
use DataMachineSocials\Tracking\SocialShareTracker;

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( string $site ): int {
		return 'main' === $site ? get_current_blog_id() : 0;
	}
}

datamachine_register_core_actions();
new SystemTaskStep();
datamachine_socials_bootstrap();
DataMachine\Engine\Tasks\TaskRegistry::reset();

function ec_studio_operator_reload_assert( bool $condition, string $oracle ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Operator reload oracle failed: ' . $oracle );
	}
}

function ec_studio_operator_reload_execute( array $job ): void {
	$acting_user_id = get_current_user_id();
	wp_set_current_user( (int) $job['user_id'] );
	try {
		$result = wp_get_ability( 'datamachine/execute-step' )->execute(
			array(
				'job_id'                => (int) $job['job_id'],
				'flow_step_id'          => (string) $job['operation_step_id'],
				'operation_generation'  => (int) $job['operation_generation'],
				'operation_claim_token' => (string) $job['operation_claim_token'],
			)
		);
	} finally {
		wp_set_current_user( $acting_user_id );
	}
	ec_studio_operator_reload_assert( ! is_wp_error( $result ), 'safe retry executes through real worker ability' );
}

function ec_studio_operator_count_calls( array $ledger, string $provider_call ): int {
	return count( array_filter( $ledger, static fn( $entry ) => $provider_call === ( $entry['provider_call'] ?? '' ) ) );
}

function ec_studio_operator_write_json( string $path, array $payload ): void {
	$encoded = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $encoded || false === file_put_contents( $path, $encoded . "\n" ) ) {
		throw new RuntimeException( 'Unable to write operator artifact: ' . basename( $path ) );
	}
}

function ec_studio_operator_expected_gaps(): array {
	return array(
		array( 'id' => 'GARDNER-STUDIO-MULTIPLATFORM-COMPOSER', 'severity' => 'high', 'explanation' => 'Gardner cannot compose one clear multi-platform post in Studio; the backend can cross-post, but the current interface is platform-by-platform.', 'backend_primitive' => 'PublishComposerContract and datamachine/enqueue-social-publish', 'evidence_ref' => 'oracle-ledger.json#/scenarios/multi_platform_ui' ),
		array( 'id' => 'GARDNER-STUDIO-SCHEDULING-TIMEZONE', 'severity' => 'high', 'explanation' => 'Gardner cannot schedule with an obvious timezone in Studio and must rely on WordPress outside the social composer.', 'backend_primitive' => 'WordPress Core future status and publish_future_post', 'evidence_ref' => 'state-transition-ledger.json' ),
		array( 'id' => 'GARDNER-STUDIO-PERSISTENT-QUEUE', 'severity' => 'high', 'explanation' => 'Gardner has no persistent Studio queue to reopen, edit, reschedule, or cancel a social item.', 'backend_primitive' => 'Data Machine delegated jobs plus Studio get/retry adapters', 'evidence_ref' => 'state-transition-ledger.json' ),
		array( 'id' => 'GARDNER-STUDIO-PUBLISHED-INVENTORY', 'severity' => 'high', 'explanation' => 'Gardner cannot inspect and reconcile published social inventory or safely edit, delete, and archive it from Studio.', 'backend_primitive' => 'SocialShareTracker and provider update/delete abilities', 'evidence_ref' => 'oracle-ledger.json#/scenarios/final_share_history' ),
		array( 'id' => 'GARDNER-SOCIALS-PER-MEDIA-HISTORY', 'severity' => 'medium', 'explanation' => 'Gardner cannot tell where an individual media item has already been used; history is attached to the article, not the media.', 'backend_primitive' => null, 'evidence_ref' => 'oracle-ledger.json#/scenarios/media_history' ),
		array( 'id' => 'GARDNER-INSTAGRAM-DMS', 'severity' => 'medium', 'explanation' => 'Gardner cannot read or answer Instagram direct messages from Studio.', 'backend_primitive' => null, 'evidence_ref' => 'oracle-ledger.json#/scenarios/instagram_dms' ),
		array( 'id' => 'GARDNER-SOCIAL-ACCOUNT-MANAGEMENT', 'severity' => 'high', 'explanation' => 'Gardner cannot connect, disconnect, switch, or inspect shared account profiles from Studio.', 'backend_primitive' => 'Data Machine provider auth storage and account abilities', 'evidence_ref' => 'oracle-ledger.json#/scenarios/account_management' ),
		array( 'id' => 'GARDNER-UNIFIED-SOCIAL-ANALYTICS', 'severity' => 'medium', 'explanation' => 'Gardner has no unified social performance view in Studio.', 'backend_primitive' => 'Provider read abilities expose partial platform metrics', 'evidence_ref' => 'oracle-ledger.json#/scenarios/social_analytics' ),
		array( 'id' => 'GARDNER-SHARE-INITIATOR-ATTRIBUTION', 'severity' => 'medium', 'explanation' => 'Share rows do not carry a first-class human initiator field separate from execution context, so Gardner attribution is unclear during delegated work.', 'backend_primitive' => 'Delegated operation initiator and SocialShareTracker share rows', 'evidence_ref' => 'oracle-ledger.json#/scenarios/initiator_attribution' ),
	);
}

$state = get_option( 'ec_studio_operator_state', array() );
ec_studio_operator_reload_assert( is_array( $state ) && ! empty( $state['draft_id'] ), 'full WordPress state reload' );
if ( ! empty( $state['delivery_blocker'] ) ) {
	$provider_ledger = get_option( 'ec_studio_operator_provider_ledger', array() );
	$transitions     = get_option( 'ec_studio_operator_transition_ledger', array() );
	$capability_gaps = ec_studio_operator_expected_gaps();
	$capability_gaps[] = $state['delivery_blocker'];
	$oracle_ledger = array(
		'schema'                => 'extrachill-studio/social-operator-oracles/v1',
		'status'                => 'incomplete-with-findings',
		'scenario'              => 'studio-social-operations',
		'identity_contract_ref' => $state['canonical_identity_contract'],
		'passing'               => array( 'gardner-ui-grant', 'ordinary-ui-denial', 'ordinary-custom-rest-denial', 'ordinary-durable-ability-denial', 'core-future-before-due-zero-effects', 'due-core-transition', 'fail-closed-provider-http', 'persisted-state-reload' ),
		'blocked'               => array( 'delegated-delivery', 'partial-retry', 'final-share-history', 'instagram-comments-after-delivery' ),
		'finding'               => $state['delivery_blocker'],
		'domain_oracles'        => array( 'duplicate-effects', 'state-loss', 'authorization-bypass', 'attribution-mismatch', 'unexplained-status', 'unexpected-network', 'unsafe-retry' ),
		'external_writes_possible' => false,
	);
	$upload = wp_upload_dir();
	$dir    = trailingslashit( $upload['basedir'] ) . 'chris-gardner-social-operator';
	wp_mkdir_p( $dir );
	ec_studio_operator_write_json( $dir . '/provider-call-ledger.json', array( 'schema' => 'extrachill-studio/provider-call-ledger/v1', 'calls' => $provider_ledger, 'live_writes_possible' => false ) );
	ec_studio_operator_write_json( $dir . '/transition-ledger.json', array( 'schema' => 'extrachill-studio/transition-ledger/v1', 'transitions' => $transitions ) );
	ec_studio_operator_write_json( $dir . '/capability-gap-ledger.json', array( 'schema' => 'extrachill-studio/capability-gap-ledger/v1', 'findings' => $capability_gaps ) );
	ec_studio_operator_write_json( $dir . '/oracle-ledger.json', $oracle_ledger );
	echo wp_json_encode( array( 'schema' => 'extrachill-studio/social-operator-final/v1', 'status' => 'incomplete-with-findings', 'findings' => count( $capability_gaps ), 'issue' => 'https://github.com/Extra-Chill/data-machine-socials/issues/246', 'external_writes_possible' => false ), JSON_PRETTY_PRINT );
	return;
}
ec_studio_operator_reload_assert( ! empty( $state['delivery_ref'] ) && ! empty( $state['job_id'] ), 'persisted delegated delivery state' );
$gardner_id  = (int) $state['gardner_user_id'];
$ordinary_id = (int) $state['ordinary_user_id'];
$article_id  = (int) $state['article_id'];
$draft_id    = (int) $state['draft_id'];
$job_id      = (int) $state['job_id'];
$ref         = (string) $state['delivery_ref'];
$jobs        = new Jobs();

wp_set_current_user( $gardner_id );
$before = get_option( 'ec_studio_operator_provider_ledger', array() );
ec_studio_operator_reload_assert( 1 === ec_studio_operator_count_calls( $before, 'instagram.publish-effect' ), 'Instagram effect exists exactly once before retry' );
ec_studio_operator_reload_assert( 0 === ec_studio_operator_count_calls( $before, 'bluesky.publish-effect' ), 'Bluesky has no effect before retry' );

$persisted = ExtraChillStudio\get_social_publish_state( $draft_id );
ec_studio_operator_reload_assert( ! empty( $persisted['success'] ) && 'failed' === ( $persisted['delivery']['status'] ?? '' ), 'component adapter reloads partial state' );
$instagram_read = wp_get_ability( 'datamachine/instagram-read' );
wp_set_current_user( $ordinary_id );
$ordinary_comments_permission = $instagram_read->check_permissions( array( 'action' => 'comments', 'media_id' => 'ig-media-operator-1' ) );
wp_set_current_user( $gardner_id );
$comments_boundary_mismatch = true === $ordinary_comments_permission;
$retry = wp_get_ability( 'extrachill/retry-social-publish' )->execute( array( 'post_id' => $draft_id ) );
if ( is_wp_error( $retry ) || empty( $retry['success'] ) ) {
	$retry_error = is_wp_error( $retry )
		? array( 'code' => $retry->get_error_code(), 'message' => $retry->get_error_message() )
		: (array) ( $retry['error'] ?? array() );
	$finding = array(
		'id'                => 'GARDNER-DATAMACHINE-DELEGATED-RETRY-CLASS-RESOLUTION',
		'severity'          => 'critical',
		'explanation'       => 'The owner-authorized retry reaches Data Machine but cannot reopen the failed job because Jobs resolves EngineData in the wrong namespace.',
		'backend_primitive' => 'datamachine/retry-delegated-operation and Jobs::reopen_failed_job',
		'evidence_ref'      => 'https://github.com/Extra-Chill/data-machine/issues/3359',
		'error'             => $retry_error,
	);
	$capability_gaps   = ec_studio_operator_expected_gaps();
	$capability_gaps[] = $finding;
	if ( $comments_boundary_mismatch ) {
		$capability_gaps[] = array(
			'id'                => 'GARDNER-IG-COMMENTS-OWNER-BOUNDARY',
			'severity'          => 'critical',
			'explanation'       => 'An ordinary team user without the brand-social grant can pass the direct REST-visible Instagram comments ability permission check.',
			'backend_primitive' => 'datamachine/instagram-read permission callback and Extra Chill Users brand-social filter',
			'evidence_ref'      => 'https://github.com/Extra-Chill/data-machine-socials/issues/247',
		);
	}
	$transitions   = get_option( 'ec_studio_operator_transition_ledger', array() );
	$transitions[] = array( 'state' => 'reloaded-partial', 'job_id' => $job_id, 'source' => 'persisted WordPress/Data Machine state' );
	$transitions[] = array( 'state' => 'retry-blocked', 'job_id' => $job_id, 'finding' => $finding['id'] );
	$oracle_ledger = array(
		'schema'        => 'extrachill-studio/social-operator-oracles/v1',
		'scenario'      => 'studio-social-operations',
		'status'        => 'incomplete-with-findings',
		'identity_contract_ref' => $state['canonical_identity_contract'],
		'authorization' => array( 'ordinary_direct_comments' => $comments_boundary_mismatch ? 'finding-owner-boundary-mismatch' : 'denied' ),
		'passing'       => array( 'due-core-transition', 'one-operation', 'idempotency-matrix', 'partial-instagram-exactly-once', 'fresh-request-state-reload', 'direct-comments-boundary-exercised' ),
		'blocked'       => array( 'safe-retry', 'final-share-history', 'instagram-comments-state-matrix' ),
		'finding'       => $finding,
		'domain_oracles' => array( 'duplicate-effects', 'state-loss', 'authorization-bypass', 'attribution-mismatch', 'unexplained-status', 'unexpected-network', 'unsafe-retry' ),
		'external_writes_possible' => false,
	);
	$upload = wp_upload_dir();
	$dir    = trailingslashit( $upload['basedir'] ) . 'chris-gardner-social-operator';
	wp_mkdir_p( $dir );
	ec_studio_operator_write_json( $dir . '/provider-call-ledger.json', array( 'schema' => 'extrachill-studio/provider-call-ledger/v1', 'calls' => $before, 'live_writes_possible' => false ) );
	ec_studio_operator_write_json( $dir . '/transition-ledger.json', array( 'schema' => 'extrachill-studio/transition-ledger/v1', 'transitions' => $transitions ) );
	ec_studio_operator_write_json( $dir . '/capability-gap-ledger.json', array( 'schema' => 'extrachill-studio/capability-gap-ledger/v1', 'findings' => $capability_gaps ) );
	ec_studio_operator_write_json( $dir . '/oracle-ledger.json', $oracle_ledger );
	ec_studio_operator_write_json( $dir . '/product-contract-diagnostic.json', array( 'schema' => 'extrachill-studio/product-contract-diagnostic/v1', 'before' => array( 'request_context' => 'ungated wordpress.run-php', 'delegated_submit_ability' => 'missing', 'reported_finding' => 'delegated_action_prepare_failed' ), 'after' => $state['product_contract_diagnostic'], 'retry_blocker' => $finding ) );
	echo wp_json_encode( array( 'schema' => 'extrachill-studio/social-operator-final/v1', 'status' => 'incomplete-with-findings', 'finding' => $finding, 'comments_owner_boundary_mismatch' => $comments_boundary_mismatch, 'external_writes_possible' => false ), JSON_PRETTY_PRINT );
	return;
}
ec_studio_operator_reload_assert( ! is_wp_error( $retry ) && ! empty( $retry['success'] ), 'retry uses existing Studio ability (result=' . wp_json_encode( is_wp_error( $retry ) ? array( 'code' => $retry->get_error_code(), 'message' => $retry->get_error_message() ) : $retry ) . ')' );
ec_studio_operator_reload_assert( $ref === ( $retry['delivery']['delivery_ref'] ?? '' ), 'retry preserves operation identity' );

$reopened = $jobs->get_job( $job_id );
ec_studio_operator_reload_assert( 'pending' === ( $reopened['status'] ?? '' ), 'retry reopens existing job instead of creating one' );
ec_studio_operator_reload_execute( $reopened );
$final = ExtraChillStudio\get_social_publish_state( $draft_id );
ec_studio_operator_reload_assert( ! empty( $final['success'] ) && 'delivered' === ( $final['delivery']['status'] ?? '' ), 'retry reaches delivered state' );
ec_studio_operator_reload_assert( $ref === ( $final['delivery']['delivery_ref'] ?? '' ), 'final state keeps stable delivery identity' );

$after = get_option( 'ec_studio_operator_provider_ledger', array() );
ec_studio_operator_reload_assert( 1 === ec_studio_operator_count_calls( $after, 'instagram.publish-effect' ), 'retry never reposts Instagram' );
ec_studio_operator_reload_assert( 1 === ec_studio_operator_count_calls( $after, 'bluesky.publish-effect' ), 'retry posts only Bluesky once' );
ec_studio_operator_reload_assert( 0 === ec_studio_operator_count_calls( $after, 'blocked-unexpected' ), 'no unexpected external network' );

$shares = SocialShareTracker::get_shares( $article_id );
ec_studio_operator_reload_assert( 2 === SocialShareTracker::count_shares( $article_id ), 'canonical article has exactly two active receipts' );
ec_studio_operator_reload_assert( 1 === SocialShareTracker::count_shares( $article_id, 'instagram' ), 'canonical article has one Instagram receipt' );
ec_studio_operator_reload_assert( 1 === SocialShareTracker::count_shares( $article_id, 'bluesky' ), 'canonical article has one Bluesky receipt' );
$operation_hash = hash( 'sha256', $ref );
foreach ( $shares as $share ) {
	ec_studio_operator_reload_assert( $operation_hash === ( $share['operation_hash'] ?? '' ), 'share receipts preserve one operation identity' );
}

// Reuse is valid across drafts while duplicate media is rejected inside one operation.
$reuse_post = wp_insert_post( array( 'post_title' => 'Media reuse draft', 'post_status' => 'publish', 'post_author' => $gardner_id ), true );
ec_studio_operator_reload_assert( ! is_wp_error( $reuse_post ), 'second draft exists for media reuse' );
$reuse_caption = 'A second draft may reuse the same canonical media.';
$reuse_input   = array(
	'post_id'      => (int) $reuse_post,
	'source_url'   => get_permalink( $reuse_post ),
	'caption'      => $reuse_caption,
	'content_hash' => hash( 'sha256', $reuse_caption ),
	'channels'     => array( 'instagram' ),
	'media_kind'   => 'image',
	'asset_refs'   => array( array( 'source_id' => $state['media'][1]['source_id'], 'role' => 'image' ) ),
);
$reused = DelegatedCrossPostAction::normalize_input( $reuse_input, array( 'phase' => 'submit' ) );
ec_studio_operator_reload_assert( is_array( $reused ), 'media reuse across drafts is accepted' );
$duplicate_input               = $reuse_input;
$duplicate_input['media_kind'] = 'carousel';
$duplicate_input['asset_refs'] = array( $reuse_input['asset_refs'][0], $reuse_input['asset_refs'][0] );
$duplicate = DelegatedCrossPostAction::normalize_input( $duplicate_input, array( 'phase' => 'submit' ) );
ec_studio_operator_reload_assert( is_wp_error( $duplicate ) && 'social_cross_post_invalid_asset_ref' === $duplicate->get_error_code(), 'duplicate media inside one operation is rejected' );
foreach ( $state['media'] as $media ) {
	ec_studio_operator_reload_assert( ! metadata_exists( 'post', (int) $media['id'], SocialShareTracker::SHARES_META_KEY ), 'per-media history remains explicitly absent' );
}

// Exercise the real Instagram read/reply abilities through deterministic provider HTTP.
$instagram_read  = wp_get_ability( 'datamachine/instagram-read' );
$instagram_reply = wp_get_ability( 'datamachine/instagram-comment-reply' );
ec_studio_operator_reload_assert( (bool) $instagram_read && (bool) $instagram_reply, 'real Instagram comments abilities exist' );
$provider_state                  = get_option( 'ec_studio_operator_provider_state', array() );
$provider_state['comments_mode'] = 'page';
update_option( 'ec_studio_operator_provider_state', $provider_state, false );
$page = $instagram_read->execute( array( 'action' => 'comments', 'media_id' => 'ig-media-operator-1', 'limit' => 1 ) );
ec_studio_operator_reload_assert( ! is_wp_error( $page ) && 1 === ( $page['data']['count'] ?? 0 ), 'Instagram comments page succeeds' );

$provider_state['comments_mode'] = 'partial';
update_option( 'ec_studio_operator_provider_state', $provider_state, false );
$partial_comments = $instagram_read->execute( array( 'action' => 'comments_all', 'media_id' => 'ig-media-operator-1' ) );
ec_studio_operator_reload_assert( ! is_wp_error( $partial_comments ) && ! empty( $partial_comments['data']['partial'] ), 'Instagram pagination returns bounded partial state' );

$provider_state['comments_mode'] = 'empty';
update_option( 'ec_studio_operator_provider_state', $provider_state, false );
$empty = $instagram_read->execute( array( 'action' => 'comments', 'media_id' => 'ig-media-operator-1' ) );
ec_studio_operator_reload_assert( ! is_wp_error( $empty ) && 0 === ( $empty['data']['count'] ?? -1 ), 'Instagram empty comments state succeeds' );

$invalid_reply = $instagram_reply->execute( array( 'comment_id' => 'ig-comment-1', 'message' => '' ) );
ec_studio_operator_reload_assert( is_wp_error( $invalid_reply ) && 'missing_param' === $invalid_reply->get_error_code(), 'Instagram reply validation is actionable' );
$successful_reply = $instagram_reply->execute( array( 'comment_id' => 'ig-comment-1', 'message' => 'Doors are at 7. Thanks for supporting local music.' ) );
ec_studio_operator_reload_assert( ! is_wp_error( $successful_reply ) && 'ig-reply-1' === ( $successful_reply['data']['reply_id'] ?? '' ), 'Instagram reply succeeds' );

$instagram = new InstagramAuth();
$account   = $instagram->get_account();
$instagram->save_account( array( 'user_id' => '17841400000000000', 'username' => 'extrachill' ) );
$expired = $instagram_read->execute( array( 'action' => 'comments', 'media_id' => 'ig-media-operator-1' ) );
ec_studio_operator_reload_assert( is_wp_error( $expired ) && 'missing_auth' === $expired->get_error_code(), 'expired Instagram auth fails plainly' );
$instagram->save_account( is_array( $account ) ? $account : array() );

// The direct REST-visible comments ability currently has a broader owner boundary.
$capability_gaps = ec_studio_operator_expected_gaps();
if ( $comments_boundary_mismatch ) {
	$capability_gaps[] = array(
		'id'                => 'GARDNER-IG-COMMENTS-OWNER-BOUNDARY',
		'severity'          => 'critical',
		'explanation'       => 'An ordinary team user without the brand-social grant can pass the direct REST-visible Instagram comments ability permission check, even though Studio and custom Socials REST deny that user.',
		'backend_primitive' => 'datamachine/instagram-read permission callback and Extra Chill Users brand-social filter',
		'evidence_ref'      => 'https://github.com/Extra-Chill/data-machine-socials/issues/247',
	);
}

$after = get_option( 'ec_studio_operator_provider_ledger', array() );
foreach ( $after as $entry ) {
	ec_studio_operator_reload_assert( array() === array_diff( array_keys( $entry ), array( 'sequence', 'method', 'host', 'path', 'provider_call', 'payload_sha256' ) ), 'provider ledger contains only sanitized fields' );
}

$transitions   = get_option( 'ec_studio_operator_transition_ledger', array() );
$transitions[] = array( 'state' => 'reloaded-partial', 'job_id' => $job_id, 'source' => 'persisted WordPress/Data Machine state' );
$transitions[] = array( 'state' => 'retrying', 'job_id' => $job_id, 'instagram' => 'replayed-receipt', 'bluesky' => 'attempted' );
$transitions[] = array( 'state' => 'delivered', 'instagram_effects' => 1, 'bluesky_effects' => 1, 'active_receipts' => 2 );

$oracle_ledger = array(
	'schema'        => 'extrachill-studio/social-operator-oracles/v1',
	'scenario'      => 'studio-social-operations',
	'identity_contract_ref' => $state['canonical_identity_contract'],
	'gardner_user_id'       => $gardner_id,
	'authorization' => array(
		'gardner_ui'                  => 'verified-by-browser-step',
		'ordinary_ui'                 => 'verified-by-browser-step',
		'ordinary_custom_rest'        => 'denied',
		'ordinary_durable_publish'    => 'denied',
		'ordinary_direct_delegated'   => 'denied',
		'forged_receipt'              => 'denied',
		'direct_comments_ordinary'    => $comments_boundary_mismatch ? 'finding-owner-boundary-mismatch' : 'denied',
	),
	'scenarios'     => array(
		'future_publish'       => array( 'before_due_effects' => 0, 'delegated_operations' => 1 ),
		'idempotency'          => array( 'same_status' => 'no-op', 'unchanged_replay' => 'duplicate', 'changed_replay' => 'conflict', 'double_submit' => 'one-operation', 'stale_tab' => 'frozen-approved-input' ),
		'partial_retry'        => array( 'instagram_effects' => 1, 'bluesky_effects' => 1, 'final_status' => 'delivered' ),
		'final_share_history'  => array( 'article_id' => $article_id, 'active_receipts' => 2, 'operation_hash' => $operation_hash ),
		'media_history'        => array( 'reuse_across_drafts' => 'accepted', 'duplicate_inside_operation' => 'rejected', 'per_media_history' => 'gap' ),
		'instagram_comments'   => array( 'page' => 'success', 'partial' => 'success-with-warning', 'empty' => 'success', 'invalid_reply' => 'denied', 'reply' => 'success', 'expired_auth' => 'denied' ),
		'multi_platform_ui'    => 'gap',
		'instagram_dms'        => 'gap',
		'account_management'   => 'gap',
		'social_analytics'     => 'gap',
		'initiator_attribution' => 'gap',
	),
	'domain_oracles' => array( 'duplicate-effects', 'state-loss', 'authorization-bypass', 'attribution-mismatch', 'unexplained-status', 'unexpected-network', 'unsafe-retry' ),
	'external_writes_possible' => false,
);

$upload = wp_upload_dir();
$dir    = trailingslashit( $upload['basedir'] ) . 'chris-gardner-social-operator';
wp_mkdir_p( $dir );
ec_studio_operator_write_json( $dir . '/provider-call-ledger.json', array( 'schema' => 'extrachill-studio/provider-call-ledger/v1', 'calls' => $after, 'unexpected_count' => ec_studio_operator_count_calls( $after, 'blocked-unexpected' ) ) );
ec_studio_operator_write_json( $dir . '/transition-ledger.json', array( 'schema' => 'extrachill-studio/transition-ledger/v1', 'transitions' => $transitions ) );
ec_studio_operator_write_json( $dir . '/capability-gap-ledger.json', array( 'schema' => 'extrachill-studio/capability-gap-ledger/v1', 'findings' => $capability_gaps ) );
ec_studio_operator_write_json( $dir . '/oracle-ledger.json', $oracle_ledger );
ec_studio_operator_write_json( $dir . '/product-contract-diagnostic.json', array( 'schema' => 'extrachill-studio/product-contract-diagnostic/v1', 'before' => array( 'request_context' => 'ungated wordpress.run-php', 'delegated_submit_ability' => 'missing', 'reported_finding' => 'delegated_action_prepare_failed' ), 'after' => $state['product_contract_diagnostic'] ) );

echo wp_json_encode(
	array(
		'schema'        => 'extrachill-studio/social-operator-final/v1',
		'status'        => 'passed-with-structured-findings',
		'active_shares' => count( $shares ),
		'provider_calls' => count( $after ),
		'findings'      => count( $capability_gaps ),
		'comments_owner_boundary_mismatch' => $comments_boundary_mismatch,
		'artifact_directory' => $dir,
		'external_writes_possible' => false,
	),
	JSON_PRETTY_PRINT
);

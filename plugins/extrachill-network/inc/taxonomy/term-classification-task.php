<?php
/**
 * Data Machine SystemTask for one bounded network term classification.
 *
 * @package ExtraChillNetwork\Taxonomy
 */

namespace ExtraChillNetwork\Taxonomy;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\AI\ConversationManager;
use DataMachine\Engine\AI\RequestBuilder;
use DataMachine\Engine\AI\System\Tasks\SystemTask;

defined( 'ABSPATH' ) || exit;

/**
 * Execute classification through Data Machine while the network owns policy.
 *
 * @method void  failJob(int $job_id, string $message)
 * @method void  completeJob(int $job_id, array $data)
 * @method array resolveSystemModel(array $params)
 */
class TermClassificationTask extends SystemTask {
	/** Return the registered task type. */
	public function getTaskType(): string {
		return \EXTRACHILL_NETWORK_TERM_CLASSIFICATION_TASK;
	}

	/** Automatic lifecycle work is trusted system maintenance. */
	public function requiresAgentContext(): bool {
		return false;
	}

	/** Return task registry and manual-run safety metadata. */
	public static function getTaskMeta(): array {
		return array(
			'label'            => 'Network Term Classification',
			'description'      => 'Classify one approved Extra Chill post against trusted network taxonomy identities.',
			'setting_key'      => 'extrachill_network_term_classification_enabled',
			'default_enabled'  => true,
			'trigger'          => 'On pending or publish content transitions',
			'trigger_type'     => 'event',
			'supports_run'     => true,
			'mutates'          => true,
			'supports_dry_run' => true,
			'requires_scope'   => true,
			'params_schema'    => array(
				'accepted' => array( 'site', 'post_id', 'taxonomies', 'fingerprint', 'force', 'dry_run' ),
				'required' => array( 'site', 'post_id' ),
				'scope'    => array( 'site', 'post_id' ),
			),
		);
	}

	/** Classification records enough prior state for bounded undo. */
	public function supportsUndo(): bool {
		return true;
	}

	/**
	 * Execute one bounded classification target.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $params Task parameters.
	 */
	public function executeTask( int $job_id, array $params ): void {
		$result = \extrachill_network_classify_post_terms( $params, array( $this, 'selectTerms' ) );
		if ( is_wp_error( $result ) ) {
			$this->failJob( $job_id, $result->get_error_message() );
			return;
		}
		$result['completed_at'] = current_time( 'mysql' );
		$this->completeJob( $job_id, $result );
	}

	/**
	 * Ask the configured Data Machine system model to choose only supplied identities.
	 *
	 * @param \WP_Post $post       Post.
	 * @param array    $candidates Approved candidates.
	 * @param array    $taxonomies Eligible taxonomies.
	 * @return array|\WP_Error
	 */
	public function selectTerms( $post, array $candidates, array $taxonomies ) {
		$model = $this->resolveSystemModel( array() );
		if ( empty( $model['provider'] ) || empty( $model['model'] ) ) {
			return new \WP_Error( 'classification_provider_unavailable', 'No default AI provider/model configured.' );
		}

		$choices = array_map(
			static fn( $candidate ) => array(
				'taxonomy' => $candidate['taxonomy'],
				'slug'     => $candidate['slug'],
				'name'     => $candidate['name'],
			),
			$candidates
		);
		$content = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 500, '...' );
		$prompt  = "Classify this post using only the supplied candidates. Select a candidate only when the post clearly refers to that exact entity. Return JSON only as an array of objects with taxonomy, slug, and confidence from 0 to 1. Omit weak or ambiguous matches.\n\n";
		$prompt .= 'Allowed taxonomies: ' . implode( ', ', $taxonomies ) . "\n";
		$prompt .= 'Title: ' . wp_strip_all_tags( (string) $post->post_title ) . "\nContent: " . $content . "\nCandidates: " . wp_json_encode( $choices );

		// Data Machine is runtime-provided rather than a Composer dependency.
		/* @phpstan-ignore-next-line class.notFound */
		$message = ConversationManager::buildConversationMessage( 'user', $prompt );
		/* @phpstan-ignore-next-line class.notFound */
		$response = RequestBuilder::build(
			array( $message ),
			$model['provider'],
			$model['model'],
			array(),
			array( 'system' ),
			array(
				'post_id'         => (int) $post->ID,
				'calling_user_id' => 0,
				'task_type'       => $this->getTaskType(),
			)
		);
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		/* @phpstan-ignore-next-line class.notFound */
		$json    = trim( RequestBuilder::resultText( $response ) );
		$json    = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $json );
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) ) {
			return new \WP_Error( 'invalid_classification_response', 'AI returned an invalid classification payload.' );
		}
		return $decoded;
	}

	/**
	 * Restore custom taxonomy effects recorded by the domain primitive.
	 *
	 * @param int   $job_id     Parent job ID.
	 * @param array $engine_data Parent engine data.
	 * @return array<string,mixed>
	 */
	public function undo( int $job_id, array $engine_data ): array {
		$effects = is_array( $engine_data['effects'] ?? null ) ? $engine_data['effects'] : array();
		if ( empty( $effects ) ) {
			/* @phpstan-ignore-next-line class.notFound */
			$jobs = new Jobs();
			/* @phpstan-ignore-next-line class.notFound */
			foreach ( $jobs->get_children( $job_id ) as $child ) {
				$effects = array_merge( $effects, (array) ( $child['engine_data']['effects'] ?? array() ) );
			}
		}

		$reverted = array();
		$skipped  = array();
		$failed   = array();
		foreach ( array_reverse( $effects ) as $effect ) {
			$result = \extrachill_network_restore_term_classification_effect( $effect );
			if ( 'reverted' === ( $result['status'] ?? '' ) ) {
				$reverted[] = $result;
			} elseif ( 'skipped' === ( $result['status'] ?? '' ) ) {
				$skipped[] = $result;
			} else {
				$failed[] = $result;
			}
		}
		return array(
			'success'  => empty( $failed ),
			'reverted' => $reverted,
			'skipped'  => $skipped,
			'failed'   => $failed,
		);
	}
}

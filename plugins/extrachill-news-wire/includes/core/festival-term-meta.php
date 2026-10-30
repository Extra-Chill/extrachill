<?php
/**
 * Festival Term Meta (Admin UI)
 *
 * Adds start/end date fields to the festival taxonomy edit screen.
 * News Wire plugin is the single source of truth for festival hub metadata.
 *
 * @package ExtraChillNewsWire
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if current site is the wire site.
 *
 * @return bool
 */
function ec_news_wire_is_wire_site() {
	return function_exists( 'ec_get_current_site_key' )
		? ( 'wire' === ec_get_current_site_key() )
		: ( 11 === (int) get_current_blog_id() );
}

/**
 * Render festival term meta fields (edit form).
 *
 * @param \WP_Term $term Festival term.
 * @return void
 */
function ec_news_wire_render_festival_term_meta_fields( $term ) {
	if ( ! ec_news_wire_is_wire_site() ) {
		return;
	}

	$start_date = (string) get_term_meta( $term->term_id, '_ec_festival_start_date', true );
	$end_date   = (string) get_term_meta( $term->term_id, '_ec_festival_end_date', true );

	wp_nonce_field( 'ec_news_wire_festival_term_meta', 'ec_news_wire_festival_term_meta_nonce' );
	?>
	<tr class="form-field">
		<th scope="row"><label for="ec_festival_start_date">Start date</label></th>
		<td>
			<input
				type="date"
				name="ec_festival_start_date"
				id="ec_festival_start_date"
				value="<?php echo esc_attr( $start_date ); ?>"
			/>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="ec_festival_end_date">End date</label></th>
		<td>
			<input
				type="date"
				name="ec_festival_end_date"
				id="ec_festival_end_date"
				value="<?php echo esc_attr( $end_date ); ?>"
			/>
		</td>
	</tr>
	<?php
}
add_action( 'festival_edit_form_fields', 'ec_news_wire_render_festival_term_meta_fields' );

/**
 * Render festival term meta fields (add form).
 *
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function ec_news_wire_render_festival_term_meta_fields_add( $taxonomy ) {
	if ( 'festival' !== (string) $taxonomy ) {
		return;
	}

	if ( ! ec_news_wire_is_wire_site() ) {
		return;
	}

	wp_nonce_field( 'ec_news_wire_festival_term_meta', 'ec_news_wire_festival_term_meta_nonce' );
	?>
	<div class="form-field">
		<label for="ec_festival_start_date">Start date</label>
		<input type="date" name="ec_festival_start_date" id="ec_festival_start_date" value="" />
	</div>
	<div class="form-field">
		<label for="ec_festival_end_date">End date</label>
		<input type="date" name="ec_festival_end_date" id="ec_festival_end_date" value="" />
	</div>
	<?php
}
add_action( 'festival_add_form_fields', 'ec_news_wire_render_festival_term_meta_fields_add' );

/**
 * Save festival term meta fields.
 *
 * @param int $term_id Term ID.
 * @return void
 */
function ec_news_wire_save_festival_term_meta_fields( $term_id ) {
	if ( ! ec_news_wire_is_wire_site() ) {
		return;
	}

	if ( ! isset( $_POST['ec_news_wire_festival_term_meta_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( $_POST['ec_news_wire_festival_term_meta_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'ec_news_wire_festival_term_meta' ) ) {
		return;
	}

	if ( isset( $_POST['ec_festival_start_date'] ) ) {
		$start_date = sanitize_text_field( wp_unslash( $_POST['ec_festival_start_date'] ) );
		if ( '' === $start_date ) {
			delete_term_meta( $term_id, '_ec_festival_start_date' );
		} else {
			update_term_meta( $term_id, '_ec_festival_start_date', $start_date );
		}
	}

	if ( isset( $_POST['ec_festival_end_date'] ) ) {
		$end_date = sanitize_text_field( wp_unslash( $_POST['ec_festival_end_date'] ) );
		if ( '' === $end_date ) {
			delete_term_meta( $term_id, '_ec_festival_end_date' );
		} else {
			update_term_meta( $term_id, '_ec_festival_end_date', $end_date );
		}
	}
}
add_action( 'edited_festival', 'ec_news_wire_save_festival_term_meta_fields' );
add_action( 'create_festival', 'ec_news_wire_save_festival_term_meta_fields' );

<?php
/**
 * Template part for displaying a Festival Wire card.
 * Used in archive-festival_wire.php, single-festival_wire.php (related posts),
 * and festival-wire-ajax.php (load more).
 *
 * @package ExtraChill
 * @since 1.0
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('festival-wire-card'); ?>>
	<?php if (has_post_thumbnail()): ?>
	<div class="festival-wire-card-image">
        <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
            <?php the_post_thumbnail( 'medium' ); ?>
        </a>
	</div>
	<?php endif; ?>
	
	<div class="festival-wire-card-content">
		<?php
		// Use unified taxonomy-badges structure
		echo '<div class="taxonomy-badges" style="position: relative; z-index: 2;">';
		
		// Categories
		$categories = get_the_category();
		if (!empty($categories)) {
			foreach ($categories as $category) {
				$cat_slug = sanitize_html_class($category->slug);
				echo '<a href="' . esc_url(get_category_link($category->term_id)) . '" class="taxonomy-badge category-badge category-' . $cat_slug . '-badge">' . esc_html($category->name) . '</a>';
			}
		}

		// Get festival taxonomy terms
		$festivals = get_the_terms(get_the_ID(), 'festival');
		if ($festivals && !is_wp_error($festivals)) {
			foreach ($festivals as $festival) {
				$festival_slug = sanitize_html_class($festival->slug);
				echo '<a href="' . esc_url(get_term_link($festival)) . '" class="taxonomy-badge festival-badge festival-' . $festival_slug . '">' . esc_html($festival->name) . '</a>';
			}
		}

		// Locations
		$locations = get_the_terms(get_the_ID(), 'location');
		if ( $locations && ! is_wp_error( $locations ) ) :
			foreach ( $locations as $location ) :
				$location_link = get_term_link( $location );
				if ( ! is_wp_error( $location_link ) ) :
					$loc_slug = sanitize_html_class( $location->slug );
					echo '<a href="' . esc_url( $location_link ) . '" class="taxonomy-badge location-badge location-' . $loc_slug . '" rel="tag">' . esc_html( $location->name ) . '</a>';
				endif;
			endforeach;
		endif;
		
		echo '</div>'; // .taxonomy-badges
		?>
		
		<header class="entry-header">
            <?php 
            // Use different heading level for archive vs related? For now, h2 consistent with archive.
            // Added card-link-target for archive-like behavior if needed (adjust if only for archive)
            the_title( sprintf( '<h2 class="entry-title"><a href="%s" class="card-link-target" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' ); 
            ?>
		</header><!-- .entry-header -->

		<div class="entry-meta">
			<?php
			$post_time_u = get_the_time('U'); // Get post time as Unix timestamp
			$current_time_u = current_time('timestamp'); // Get current time as Unix timestamp
			$time_diff_seconds = $current_time_u - $post_time_u;

			// Define threshold (e.g., 24 hours)
			$threshold_seconds = 24 * HOUR_IN_SECONDS; // Use WordPress constant

			if ($time_diff_seconds < $threshold_seconds && $time_diff_seconds > 0) {
			    // Within threshold: Show relative time
			    printf(
			        '<span class="posted-on"><time class="entry-date published updated" datetime="%1$s">%2$s ago</time></span>',
			        esc_attr(get_the_date(DATE_W3C)), // Use ISO 8601 format for datetime attribute
			        esc_html(human_time_diff($post_time_u, $current_time_u))
			    );
			} else {
			    // Older than threshold: Show standard date format (matching previous format)
			     printf(
			        '<span class="posted-on"><time class="entry-date published updated" datetime="%1$s">%2$s</time></span>',
			        esc_attr(get_the_date(DATE_W3C)),
			        esc_html(get_the_date('F j, Y \a\t g:ia')) // Use original format for older posts
			    );
			}
			?>
            <?php // Meta details like author/location removed as per archive structure ?>
		</div><!-- .entry-meta -->

		<div class="entry-summary">
			<?php echo wp_trim_words( get_the_excerpt(), 30, '...' ); // Use consistent excerpt trimming ?>
		</div><!-- .entry-summary -->
	</div>
</article><!-- #post-<?php the_ID(); ?> --> 
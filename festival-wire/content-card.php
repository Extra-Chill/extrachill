<?php
/**
 * Template part for displaying a Festival Wire card.
 * Used in archive-festival_wire.php, single-festival_wire.php (related posts),
 * and festival-wire-ajax.php (load more).
 *
 * @package ColorMag Pro
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('festival-wire-card'); ?>>
	<?php if (has_post_thumbnail()): ?>
	<div class="festival-wire-card-image">
        <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
            <?php the_post_thumbnail('medium'); // Consistent thumbnail size ?>
        </a>
	</div>
	<?php endif; ?>
	
	<div class="festival-wire-card-content">
		<?php
		// Display categories, festivals, and locations in a flex container
		echo '<div class="festival-badges">';
		
		// Categories
		$categories = get_the_category();
		if (!empty($categories)) {
			echo '<div class="festival-tags">';
			foreach ($categories as $category) {
				$cat_slug = sanitize_html_class($category->slug);
				// Added taxonomy-badge class for consistency
				echo '<a href="' . esc_url(get_category_link($category->term_id)) . '" class="taxonomy-badge category-badge category-' . $cat_slug . '-badge">' . esc_html($category->name) . '</a>';
			}
			echo '</div>';
		}

		// Festivals
		$festivals = get_the_terms( get_the_ID(), 'festival' );
		if ($festivals && !is_wp_error($festivals)) {
			echo '<div class="post-festivals">';
			foreach ($festivals as $festival) {
				// Classes updated for consistency: taxonomy-badge and festival-{slug}
				$festival_link_classes = 'taxonomy-badge festival-' . esc_attr($festival->slug);
				echo '<a href="' . esc_url(get_term_link($festival)) . '" class="' . $festival_link_classes . '">' . esc_html($festival->name) . '</a>';
			}
			echo '</div>';
		}

		// Locations
		$locations = get_the_terms( get_the_ID(), 'location' );
		if ( $locations && ! is_wp_error( $locations ) ) :
			echo '<div class="location-badges">'; // Container for locations
			foreach ( $locations as $location ) :
				$location_link = get_term_link( $location );
				if ( ! is_wp_error( $location_link ) ) :
					$loc_slug = sanitize_html_class( $location->slug );
					echo '<a href="' . esc_url( $location_link ) . '" class="taxonomy-badge location-badge location-' . $loc_slug . '-badge" rel="tag">' . esc_html( $location->name ) . '</a>';
				endif;
			endforeach;
			echo '</div>'; // Close .location-badges
		endif;
		
		echo '</div>'; // .festival-badges
		?>
		
		<header class="entry-header">
            <?php 
            // Use different heading level for archive vs related? For now, h2 consistent with archive.
            // Added card-link-target for archive-like behavior if needed (adjust if only for archive)
            the_title( sprintf( '<h2 class="entry-title"><a href="%s" class="card-link-target" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' ); 
            ?>
		</header><!-- .entry-header -->

		<div class="entry-meta">
			<span class="posted-on"><?php echo esc_html( get_the_date('F j, Y \a\t g:ia') ); ?></span>
            <?php // Meta details like author/location removed as per archive structure ?>
		</div><!-- .entry-meta -->

		<div class="entry-summary">
			<?php echo wp_trim_words( get_the_excerpt(), 30, '...' ); // Use consistent excerpt trimming ?>
		</div><!-- .entry-summary -->
	</div>
</article><!-- #post-<?php the_ID(); ?> --> 
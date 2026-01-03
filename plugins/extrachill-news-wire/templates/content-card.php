<?php
/**
 * Festival Wire Content Card Template
 *
 * Displays Festival Wire posts in card format with:
 * - Featured image with permalink
 * - Taxonomy badges (categories, festivals, locations)
 * - Smart date display (relative for recent, absolute for older)
 * - Trimmed excerpt
 *
 * Used by: archive pages, AJAX load-more, related posts
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
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
		// Display taxonomy badges using theme function with custom styling
		if ( function_exists('extrachill_display_taxonomy_badges') ) {
			extrachill_display_taxonomy_badges( get_the_ID(), array(
				'wrapper_style' => 'position: relative; z-index: 2;'
			) );
		}
		?>
		
		<header>
            <?php 
            // Use different heading level for archive vs related? For now, h2 consistent with archive.
            // Added card-link-target for archive-like behavior if needed (adjust if only for archive)
            the_title( sprintf( '<h2><a href="%s" class="card-link-target" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' ); 
            ?>
		</header>

		<div class="entry-meta">
			<?php
			$post_time_u = get_the_time('U');
			$current_time_u = current_time('timestamp');
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
		</div><!-- .entry-meta -->

		<div class="entry-summary">
			<?php echo wp_trim_words( get_the_excerpt(), 30, '...' ); // Use consistent excerpt trimming ?>
		</div><!-- .entry-summary -->
	</div>
</article><!-- #post-<?php the_ID(); ?> --> 
<?php
/**
 * The Template for displaying all single Band Profiles.
 */

get_header(); ?>

	<div id="primary" <?php generate_do_element_classes( 'content' ); ?>>
		<main id="main" <?php generate_do_element_classes( 'main' ); ?>>
			<?php
			/**
			 * generate_before_main_content hook.
			 *
			 * @since 0.1
			 */
			do_action( 'generate_before_main_content' );

			// --- Display Breadcrumbs ---
			// Moved to be a direct child of <main>, before the while loop and <article>
			if ( function_exists( 'bbp_breadcrumb' ) ) {
				bbp_breadcrumb( array( 
					'before' => '<div class="bbp-breadcrumb"><p>', 
					'after'  => '</p></div>',
					'sep_before' => '<span class="bbp-breadcrumb-sep">', 
					'sep_after'  => '</span>'
				) );
			}
			// --- End Breadcrumbs ---

			// --- Main Post Loop ---
			while ( have_posts() ) : the_post(); 
            
                $band_profile_id = get_the_ID();

                // Increment view count for this band profile
                if ( function_exists( 'bp_increment_band_profile_view_count' ) ) {
                    bp_increment_band_profile_view_count( $band_profile_id );
                }

                $forum_id = get_post_meta( $band_profile_id, '_band_forum_id', true );
                $allow_public_topics = get_post_meta( $band_profile_id, '_allow_public_topic_creation', true );

                // Get additional band meta
                $genre = get_post_meta( $band_profile_id, '_genre', true );
                $local_city = get_post_meta( $band_profile_id, '_local_city', true );
                $website_url = get_post_meta( $band_profile_id, '_website_url', true );
                $spotify_url = get_post_meta( $band_profile_id, '_spotify_url', true );
                $apple_music_url = get_post_meta( $band_profile_id, '_apple_music_url', true );
                $bandcamp_url = get_post_meta( $band_profile_id, '_bandcamp_url', true );

                // --- Get Social Links --- 
                $band_profile_social_links = get_post_meta( $band_profile_id, '_band_profile_social_links', true );
                if ( ! is_array( $band_profile_social_links ) ) {
                    $band_profile_social_links = array();
                }

            ?>

				<article id="post-<?php the_ID(); ?>" <?php post_class(); ?> <?php generate_do_microdata( 'article' ); ?>>
					<div class="inside-article">
						<?php
                        // --- Display Invitation Acceptance/Error Messages ---
                        if ( isset( $_GET['invite_accepted'] ) && $_GET['invite_accepted'] === '1' ) {
                            $invite_success_message = __( 'Invitation accepted! You are now a member of this band.', 'generatepress_child' );
                            if ( isset( $_GET['invite_warning'] ) && $_GET['invite_warning'] === 'cleanup_failed' ) {
                                $invite_success_message .= ' ' . __( '(A small cleanup task for the invitation record encountered an issue, but your membership is confirmed. Please contact an admin if you notice any problems.)', 'generatepress_child' );
                            }
                            echo '<div class="bp-notice bp-notice-success">';
                            echo '<p>' . esc_html( $invite_success_message ) . '</p>';
                            echo '</div>';
                        } elseif ( isset( $_GET['invite_error'] ) ) {
                            $error_code = sanitize_key( $_GET['invite_error'] );
                            $invite_error_message = '';
                            switch ( $error_code ) {
                                case 'invalid_token':
                                    $invite_error_message = __( 'The invitation link is invalid or has expired. Please request a new invitation.', 'generatepress_child' );
                                    break;
                                case 'not_artist':
                                    $invite_error_message = __( 'Your account is not recognized as an artist account. Please contact support if you believe this is an error.', 'generatepress_child' );
                                    break;
                                case 'membership_failed':
                                    $invite_error_message = __( 'There was an error adding you to the band. Please try again or contact support.', 'generatepress_child' );
                                    break;
                                default:
                                    $invite_error_message = __( 'An unknown error occurred while processing your invitation.', 'generatepress_child' );
                            }
                            if ( ! empty( $invite_error_message ) ) {
                                echo '<div class="bp-notice bp-notice-error">';
                                echo '<p>' . esc_html( $invite_error_message ) . '</p>';
                                echo '</div>';
                            }
                        }

						// Removed default generate_before_content hook to place header manually
						?>

                        <?php 
                        // Prepare for Hero Section
                        $hero_background_style = '';
                        $header_image_id = get_post_meta( $band_profile_id, '_band_profile_header_image_id', true );

                        if ( $header_image_id ) {
                            $image_url = wp_get_attachment_image_url( $header_image_id, 'full' ); // Or 'large', depending on desired size
                            if ( $image_url ) {
                                $hero_background_style = 'style="background-image: url(\'' . esc_url( $image_url ) . '\');"';
                            }
                        } else {
                            // Fallback for no specific header image; class-based styling can apply
                            $hero_background_style = ''; 
                        }
                        ?>

						<div class="band-profile-header band-hero" <?php echo $hero_background_style; ?>>
                            <div class="band-hero-overlay"></div>
                            <div class="band-hero-content">
                                <?php
                                // --- Manage Band Button (Moved to top of hero content) ---
                                if ( is_user_logged_in() ) :
                                    // Display "Manage Band Profile" button if the current user can manage members for this band profile.
                                    // This uses the custom capability 'manage_band_members'.
                                    if ( current_user_can( 'manage_band_members', $band_profile_id ) ) :
                                        $manage_band_url = get_permalink( get_page_by_path( 'manage-band-profiles' ) );
                                        if ( $manage_band_url ) {
                                            $manage_band_url_with_id = add_query_arg( 'band_id', $band_profile_id, $manage_band_url );
                                            echo '<div class="band-profile-actions" style="margin-bottom: 1em;">'; // Kept original class, added margin
                                            echo '<a href="' . esc_url( $manage_band_url_with_id ) . '" class="button band-manage-button">Manage Band</a>';
                                            echo '</div>';
                                        }
                                    endif;
                                endif;
                                // --- End Manage Band Button (Moved) ---
                                ?>
                                <?php // Display Profile Picture and then Title/Meta in a flex row
                                // The band-hero-top-row container helps to align the image and the text content side-by-side.
                                ?>
                                <div class="band-hero-top-row" style="display: flex; align-items: flex-start; /* gap: 20px; */ margin-bottom: 1em;">
                                    <?php if ( has_post_thumbnail( $band_profile_id ) ) : ?>
                                        <div class="band-profile-featured-image" /* style="flex-shrink: 0;" */ >
                                            <?php echo get_the_post_thumbnail( $band_profile_id, 'thumbnail' ); // This is the actual profile picture ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="band-hero-text-content" style="flex-grow: 1;">
                                        <h1 class="band-hero-title entry-title" itemprop="headline"><?php echo esc_html( get_the_title( $band_profile_id ) ); ?></h1>

                                        <?php if ( ! empty( $genre ) || ! empty( $local_city ) ) : ?>
                                            <p class="band-meta-info">
                                                <?php if ( ! empty( $genre ) ) : ?>
                                                    <span class="band-genre"><strong><?php esc_html_e( 'Genre:', 'generatepress_child' ); ?></strong> <?php echo esc_html( $genre ); ?></span>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $genre ) && ! empty( $local_city ) ) : ?>
                                                    <span class="band-meta-separator">|</span>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $local_city ) ) : ?>
                                                    <span class="band-local-scene"><strong><?php esc_html_e( 'Local Scene:', 'generatepress_child' ); ?></strong> <?php echo esc_html( $local_city ); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ( ! empty( $band_profile_social_links ) ) : ?>
									<div class="band-social-links">
										<?php foreach ( $band_profile_social_links as $icon ) :
											if ( empty( $icon['url'] ) ) continue;
											$icon_class = !empty($icon['icon']) ? $icon['icon'] : ('fab fa-' . preg_replace('/[^a-z0-9_-]/', '', strtolower($icon['type'])));
											$icon_class = esc_attr($icon_class);
											$url = esc_url($icon['url']);
										?>
											<a href="<?php echo $url; ?>" class="extrch-social-icon" target="_blank" rel="noopener">
												<i class="<?php echo $icon_class; ?>" aria-hidden="true"></i>
											</a>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>

                                <?php 
                                // --- Band Follow Button & Count --- 
                                if ( function_exists('bp_get_band_follower_count') && function_exists('bp_is_user_following_band') ) : 
                                    $follower_count = bp_get_band_follower_count( $band_profile_id );
                                    $is_following = is_user_logged_in() ? bp_is_user_following_band( get_current_user_id(), $band_profile_id ) : false;
                                    $follow_button_text = $is_following ? __( 'Following', 'generatepress_child' ) : __( 'Follow', 'generatepress_child' );
                                    $follow_button_action = $is_following ? 'unfollow' : 'follow';
                                ?>
                                <div class="band-follow-section">
                                    <span class="band-follower-count" id="band-follower-count-<?php echo esc_attr($band_profile_id); ?>">
                                        <?php echo sprintf( _n( '%s follower', '%s followers', $follower_count, 'generatepress_child' ), number_format_i18n( $follower_count ) ); ?>
                                    </span>
                                    <?php if ( is_user_logged_in() ) : ?>
                                        <?php 
                                        // Check if the band profile being viewed belongs to the current user - CANNOT FOLLOW OWN BAND
                                        $is_own_band = false;
                                        $user_bands = get_user_meta( get_current_user_id(), '_band_profile_ids', true );
                                        if ( is_array($user_bands) && in_array($band_profile_id, $user_bands) ) {
                                            $is_own_band = true;
                                        }
                                        ?>
                                        <?php if ( ! $is_own_band ): ?>
                                            <button type="button" class="button button-small bp-follow-band-button" 
                                                    data-action="<?php echo esc_attr( $follow_button_action ); ?>" 
                                                    data-band-id="<?php echo esc_attr( $band_profile_id ); ?>">
                                                <?php echo esc_html( $follow_button_text ); ?>
                                            </button>
                                        <?php else: ?>
                                            <?php // Optionally show a disabled button or nothing if it's their own band ?>
                                            <span class="bp-own-band-indicator">(<?php esc_html_e('Your Band', 'generatepress_child'); ?>)</span>
                                        <?php endif; ?>
                                    <?php else : // User not logged in ?>
                                        <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="button button-small bp-follow-login-button">
                                            <?php esc_html_e( 'Follow', 'generatepress_child' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; // End check for functions exist ?>
                                <?php // --- End Band Follow Button --- ?>

                                <?php
                                // --- Display Band Link Page URL (New) ---
                                $link_page_id_for_url_display = get_post_meta( $band_profile_id, '_extrch_link_page_id', true );
                                if ( $link_page_id_for_url_display && get_post_type( $link_page_id_for_url_display ) === 'band_link_page' ) {
                                    global $post; // Ensure $post is the band_profile CPT object
                                    if ( isset( $post ) && $post->post_type === 'band_profile' ) {
                                        $band_slug_for_url = $post->post_name;
                                        $public_url_href = ''; 
                                        $public_url_display_text = '';

                                        if ( defined('EXTRCH_LINKPAGE_DEV') && EXTRCH_LINKPAGE_DEV ) {
                                            $public_url_href = get_permalink( $link_page_id_for_url_display );
                                        } else {
                                            // Use canonical extrachill.link URL
                                            $public_url_href = 'https://extrachill.link/' . $band_slug_for_url;
                                        }

                                        if ( ! empty( $public_url_href ) ) {
                                            $public_url_display_text = preg_replace( '#^https?://#', '', $public_url_href );
                                            
                                            echo '<div class="band-public-link-display" style="margin-top: 1em; /* text-align: left; (Adjust as needed for alignment with follower count) */">';
                                            echo '<a href="' . esc_url( $public_url_href ) . '" target="_blank" rel="noopener" style="text-decoration: none; color: inherit;">' . esc_html( $public_url_display_text ) . '</a>';
                                            echo '</div>';
                                        }
                                    }
                                }
                                // --- End Display Band Link Page URL (New) ---
                                ?>

                                <?php /* Moved edit button container 
								<?php if ( current_user_can( 'edit_post', $band_profile_id ) ) : ?>
									<?php 
									$manage_page = get_page_by_path( 'manage-band-profiles' ); // Corrected slug
									if ( $manage_page ) { // Check if page exists before using its ID
										$edit_url = add_query_arg( 'band_id', $band_profile_id, get_permalink( $manage_page->ID ) );
										echo '<p class="band-edit-link"><a href="' . esc_url( $edit_url ) . '" class="button button-small">' . __( 'Edit Band Profile', 'generatepress_child' ) . '</a></p>';
									}
									?>
								<?php endif; ?>
                                */ ?>
							</div>
						</div>

						<?php
						/**
						 * generate_after_entry_title hook.
						 *
						 * @since 0.1
						 * @hooked generate_post_meta - 10
						 */
						// do_action( 'generate_after_entry_title' ); // Maybe hide default meta?
						?>

						<div class="entry-content" itemprop="text">
							<?php
                            // --- Band Bio & Members Section ---
                            echo '<div class="band-details-columns">';

                            // Column 1: Band Bio
                            echo '<div class="band-bio-column">';
                            if ( get_the_content() ) {
                                echo '<h2 class="section-title">' . __( 'About the Band', 'generatepress_child' ) . '</h2>';
                                echo '<div class="band-bio">';
                                echo wpautop( get_the_content() ); 
                                echo '</div>';
                            } else {
                                echo '<h2 class="section-title">' . __( 'About the Band', 'generatepress_child' ) . '</h2>';
                                echo '<p>' . __( 'No biography available yet.', 'generatepress_child' ) . '</p>';
                            }
                            echo '</div>'; // .band-bio-column

                            // Column 2: Band Members
                            echo '<div class="band-members-column">';
                            // Add a wrapper for the title and icon for flex layout if needed, and the icon itself
                            echo '<div class="band-roster-header" style="display: flex; align-items: center; justify-content: space-between;">';
                            echo '<h2 class="section-title">' . __( 'Band Roster', 'generatepress_child' ) . '</h2>';
                            // Icon for collapsing - starts with 'plus' as it's collapsed by default
                            echo '<i class="fa-solid fa-square-plus band-roster-toggle" onclick="toggleForumCollapse(this, \'band-roster-list-container\')" style="cursor: pointer; font-size: 27px; margin-left: 10px;"></i>';
                            echo '</div>'; // .band-roster-header

                            // Add a container div for the list that will be collapsed/expanded
                            // Add 'collapsed' class to make it collapsed by default and styles for transition
                            echo '<div id="band-roster-list-container" class="band-roster-list-container collapsed" style="overflow: hidden; transition: height 0.5s ease-out;">';

                            $roster_items_html = [];
                            $displayed_names_for_roster = []; // To track names already added to the roster list

                            // 1. Process Linked Members (Actual WP Users)
                            if ( function_exists('bp_get_linked_members') ) {
                                $linked_members = bp_get_linked_members( $band_profile_id );
                                if ( ! empty( $linked_members ) ) {
                                    foreach ( $linked_members as $member_obj ) {
                                        if ( $member_obj && isset( $member_obj->ID ) ) {
                                            $user_info = get_userdata( $member_obj->ID );
                                            if ( $user_info && !in_array( $user_info->display_name, $displayed_names_for_roster ) ) {
                                                $roster_items_html[] = '<li class="band-member-item linked-user">' .
                                                                        get_avatar( $user_info->ID, 60, 'mystery', esc_html($user_info->display_name) ) .
                                                                        '<span class="member-name"><a href="' . esc_url( bbp_get_user_profile_url( $user_info->ID ) ) . '">' . esc_html( $user_info->display_name ) . '</a></span>' .
                                                                     '</li>';
                                                $displayed_names_for_roster[] = $user_info->display_name;
                                            }
                                        }
                                    }
                                }
                            } else {
                                 error_log('bp_get_linked_members function does not exist when trying to display members on band profile.');
                            }

                            // 2. Process Pending Invitations
                            // Assumes bp_get_pending_invitations returns an array of invites with 'display_name' and 'email'
                            if ( function_exists('bp_get_pending_invitations') ) {
                                $pending_invitations = bp_get_pending_invitations( $band_profile_id );
                                if ( ! empty( $pending_invitations ) ) {
                                    foreach ( $pending_invitations as $invite ) {
                                        $invite_display_name = $invite['display_name'] ?? '';
                                        if ( !empty($invite_display_name) && !in_array( $invite_display_name, $displayed_names_for_roster ) ) {
                                            // Display like a plaintext member
                                            $roster_items_html[] = '<li class="band-member-item pending-invite-as-plaintext">' . // New class for potential distinct styling if ever needed
                                                                    get_avatar( '', 60, 'mystery', esc_html($invite_display_name) ) . // Use default avatar, no email
                                                                    '<span class="member-name">' . esc_html( $invite_display_name ) . '</span>' .
                                                                 '</li>';
                                            $displayed_names_for_roster[] = $invite_display_name;
                                        }
                                    }
                                }
                            } else {
                                error_log('bp_get_pending_invitations function does not exist on single-band_profile.php');
                            }

                            // 3. Process Plaintext Members
                            // Assumes bp_get_plaintext_members returns an array of members with 'display_name'
                            if ( function_exists('bp_get_plaintext_members') ) {
                                $plaintext_members = bp_get_plaintext_members( $band_profile_id );
                                if ( ! empty( $plaintext_members ) ) {
                                    foreach ( $plaintext_members as $pt_member ) {
                                        $pt_display_name = $pt_member['display_name'] ?? '';
                                        if ( !empty($pt_display_name) && !in_array( $pt_display_name, $displayed_names_for_roster ) ) {
                                            $roster_items_html[] = '<li class="band-member-item plaintext-entry">' .
                                                                    get_avatar( '', 60, 'mystery', esc_html($pt_display_name) ) . // No email for plaintext, use default avatar
                                                                    '<span class="member-name">' . esc_html( $pt_display_name ) . '</span>' .
                                                                 '</li>';
                                            $displayed_names_for_roster[] = $pt_display_name;
                                        }
                                    }
                                }
                            } else {
                                error_log('bp_get_plaintext_members function does not exist on single-band_profile.php');
                            }

                            // Display the collected roster
                            if ( ! empty( $roster_items_html ) ) {
                                echo '<ul class="band-members-list">';
                                foreach ( $roster_items_html as $item_html ) {
                                    echo $item_html;
                                }
                                echo '</ul>';
                            } else {
                                echo '<p>' . __( 'No band members listed yet.', 'generatepress_child' ) . '</p>';
                            }
                            echo '</div>'; // #band-roster-list-container (new wrapper for collapse)
                            echo '</div>'; // .band-members-column

                            echo '</div>'; // .band-details-columns

							// --- Display Band Forum Section --- 
							error_log('--- Band Profile Debug ---'); // Add to PHP error log
							error_log('Band Profile ID: ' . $band_profile_id);
							error_log('Forum ID from Meta: ' . $forum_id);
							error_log('Allow Public Topics: ' . $allow_public_topics);
							// error_log('Function bbp_topic_index exists: ' . (function_exists('bbp_topic_index') ? 'Yes' : 'No')); // Restore check

							// if ( ! empty( $forum_id ) && function_exists('bbp_topic_index') ) { // Restore original check
							if ( ! empty( $forum_id ) ) { // <<< REMOVED function_exists CHECK
								error_log('Forum section condition met (only checking for non-empty forum_id).'); // Updated log

								echo '<div class="band-forum-section">';
								// Update Forum Title to include Band Name
								echo '<h2 class="band-forum-title">' . sprintf( esc_html__( '%s Forum', 'generatepress_child' ), get_the_title() ) . '</h2>'; 

								// --- Sorting & Search UI (Adapted from loop-topics.php) ---
								$current_sort = $_GET['sort'] ?? 'default';
								$current_search = $_GET['bbp_search'] ?? '';

								echo '<div class="sorting-search">';

								// Sorting Form 
								echo '<div class="bbp-sorting-form">';
								echo '<form id="sortingForm" method="get">';
								// Preserve existing query args (like the band profile URL itself)
								foreach ($_GET as $key => $value) {
									if ($key !== 'sort' && $key !== 'bbp_search') {
										echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">' . "\n";
									}
								}
								echo '<label for="sortSelect" class="screen-reader-text">Sort Topics:</label>'; // Accessibility
								echo '<select name="sort" id="sortSelect" onchange="this.form.submit()">'; // Submit on change
								echo '<option value="default" ' . selected($current_sort, 'default', false) . '>Sort by Recent</option>';
								echo '<option value="upvotes" ' . selected($current_sort, 'upvotes', false) . '>Sort by Upvotes</option>';
								// Note: 'popular' sort might need adjustment if not using replies within band forums
								// echo '<option value="popular" ' . selected($current_sort, 'popular', false) . '>Sort by Popular</option>'; 
								echo '</select>';
								if (!empty($current_search)) {
									echo '<input type="hidden" name="bbp_search" value="' . esc_attr($current_search) . '">';
								}
								echo '</form>';
								echo '</div>';

								// Search Form
								echo '<div class="bbp-search-form">';
								echo '<form method="get">';
								// Preserve existing query args
								foreach ($_GET as $key => $value) {
									if ($key !== 'sort' && $key !== 'bbp_search') {
										echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">' . "\n";
									}
								}
								echo '<label for="bbp_search" class="screen-reader-text">Search Topics:</label>'; // Accessibility
								echo '<input type="text" name="bbp_search" id="bbp_search" placeholder="Search topics..." value="' . esc_attr($current_search) . '">';
								echo '<input type="hidden" name="sort" value="' . esc_attr($current_sort) . '">';
								echo '<button type="submit">Search</button>';
								echo '</form>';
								echo '</div>';

								echo '</div>'; // .sorting-search
								// --- End Sorting & Search UI ---

								// Check permissions for creating topics (needed later for the form)
								$can_create_topics = current_user_can( 'publish_topics', $forum_id );
								$public_can_post = ( $allow_public_topics === '1' && current_user_can('bbp_topic_creatable') );
								// error_log('Current User Can Publish Topics in Forum ' . $forum_id . ': ' . ($can_create_topics ? 'Yes' : 'No'));
								// error_log('Public Can Post (Allowed=' . $allow_public_topics . ', Site Cap=' . (current_user_can('bbp_topic_creatable') ? 'Yes' : 'No') . '): ' . ($public_can_post ? 'Yes' : 'No'));

								// --- Display Topic List ---
								echo '<div class="band-forum-topics">';
								// echo '<h3 class="band-topics-title">' . __( 'Recent Topics', 'generatepress_child' ) . '</h3>'; // Title might be redundant now
								
								// Prepare topic query args
								$topic_args = array( 
									'post_parent' => $forum_id, 
									'show_stickies' => false,
									// Add sorting/search parameters
									'posts_per_page' => get_option('_bbp_topics_per_page', 15), // Use bbPress setting
									'paged'          => bbp_get_paged(),
									// 'post_status' => array('publish', 'closed'), // Let bbp_has_topics handle status based on user caps
								); 

								// Apply sorting logic 
								if ($current_sort === 'upvotes') {
									$topic_args['meta_key'] = 'upvote_count'; // Assuming you have this meta key
									$topic_args['orderby']  = 'meta_value_num';
									$topic_args['order']    = 'DESC';
								} else { // Default: sort by recent activity
									$topic_args['orderby']  = 'meta_value';
									$topic_args['meta_key'] = '_bbp_last_active_time';
									$topic_args['meta_type'] = 'DATETIME'; // Important for correct sorting
									$topic_args['order']    = 'DESC';
								}

								// Apply search logic
								if (!empty($current_search)) {
									$topic_args['s'] = sanitize_text_field($current_search);
								}

								error_log('Checking bbp_has_topics with args: ' . print_r($topic_args, true));
								if ( bbp_has_topics( $topic_args ) ) :
									error_log('bbp_has_topics returned TRUE.');
									
									// Add pagination before the loop
									bbp_get_template_part('pagination', 'topics'); 
									
									?>
									<ul id="bbp-forum-<?php echo esc_attr( $forum_id ); ?>" class="bbp-topics">
										<?php /* REMOVE THE HEADER ROW
										<li class="bbp-header">
											<ul class="forum-titles">
												<li class="bbp-topic-title"><?php esc_html_e( 'Topic', 'bbpress' ); ?></li>
												<li class="bbp-topic-voice-count"><?php esc_html_e( 'Voices', 'bbpress' ); ?></li>
												<li class="bbp-topic-reply-count"><?php bbp_show_lead_topic() ? esc_html_e( 'Replies', 'bbpress' ) : esc_html_e( 'Posts', 'bbpress' ); ?></li>
												<li class="bbp-topic-freshness"><?php esc_html_e( 'Freshness', 'bbpress' ); ?></li>
											</ul>
										</li>
										*/ ?>

										<li class="bbp-body"> <?php // This might also need removal later if cards have their own wrapper ?>
											<?php while ( bbp_topics() ) : bbp_the_topic(); ?>
												<?php bbp_get_template_part( 'loop', 'single-topic-card' ); // <-- Use custom card layout ?>
											<?php endwhile; ?>
										</li>

										<?php /* REMOVE THE FOOTER ROW
										<li class="bbp-footer">
											<div class="tr">
												<p>
													<span class="td colspan<?php echo ( bbp_is_user_home() && ( bbp_is_favorites() || bbp_is_subscriptions() ) ) ? '5' : '4'; ?>">&nbsp;</span>
												</p>
											</div>
										</li>
										*/ ?>
									</ul><!-- #bbp-forum-<?php bbp_forum_id(); ?> -->
									<?php
									
									// Add pagination after the loop
									bbp_get_template_part('pagination', 'topics'); 

								else : 
									error_log('bbp_has_topics returned FALSE.');
									// No topics found - Custom message
									$band_name = esc_html( get_the_title( $band_profile_id ) );
									$custom_no_topics_message = sprintf(
										esc_html__( 'This space is for %s to connect with their community! If you\'re part of the band, why not start a new topic? Share your latest news, upcoming gigs, or just say hello to your fans.', 'generatepress_child' ),
										$band_name
									);
									echo '<div class="bbp-template-notice info"><p>' . $custom_no_topics_message . '</p></div>';
								endif; // end bbp_has_topics()
								echo '</div>'; // .band-forum-topics
								// --- End Display Topic List ---
								
								// --- Show "New Topic" form AFTER list (if user can post) ---
								if ( $can_create_topics || $public_can_post ) {
									error_log('Displaying New Topic Form.');
									bbp_get_template_part( 'form', 'topic' );
								} else {
									error_log('NOT displaying New Topic Form.');
								}
								// --- End New Topic Form --- 

								echo '</div>'; // .band-forum-section
							} else {
								error_log('Forum section condition NOT met. Forum ID: ' . $forum_id . ', Function Exists: ' . (function_exists('bbp_topic_index') ? 'Yes' : 'No'));
							}
							// --- End Band Forum Section --- 
							?>
						</div><!-- .entry-content -->

						<?php
						/**
						 * generate_after_content hook.
						 *
						 * @since 0.1
						 */
						do_action( 'generate_after_content' );
						?>
					</div><!-- .inside-article -->
				</article><!-- #post-## -->

			<?php endwhile; // end of the loop. ?>

			<?php
			/**
			 * generate_after_main_content hook.
			 *
			 * @since 0.1
			 */
			do_action( 'generate_after_main_content' );
			?>
		</main><!-- #main -->
	</div><!-- #primary -->

<?php
/**
 * generate_after_primary_content_area hook.
 *
 * @since 2.0
 */
do_action( 'generate_after_primary_content_area' );

generate_construct_sidebars();

get_footer(); 
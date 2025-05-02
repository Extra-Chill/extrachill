/**
 * File customizer.js.
 *
 * Theme Customizer enhancements for a better user experience.
 *
 * Contains handlers to make Theme Customizer preview reload changes asynchronously.
 */

( function ( $ ) {

	// Site title
	wp.customize( 'blogname', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-title a' ).text( to );
		} );
	} );

	// Site description.
	wp.customize( 'blogdescription', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-description' ).text( to );
		} );
	} );

	// Header display type
	wp.customize( 'colormag_header_display_type', function ( value ) {
		value.bind( function ( layout ) {
				var display_type = layout;

				if ( display_type === 'type_two' ) {
					$( 'body' ).removeClass( 'header_display_type_two' ).addClass( 'header_display_type_one' );
				} else if ( display_type === 'type_three' ) {
					$( 'body' ).removeClass( 'header_display_type_one' ).addClass( 'header_display_type_two' );
				} else if ( display_type === 'type_one' ) {
					$( 'body' ).removeClass( 'header_display_type_one header_display_type_two' );
				}
			}
		);
	} );

	// Site Layout Option
	wp.customize( 'colormag_site_layout', function ( value ) {
		value.bind( function ( layout ) {
				var site_layout = layout;

				if ( site_layout === 'wide_layout' ) {
					$( 'body' ).addClass( 'wide' );
				} else if ( site_layout === 'boxed_layout' ) {
					$( 'body' ).removeClass( 'wide' );
				}
			}
		);
	} );

	// Primary Color Option
	wp.customize( 'colormag_primary_color', function ( value ) {
		value.bind( function ( primaryColor ) {
			// Store internal style for primary color
			var primaryColorStyle = '<style id="colormag-internal-primary-color">' +
				'.colormag-button,blockquote,button,input[type=reset],input[type=button],input[type=submit]{background-color:' + primaryColor + '}' +
				'a,#masthead .main-small-navigation li:hover > .sub-toggle i, #masthead .main-small-navigation li.current-page-ancestor > .sub-toggle i, #masthead .main-small-navigation li.current-menu-ancestor > .sub-toggle i, #masthead .main-small-navigation li.current-page-item > .sub-toggle i, #masthead .main-small-navigation li.current-menu-item > .sub-toggle i,#masthead.colormag-header-classic #site-navigation .fa.search-top:hover,#masthead.colormag-header-classic #site-navigation.main-small-navigation .random-post a:hover .fa-random,#masthead.colormag-header-classic #site-navigation.main-navigation .random-post a:hover .fa-random,#masthead.colormag-header-classic .breaking-news .newsticker a:hover{color:' + primaryColor + '}' +
				'#site-navigation{border-top:4px solid ' + primaryColor + '}' +
				'.home-icon.front_page_on,.main-navigation a:hover,.main-navigation ul li ul li a:hover,.main-navigation ul li ul li:hover>a,.main-navigation ul li.current-menu-ancestor>a,.main-navigation ul li.current-menu-item ul li a:hover,.main-navigation ul li.current-menu-item>a,.main-navigation ul li.current_page_ancestor>a,.main-navigation ul li.current_page_item>a,.main-navigation ul li:hover>a,.main-small-navigation li a:hover,.site-header .menu-toggle:hover,#masthead.colormag-header-classic .main-navigation ul ul.sub-menu li:hover > a, #masthead.colormag-header-classic .main-navigation ul ul.sub-menu li.current-menu-ancestor > a, #masthead.colormag-header-classic .main-navigation ul ul.sub-menu li.current-menu-item > a,#masthead.colormag-header-clean #site-navigation .menu-toggle:hover,#masthead.colormag-header-clean #site-navigation.main-small-navigation .menu-toggle,#masthead.colormag-header-classic #site-navigation.main-small-navigation .menu-toggle,#masthead .main-small-navigation li:hover > a, #masthead .main-small-navigation li.current-page-ancestor > a, #masthead .main-small-navigation li.current-menu-ancestor > a, #masthead .main-small-navigation li.current-page-item > a, #masthead .main-small-navigation li.current-menu-item > a,#masthead.colormag-header-classic #site-navigation .menu-toggle:hover{background-color:' + primaryColor + '}' +
				'#masthead.colormag-header-classic .main-navigation ul > li:hover > a, #masthead.colormag-header-classic .main-navigation ul > li.current-menu-item > a, #masthead.colormag-header-classic .main-navigation ul > li.current-menu-ancestor > a, #masthead.colormag-header-classic .main-navigation ul li.focus > a, #masthead.colormag-header-classic .main-navigation ul ul.sub-menu li:hover, #masthead.colormag-header-classic .main-navigation ul ul.sub-menu li.current-menu-ancestor, #masthead.colormag-header-classic .main-navigation ul ul.sub-menu li.current-menu-item,#masthead.colormag-header-classic #site-navigation .menu-toggle:hover,#masthead.colormag-header-classic #site-navigation.main-small-navigation .menu-toggle{border-color:' + primaryColor + '}' +
				'.main-small-navigation .current-menu-item>a,.main-small-navigation .current_page_item>a,#masthead.colormag-header-clean .main-small-navigation li:hover > a, #masthead.colormag-header-clean .main-small-navigation li.current-page-ancestor > a, #masthead.colormag-header-clean .main-small-navigation li.current-menu-ancestor > a, #masthead.colormag-header-clean .main-small-navigation li.current-page-item > a, #masthead.colormag-header-clean .main-small-navigation li.current-menu-item > a{background:' + primaryColor + '}' +
				'#main .breaking-news-latest,.fa.search-top:hover,.main-navigation ul li.focus > a, #masthead.colormag-header-classic .main-navigation ul ul.sub-menu li.focus > a{background-color:' + primaryColor + '}' +
				'.byline a:hover,.comments a:hover,.edit-link a:hover,.posted-on a:hover,.social-links i.fa:hover,.tag-links a:hover,#masthead.colormag-header-clean .social-links li:hover i.fa,#masthead.colormag-header-classic .social-links li:hover i.fa,#masthead.colormag-header-clean .breaking-news .newsticker a:hover{color:' + primaryColor + '}' +
				'.widget_featured_posts .article-content .above-entry-meta .cat-links a,.widget_call_to_action .btn--primary,.colormag-footer--classic .footer-widgets-area .widget-title span::before,.colormag-footer--classic-bordered .footer-widgets-area .widget-title span::before{background-color:' + primaryColor + '}' +
				'.widget_featured_posts .article-content .entry-title a:hover{color:' + primaryColor + '}' +
				'.widget_featured_posts .widget-title{border-bottom:2px solid ' + primaryColor + '}' +
				'.widget_featured_posts .widget-title span,.widget_featured_slider .slide-content .above-entry-meta .cat-links a{background-color:' + primaryColor + '}' +
				'.widget_featured_slider .slide-content .below-entry-meta .byline a:hover,.widget_featured_slider .slide-content .below-entry-meta .comments a:hover,.widget_featured_slider .slide-content .below-entry-meta .posted-on a:hover,.widget_featured_slider .slide-content .entry-title a:hover{color:' + primaryColor + '}' +
				'.widget_highlighted_posts .article-content .above-entry-meta .cat-links a{background-color:' + primaryColor + '}' +
				'.widget_block_picture_news.widget_featured_posts .article-content .entry-title a:hover,.widget_highlighted_posts .article-content .below-entry-meta .byline a:hover,.widget_highlighted_posts .article-content .below-entry-meta .comments a:hover,.widget_highlighted_posts .article-content .below-entry-meta .posted-on a:hover,.widget_highlighted_posts .article-content .entry-title a:hover{color:' + primaryColor + '}' +
				'.category-slide-next,.category-slide-prev,.slide-next,.slide-prev,.tabbed-widget ul li{background-color:' + primaryColor + '}' +
				'i.fa-arrow-up, i.fa-arrow-down{color:' + primaryColor + '}' +
				'#secondary .widget-title{border-bottom:2px solid ' + primaryColor + '}' +
				'#content .wp-pagenavi .current,#content .wp-pagenavi a:hover,#secondary .widget-title span{background-color:' + primaryColor + '}' +
				'#site-title a{color:' + primaryColor + '}' +
				'.page-header .page-title{border-bottom:2px solid ' + primaryColor + '}' +
				'#content .post .article-content .above-entry-meta .cat-links a,.page-header .page-title span{background-color:' + primaryColor + '}' +
				'#content .post .article-content .entry-title a:hover,.entry-meta .byline i,.entry-meta .cat-links i,.entry-meta a,.post .entry-title a:hover,.search .entry-title a:hover{color:' + primaryColor + '}' +
				'.entry-meta .post-format i{background-color:' + primaryColor + '}' +
				'.entry-meta .comments-link a:hover,.entry-meta .edit-link a:hover,.entry-meta .posted-on a:hover,.entry-meta .tag-links a:hover,.single #content .tags a:hover{color:' + primaryColor + '}' +
				'.format-link .entry-content a,.more-link{background-color:' + primaryColor + '}' +
				'.count,.next a:hover,.previous a:hover,.related-posts-main-title .fa,.single-related-posts .article-content .entry-title a:hover{color:' + primaryColor + '}' +
				'.pagination a span:hover{color:' + primaryColor + '}' + 'border-color:' + primaryColor + '}' +
				'.pagination span{background-color:' + primaryColor + '}' +
				'#content .comments-area a.comment-edit-link:hover,#content .comments-area a.comment-permalink:hover,#content .comments-area article header cite a:hover,.comments-area .comment-author-link a:hover{color:' + primaryColor + '}' +
				'.comments-area .comment-author-link span{background-color:' + primaryColor + '}' +
				'.comment .comment-reply-link:hover,.nav-next a,.nav-previous a{color:' + primaryColor + '}' +
				'.footer-widgets-area .widget-title{border-bottom:2px solid ' + primaryColor + '}' +
				'.footer-widgets-area .widget-title span{background-color:' + primaryColor + '}' +
				'#colophon .footer-menu ul li a:hover,.footer-widgets-area a:hover,a#scroll-up i{color:' + primaryColor + '}' +
				'.advertisement_above_footer .widget-title{border-bottom:2px solid ' + primaryColor + '}' +
				'.advertisement_above_footer .widget-title span{background-color:' + primaryColor + '}' +
				'.sub-toggle{background:' + primaryColor + '}' +
				'.main-small-navigation li.current-menu-item > .sub-toggle i {color:' + primaryColor + '}' +
				'.error,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-10 .tg_module_block.tg_module_block--list-small:before{background:' + primaryColor + '}' +
				'.num-404{color:' + primaryColor + '}' +
				'#primary .widget-title{border-bottom: 2px solid ' + primaryColor + '}' +
				'#primary .widget-title span{background-color:' + primaryColor + '}' +
				'.elementor .tg-module-wrapper .module-title span,.elementor .tg-module-wrapper .tg-post-category,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-5 .tg_module_block .read-more{background-color:' + primaryColor + '}' +
				'.elementor .tg-module-wrapper .tg-module-meta .tg-module-comments a:hover,.elementor .tg-module-wrapper .tg-module-meta .tg-post-auther-name a:hover,.elementor .tg-module-wrapper .tg-module-meta .tg-post-date a:hover,.elementor .tg-module-wrapper .tg-module-title:hover a,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-7 .tg_module_block--white .tg-module-comments a:hover,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-7 .tg_module_block--white .tg-post-auther-name a:hover,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-7 .tg_module_block--white .tg-post-date a:hover,.elementor .tg-module-wrapper.tg-module-grid .tg_module_grid .tg-module-info .tg-module-meta a:hover,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-7 .tg_module_block--white .tg-module-title a:hover,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-10 .tg_module_block--white .tg-module-title a,.elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-10 .tg_module_block--white .tg-post-auther-name a:hover, .elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-10 .tg_module_block--white .tg-post-date a:hover, .elementor .tg-module-wrapper.tg-module-block.tg-module-block--style-10 .tg_module_block--white .tg-module-comments a:hover{color:' + primaryColor + '}' +
				'.elementor .tg-module-wrapper .module-title{border-bottom:1px solid ' + primaryColor + '}' +
				'.elementor .tg-trending-news .swiper-controls .swiper-button-next:hover, .elementor .tg-trending-news .swiper-controls .swiper-button-prev:hover{border-color:' + primaryColor + '}' +
				'.related-posts-wrapper-flyout .entry-title a:hover{color:' + primaryColor + '}' +
				'.related-posts-wrapper.style-three .article-content .entry-title a:hover:before{background:' + primaryColor + '}' +
				'.related-posts-main-title,.single-related-posts,.related-posts-wrapper,.related-posts-wrapper-flyout{display:none}' +
				'</style>';

			// Remove previously create internal style and add new one.
			$( 'head #colormag-internal-primary-color' ).remove();
			$( 'head' ).append( primaryColorStyle );
		} );
	} );

	// Footer Main Area Display Type
	wp.customize( 'colormag_main_footer_layout_display_type', function ( value ) {
		value.bind( function ( layout ) {
				var display_type = layout;

				if ( display_type === 'type_two' ) {
					$( '#colophon' ).removeClass( 'colormag-footer--classic-bordered' ).addClass( 'colormag-footer--classic' );
				} else if ( display_type === 'type_three' ) {
					$( '#colophon' ).removeClass( 'colormag-footer--classic' ).addClass( 'colormag-footer--classic-bordered' );
				} else if ( display_type === 'type_one' ) {
					$( '#colophon' ).removeClass( 'colormag-footer--classic colormag-footer--classic-bordered' );
				}
			}
		);
	} );

	// Footer Background Image Position
	wp.customize( 'colormag_footer_background_image_position', function ( value ) {
		value.bind( function ( position ) {
				var background_position = position;

				if ( background_position === 'left-top' ) {
					$( '#colophon' ).css( { 'background-position' : 'left top' } );
				} else if ( background_position === 'center-top' ) {
					$( '#colophon' ).css( { 'background-position' : 'center top' } );
				} else if ( background_position === 'right-top' ) {
					$( '#colophon' ).css( { 'background-position' : 'right top' } );
				} else if ( background_position === 'left-center' ) {
					$( '#colophon' ).css( { 'background-position' : 'left center' } );
				} else if ( background_position === 'center-center' ) {
					$( '#colophon' ).css( { 'background-position' : 'center center' } );
				} else if ( background_position === 'right-center' ) {
					$( '#colophon' ).css( { 'background-position' : 'right center' } );
				} else if ( background_position === 'left-bottom' ) {
					$( '#colophon' ).css( { 'background-position' : 'left bottom' } );
				} else if ( background_position === 'center-bottom' ) {
					$( '#colophon' ).css( { 'background-position' : 'center bottom' } );
				} else if ( background_position === 'right-bottom' ) {
					$( '#colophon' ).css( { 'background-position' : 'right bottom' } );
				}
			}
		);
	} );

	// Footer Background Image Size
	wp.customize( 'colormag_footer_background_image_size', function ( value ) {
		value.bind( function ( size ) {
				var background_size = size;

				if ( background_size === 'cover' ) {
					$( '#colophon' ).css( { 'background-size' : 'cover' } );
				} else if ( background_size === 'contain' ) {
					$( '#colophon' ).css( { 'background-size' : 'contain' } );
				} else if ( background_size === 'auto' ) {
					$( '#colophon' ).css( { 'background-size' : 'auto' } );
				}
			}
		);
	} );

	// Footer Background Image Attachment
	wp.customize( 'colormag_footer_background_image_attachment', function ( value ) {
		value.bind( function ( attachment ) {
				var background_attachment = attachment;

				if ( background_attachment === 'scroll' ) {
					$( '#colophon' ).css( { 'background-attachment' : 'scroll' } );
				} else if ( background_attachment === 'fixed' ) {
					$( '#colophon' ).css( { 'background-attachment' : 'fixed' } );
				}
			}
		);
	} );

	// Footer Background Image Repeat
	wp.customize( 'colormag_footer_background_image_repeat', function ( value ) {
		value.bind( function ( repeat ) {
				var background_repeat = repeat;

				if ( background_repeat === 'no-repeat' ) {
					$( '#colophon' ).css( { 'background-repeat' : 'no-repeat' } );
				} else if ( background_repeat === 'repeat' ) {
					$( '#colophon' ).css( { 'background-repeat' : 'repeat' } );
				} else if ( background_repeat === 'repeat-x' ) {
					$( '#colophon' ).css( { 'background-repeat' : 'repeat-x' } );
				} else if ( background_repeat === 'repeat-y' ) {
					$( '#colophon' ).css( { 'background-repeat' : 'repeat-y' } );
				}
			}
		);
	} );

	// Remove all of the meta data
	wp.customize( 'colormag_all_entry_meta_remove', function ( value ) {
		value.bind( function ( to ) {
			if ( to ) {
				$( '.above-entry-meta,.below-entry-meta,.tg-module-meta,.tg-post-categories' ).css( {
					'display' : 'none'
				} );
			} else {
				$( '.above-entry-meta,.below-entry-meta,.tg-module-meta,.tg-post-categories' ).css( {
					'display' : 'block'
				} );
			}
		} );
	} );

	// Disable the author only
	wp.customize( 'colormag_author_entry_meta_remove', function ( value ) {
		value.bind( function ( to ) {
			if ( to ) {
				$( '.below-entry-meta .byline, .tg-module-meta .tg-post-auther-name' ).css( {
					'display' : 'none'
				} );
			} else {
				$( '.below-entry-meta .byline, .tg-module-meta .tg-post-auther-name' ).css( {
					'display' : 'inline-block'
				} );
			}
		} );
	} );

	// Disable the date only
	wp.customize( 'colormag_date_entry_meta_remove', function ( value ) {
		value.bind( function ( to ) {
			if ( to ) {
				$( '.below-entry-meta .posted-on, .tg-module-meta .tg-post-date' ).css( {
					'display' : 'none'
				} );
			} else {
				$( '.below-entry-meta .posted-on, .tg-module-meta .tg-post-date' ).css( {
					'display' : 'inline-block'
				} );
			}
		} );
	} );

	// Disable the category only
	wp.customize( 'colormag_category_entry_meta_remove', function ( value ) {
		value.bind( function ( to ) {
			if ( to ) {
				$( '.above-entry-meta, .tg-post-categories' ).css( {
					'display' : 'none'
				} );
			} else {
				$( '.above-entry-meta, .tg-post-categories' ).css( {
					'display' : 'inline-block'
				} );
			}
		} );
	} );

	// Disable the comments only
	wp.customize( 'colormag_comments_entry_meta_remove', function ( value ) {
		value.bind( function ( to ) {
			if ( to ) {
				$( '.below-entry-meta .comments, .tg-module-meta .tg-module-comments' ).css( {
					'display' : 'none'
				} );
			} else {
				$( '.below-entry-meta .comments, .tg-module-meta .tg-module-comments' ).css( {
					'display' : 'inline-block'
				} );
			}
		} );
	} );

	// Disable the tags only
	wp.customize( 'colormag_tags_entry_meta_remove', function ( value ) {
		value.bind( function ( to ) {
			if ( to ) {
				$( '.below-entry-meta .tag-links' ).css( {
					'display' : 'none'
				} );
			} else {
				$( '.below-entry-meta .tag-links' ).css( {
					'display' : 'inline-block'
				} );
			}
		} );
	} );

	// Site title font size
	wp.customize( 'colormag_title_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-title a' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Site tagline font size
	wp.customize( 'colormag_tagline_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-description' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Primary menu font size
	wp.customize( 'colormag_primary_menu_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.main-navigation ul li a' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Primary sub-menu font size
	wp.customize( 'colormag_primary_sub_menu_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.main-navigation ul li ul li a' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Heading h1 tag font size
	wp.customize( 'colormag_heading_h1_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( 'h1' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Heading h2 tag font size
	wp.customize( 'colormag_heading_h2_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( 'h2' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Heading h3 tag font size
	wp.customize( 'colormag_heading_h3_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( 'h3' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Heading h4 tag font size
	wp.customize( 'colormag_heading_h4_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( 'h4' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Heading h5 tag font size
	wp.customize( 'colormag_heading_h5_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( 'h5' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Heading h6 tag font size
	wp.customize( 'colormag_heading_h6_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( 'h6' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Post Title font size
	wp.customize( 'colormag_post_title_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '#content .post .article-content .entry-title' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Page Title font size
	wp.customize( 'colormag_page_title_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.type-page .entry-title' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Widget Title font size
	wp.customize( 'colormag_widget_title_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '#secondary .widget-title' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Comment Title font size
	wp.customize( 'colormag_comment_title_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.comments-title, .comment-reply-title, #respond h3#reply-title' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Content font size
	wp.customize( 'colormag_content_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( 'body, button, input, select, textarea, p, blockquote p, dl, .previous a, .next a, .nav-previous a, .nav-next a, #respond h3#reply-title #cancel-comment-reply-link, #respond form input[type="text"], #respond form textarea, #secondary .widget, .error-404 .widget' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Post meta font size
	wp.customize( 'colormag_post_meta_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '#content .post .article-content .below-entry-meta .posted-on a, #content .post .article-content .below-entry-meta .byline a, #content .post .article-content .below-entry-meta .comments a, #content .post .article-content .below-entry-meta .tag-links a, #content .post .article-content .below-entry-meta .edit-link a, #content .post .article-content .below-entry-meta .total-views' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Button text font size
	wp.customize( 'colormag_button_text_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.colormag-button, input[type="reset"], input[type="button"], input[type="submit"], button, .more-link span' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Footer widget Titles font size
	wp.customize( 'colormag_footer_widget_title_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-widgets-area .widget-title' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Footer widget content font size
	wp.customize( 'colormag_footer_widget_content_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '#colophon, #colophon p' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Footer copyright text font size
	wp.customize( 'colormag_footer_copyright_text_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-socket-wrapper .copyright' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Footer small menu font size
	wp.customize( 'colormag_footer_small_menu_font_size', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-menu a' ).css( 'fontSize', parseInt( to ) );
		} );
	} );

	// Site Title color option
	wp.customize( 'colormag_site_title_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-title a' ).css( 'color', to );
		} );
	} );

	// Site Tagline color option
	wp.customize( 'colormag_site_tagline_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-description' ).css( 'color', to );
		} );
	} );

	// Primary menu text color option
	wp.customize( 'colormag_primary_menu_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.main-navigation a, .main-navigation ul li ul li a, .main-navigation ul li.current-menu-item ul li a, .main-navigation ul li ul li.current-menu-item a, .main-navigation ul li.current_page_ancestor ul li a, .main-navigation ul li.current-menu-ancestor ul li a, .main-navigation ul li.current_page_item ul li a' ).css( 'color', to );
		} );
	} );

	// Primary menu selected/hovered item color option
	wp.customize( 'colormag_primary_menu_selected_hovered_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.main-navigation a:hover, .main-navigation ul li.current-menu-item a, .main-navigation ul li.current_page_ancestor a, .main-navigation ul li.current-menu-ancestor a, .main-navigation ul li.current_page_item a, .main-navigation ul li:hover > a, .main-navigation ul li ul li a:hover, .main-navigation ul li ul li:hover > a, .main-navigation ul li.current-menu-item ul li a:hover' ).css( 'color', to );
		} );
	} );

	// Primary sub menu background color option
	wp.customize( 'colormag_primary_menu_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-navigation' ).css( 'backgroundColor', to );
		} );
	} );

	// Primary sub menu background color option
	wp.customize( 'colormag_primary_sub_menu_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.main-navigation .sub-menu, .main-navigation .children' ).css( 'backgroundColor', to );
		} );
	} );

	// Primary menu top border color option
	wp.customize( 'colormag_primary_menu_top_border_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#site-navigation' ).css( 'borderTopColor', to );
		} );
	} );

	// Header background color option
	wp.customize( 'colormag_header_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#header-text-nav-container' ).css( 'backgroundColor', to );
		} );
	} );

	// Content Part titles color option
	wp.customize( 'colormag_content_part_title_color', function ( value ) {
		value.bind( function ( to ) {
			$( 'h1, h2, h3, h4, h5, h6' ).css( 'color', to );
		} );
	} );

	// Posts title color option
	wp.customize( 'colormag_post_title_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.post .entry-title, .post .entry-title a' ).css( 'color', to );
		} );
	} );

	// Page title color option
	wp.customize( 'colormag_page_title_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.type-page .entry-title' ).css( 'color', to );
		} );
	} );

	// Content text color option
	wp.customize( 'colormag_content_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( 'body, button, input, select, textarea' ).css( 'color', to );
		} );
	} );

	// Post metacolor option
	wp.customize( 'colormag_post_meta_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.posted-on a, .byline a, .comments a, .tag-links a, .edit-link a' ).css( 'color', to );
		} );
	} );

	// Button text color option
	wp.customize( 'colormag_button_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.colormag-button, input[type="reset"], input[type="button"], input[type="submit"], button, .more-link span' ).css( 'color', to );
		} );
	} );

	// Button background color option
	wp.customize( 'colormag_button_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.colormag-button, input[type="reset"], input[type="button"], input[type="submit"], button, .more-link span' ).css( 'backgroundColor', to );
		} );
	} );

	// Sidebar widget title color option
	wp.customize( 'colormag_sidebar_widget_title_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#secondary .widget-title span' ).css( 'color', to );
		} );
	} );

	// Content section background color option
	wp.customize( 'colormag_content_section_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#main' ).css( 'backgroundColor', to );
		} );
	} );

	// Widget title color option
	wp.customize( 'colormag_footer_widget_title_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-widgets-area .widget-title span' ).css( 'color', to );
		} );
	} );

	// Footer widget content color option
	wp.customize( 'colormag_footer_widget_content_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-widgets-area, .footer-widgets-area p' ).css( 'color', to );
		} );
	} );

	// Footer widget content link text color option
	wp.customize( 'colormag_footer_widget_content_link_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-widgets-area a' ).css( 'color', to );
		} );
	} );

	// Footer widget background color option
	wp.customize( 'colormag_footer_widget_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-widgets-wrapper' ).css( 'backgroundColor', to );
		} );
	} );

	// Upper footer widget background color option
	wp.customize( 'colormag_upper_footer_widget_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#colophon .tg-upper-footer-widgets .widget' ).css( 'backgroundColor', to );
		} );
	} );

	// Footer copyright text color option
	wp.customize( 'colormag_footer_copyright_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-socket-wrapper .copyright' ).css( 'color', to );
		} );
	} );

	// Footer copyright link text color option
	wp.customize( 'colormag_footer_copyright_link_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-socket-wrapper .copyright a' ).css( 'color', to );
		} );
	} );

	// Footer small menu text color option
	wp.customize( 'colormag_footer_small_menu_text_color', function ( value ) {
		value.bind( function ( to ) {
			$( '#colophon .footer-menu ul li a' ).css( 'color', to );
		} );
	} );

	// Footer copyright part background color option
	wp.customize( 'colormag_footer_copyright_part_background_color', function ( value ) {
		value.bind( function ( to ) {
			$( '.footer-socket-wrapper' ).css( 'backgroundColor', to );
		} );
	} );

} )( jQuery );

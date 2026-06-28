<?php
/**
 * Living Design System Template
 *
 * A public, unlisted, noindexed style guide for the Extra Chill theme. Every
 * specimen consumes live CSS custom properties (var(--token)) so the page
 * always reflects whatever the shipped tokens currently are, including the
 * dark-mode overrides in root.css.
 *
 * A client-side tweak panel (assets/js/design-system.js) lets visitors edit
 * the core palette, type scale and radii live, copy the override set, share via
 * URL hash, and reset. Nothing is ever written to the server — the source of
 * truth for tokens stays the @extrachill/tokens package.
 *
 * Loaded via template_include (priority 5) in inc/core/design-system.php.
 *
 * @package ExtraChill
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Specimen data. Token names ONLY — no hardcoded colors or sizes. Every value
 * is rendered through var(--token) and the live computed value is filled in by
 * JS reading getComputedStyle() on load.
 * ---------------------------------------------------------------------------
 */

// Core palette colors — these are editable in the tweak panel.
$ds_palette_colors = array(
	'--background-color'  => 'Background',
	'--text-color'        => 'Text',
	'--link-color'        => 'Link',
	'--link-color-hover'  => 'Link Hover',
	'--border-color'      => 'Border',
	'--accent'            => 'Accent',
	'--accent-hover'      => 'Accent Hover',
	'--accent-2'          => 'Accent 2',
	'--accent-3'          => 'Accent 3',
	'--error-color'       => 'Error',
	'--success-color'     => 'Success',
	'--warning-color'     => 'Warning',
	'--info-color'        => 'Info',
	'--muted-text'        => 'Muted Text',
	'--card-background'   => 'Card Background',
	'--button-text-color' => 'Button Text',
);

// Identity badge colors — shown as swatches (not editable in v1).
$ds_identity_colors = array(
	'--artist-badge-color'       => 'Artist',
	'--team-badge-color'         => 'Team',
	'--professional-badge-color' => 'Professional',
);

// Type scale — editable (number + unit) in the tweak panel.
$ds_font_sizes = array(
	'--font-size-xs'    => 'xs',
	'--font-size-sm'    => 'sm',
	'--font-size-base'  => 'base',
	'--font-size-body'  => 'body',
	'--font-size-lg'    => 'lg',
	'--font-size-xl'    => 'xl',
	'--font-size-2xl'   => '2xl',
	'--font-size-3xl'   => '3xl',
	'--font-size-brand' => 'brand',
);

// Font families.
$ds_font_families = array(
	'--font-family-heading' => 'Heading (Loft Sans)',
	'--font-family-body'    => 'Body',
	'--font-family-brand'   => 'Brand (Lobster)',
	'--font-family-mono'    => 'Mono',
);

// Font weights.
$ds_font_weights = array(
	'--font-weight-normal'   => 'Normal',
	'--font-weight-medium'   => 'Medium',
	'--font-weight-semibold' => 'Semibold',
	'--font-weight-bold'     => 'Bold',
);

// Spacing scale — shown as bars, NOT editable.
$ds_spacing = array(
	'--spacing-xs' => 'xs',
	'--spacing-sm' => 'sm',
	'--spacing-md' => 'md',
	'--spacing-lg' => 'lg',
	'--spacing-xl' => 'xl',
);

// Border radii — editable (number + unit) in the tweak panel.
$ds_radii = array(
	'--border-radius-sm'     => 'sm',
	'--border-radius-md'     => 'md',
	'--border-radius-lg'     => 'lg',
	'--border-radius-xl'     => 'xl',
	'--border-radius-pill'   => 'pill',
	'--border-radius-circle' => 'circle',
);

// Representative real badge specimens. Each entry: [css-class, label].
$ds_festival_badges = array(
	array( 'festival-bonnaroo', 'Bonnaroo' ),
	array( 'festival-coachella', 'Coachella' ),
	array( 'festival-acl-festival', 'ACL Festival' ),
	array( 'festival-sxsw', 'SXSW' ),
	array( 'festival-electric-forest', 'Electric Forest' ),
	array( 'festival-hulaween', 'Hulaween' ),
	array( 'festival-shaky-knees', 'Shaky Knees' ),
	array( 'festival-governors-ball', 'Governors Ball' ),
	array( 'festival-lollapalooza', 'Lollapalooza' ),
	array( 'festival-outside-lands', 'Outside Lands' ),
	array( 'festival-riot-fest', 'Riot Fest' ),
	array( 'festival-hopscotch-festival', 'Hopscotch' ),
);

$ds_location_badges = array(
	array( 'location-charleston', 'Charleston' ),
	array( 'location-austin', 'Austin' ),
	array( 'location-asheville', 'Asheville' ),
	array( 'location-nashville', 'Nashville' ),
	array( 'location-new-orleans', 'New Orleans' ),
	array( 'location-chicago', 'Chicago' ),
	array( 'location-atlanta', 'Atlanta' ),
	array( 'location-brooklyn', 'Brooklyn' ),
	array( 'location-denver', 'Denver' ),
	array( 'location-seattle', 'Seattle' ),
	array( 'location-savannah', 'Savannah' ),
	array( 'location-columbia', 'Columbia' ),
);

$ds_venue_badges = array(
	array( 'venue-the-royal-american', 'The Royal American' ),
	array( 'venue-the-bounty-bar', 'The Bounty Bar' ),
	array( 'venue-the-refinery', 'The Refinery' ),
	array( 'venue-lo-fi-brewing', 'Lo-Fi Brewing' ),
	array( 'venue-charleston-tin-roof', 'Charleston Tin Roof' ),
);

$ds_artist_badges = array(
	array( 'artist-susto', 'SUSTO' ),
);

// Category badges follow the `category-{slug}-badge` convention.
$ds_category_badges = array(
	array( 'category-interviews-badge', 'Interviews' ),
	array( 'category-concerts-badge', 'Concerts' ),
	array( 'category-festivals-badge', 'Festivals' ),
	array( 'category-music-news-badge', 'Music News' ),
	array( 'category-song-meanings-badge', 'Song Meanings' ),
	array( 'category-festival-news-badge', 'Festival News' ),
);

/**
 * Render a color swatch that consumes a live token.
 *
 * @param string $token CSS custom property name (e.g. --accent).
 * @param string $label Human label.
 */
function extrachill_ds_color_swatch( $token, $label ) {
	printf(
		'<div class="ds-swatch" data-ds-color-token="%1$s">' .
			'<span class="ds-swatch__chip" style="background:var(%1$s);"></span>' .
			'<span class="ds-swatch__meta">' .
				'<code class="ds-swatch__token">%1$s</code>' .
				'<span class="ds-swatch__label">%2$s</span>' .
				'<code class="ds-swatch__value" data-ds-computed="%1$s">—</code>' .
			'</span>' .
		'</div>',
		esc_attr( $token ),
		esc_html( $label )
	);
}

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<article id="design-system" class="ds-page">

	<header class="ds-page__header">
		<h1 class="ds-page__title">Extra Chill Design System</h1>
		<p class="ds-page__intro">
			A <strong>living style guide</strong> for the Extra Chill theme. Every specimen below
			consumes the live design tokens shipped in <code>root.css</code> (including dark-mode
			overrides). Use the tweak panel to propose changes — edits are <strong>local to your
			browser only</strong>, never saved to the server. The source of truth for tokens is the
			<code>@extrachill/tokens</code> package. Copy or share a tweaked link to send a
			recommended palette back to the team.
		</p>
	</header>

	<!-- ===================== TOKENS ===================== -->
	<section class="ds-section" id="ds-colors">
		<h2 class="ds-section__title">Colors</h2>
		<p class="ds-section__desc">Core palette. Each swatch shows the token name and its live computed value.</p>
		<div class="ds-swatch-grid">
			<?php foreach ( $ds_palette_colors as $token => $label ) : ?>
				<?php extrachill_ds_color_swatch( $token, $label ); ?>
			<?php endforeach; ?>
		</div>

		<h3 class="ds-subsection__title">Identity Badge Colors</h3>
		<div class="ds-swatch-grid">
			<?php foreach ( $ds_identity_colors as $token => $label ) : ?>
				<?php extrachill_ds_color_swatch( $token, $label ); ?>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="ds-section" id="ds-typography">
		<h2 class="ds-section__title">Typography</h2>

		<h3 class="ds-subsection__title">Type Scale</h3>
		<div class="ds-typescale">
			<?php foreach ( $ds_font_sizes as $token => $label ) : ?>
				<div class="ds-typescale__row">
					<span class="ds-typescale__sample" style="font-size:var(<?php echo esc_attr( $token ); ?>);">
						Extra Chill — the Online Music Scene
					</span>
					<span class="ds-typescale__meta">
						<code class="ds-typescale__token"><?php echo esc_html( $token ); ?></code>
						<span class="ds-typescale__name"><?php echo esc_html( $label ); ?></span>
						<code class="ds-typescale__value" data-ds-computed="<?php echo esc_attr( $token ); ?>">—</code>
					</span>
				</div>
			<?php endforeach; ?>
		</div>

		<h3 class="ds-subsection__title">Font Families</h3>
		<div class="ds-fonts">
			<?php foreach ( $ds_font_families as $token => $label ) : ?>
				<div class="ds-font" style="font-family:var(<?php echo esc_attr( $token ); ?>);">
					<span class="ds-font__sample">Aa Bb Cc — Extra Chill 2026</span>
					<span class="ds-font__meta">
						<code class="ds-font__token"><?php echo esc_html( $token ); ?></code>
						<span class="ds-font__name"><?php echo esc_html( $label ); ?></span>
					</span>
				</div>
			<?php endforeach; ?>
		</div>

		<h3 class="ds-subsection__title">Font Weights</h3>
		<div class="ds-weights">
			<?php foreach ( $ds_font_weights as $token => $label ) : ?>
				<div class="ds-weight" style="font-weight:var(<?php echo esc_attr( $token ); ?>);">
					<span class="ds-weight__sample">Extra Chill</span>
					<code class="ds-weight__token"><?php echo esc_html( $token ); ?></code>
					<span class="ds-weight__name"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="ds-section" id="ds-spacing">
		<h2 class="ds-section__title">Spacing</h2>
		<p class="ds-section__desc">Display only — spacing is shown but not editable in v1.</p>
		<div class="ds-spacing">
			<?php foreach ( $ds_spacing as $token => $label ) : ?>
				<div class="ds-spacing__row">
					<span class="ds-spacing__bar" style="width:var(<?php echo esc_attr( $token ); ?>);"></span>
					<code class="ds-spacing__token"><?php echo esc_html( $token ); ?></code>
					<span class="ds-spacing__name"><?php echo esc_html( $label ); ?></span>
					<code class="ds-spacing__value" data-ds-computed="<?php echo esc_attr( $token ); ?>">—</code>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="ds-section" id="ds-radius">
		<h2 class="ds-section__title">Border Radius</h2>
		<div class="ds-radii">
			<?php foreach ( $ds_radii as $token => $label ) : ?>
				<div class="ds-radius">
					<span class="ds-radius__box" style="border-radius:var(<?php echo esc_attr( $token ); ?>);"></span>
					<code class="ds-radius__token"><?php echo esc_html( $token ); ?></code>
					<span class="ds-radius__name"><?php echo esc_html( $label ); ?></span>
					<code class="ds-radius__value" data-ds-computed="<?php echo esc_attr( $token ); ?>">—</code>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- ===================== COMPONENTS ===================== -->
	<section class="ds-section" id="ds-buttons">
		<h2 class="ds-section__title">Buttons</h2>
		<p class="ds-section__desc">Real theme button classes, in all three sizes.</p>
		<?php
		$ds_button_variants = array(
			'button-1'      => 'Primary',
			'button-2'      => 'Accent',
			'button-3'      => 'Subtle',
			'button-danger' => 'Danger',
		);
		$ds_button_sizes    = array( 'button-small', 'button-medium', 'button-large' );
		?>
		<?php foreach ( $ds_button_variants as $variant => $variant_label ) : ?>
			<div class="ds-button-row">
				<span class="ds-button-row__label"><?php echo esc_html( $variant_label ); ?> <code>.<?php echo esc_html( $variant ); ?></code></span>
				<?php foreach ( $ds_button_sizes as $size ) : ?>
					<button type="button" class="<?php echo esc_attr( $variant . ' ' . $size ); ?>"><?php echo esc_html( ucfirst( str_replace( 'button-', '', $size ) ) ); ?></button>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</section>

	<section class="ds-section" id="ds-notices">
		<h2 class="ds-section__title">Notices</h2>
		<div class="notice notice-success">Success — the three-tier notice system.</div>
		<div class="notice notice-info">Info — neutral informational message.</div>
		<div class="notice notice-error">Error — something needs attention.</div>
	</section>

	<section class="ds-section" id="ds-cards">
		<h2 class="ds-section__title">Cards</h2>
		<div class="ds-card-grid">
			<div class="ds-card">
				<h3 class="ds-card__title">Card Specimen</h3>
				<p class="ds-card__body">
					Uses <code>--card-background</code>, <code>--card-shadow</code> and
					<code>--border-radius-lg</code>. Edit those tokens in the tweak panel and watch
					this card shift.
				</p>
				<button type="button" class="button-2 button-small">Action</button>
			</div>
			<div class="ds-card">
				<h3 class="ds-card__title">Another Card</h3>
				<p class="ds-card__body">
					Cards are a network-wide convention. The chrome here is built entirely from
					tokens — no hardcoded colors or sizes.
				</p>
				<button type="button" class="button-3 button-small">Secondary</button>
			</div>
		</div>
	</section>

	<section class="ds-section" id="ds-badges">
		<h2 class="ds-section__title">Taxonomy Badges</h2>
		<p class="ds-section__desc">Real badge classes from <code>taxonomy-badges.css</code>, colored by live <code>--badge-*</code> tokens.</p>

		<h3 class="ds-subsection__title">Festivals</h3>
		<div class="ds-badge-wrap">
			<?php foreach ( $ds_festival_badges as $badge ) : ?>
				<span class="taxonomy-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
			<?php endforeach; ?>
		</div>

		<h3 class="ds-subsection__title">Locations</h3>
		<div class="ds-badge-wrap">
			<?php foreach ( $ds_location_badges as $badge ) : ?>
				<span class="taxonomy-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
			<?php endforeach; ?>
		</div>

		<h3 class="ds-subsection__title">Venues</h3>
		<div class="ds-badge-wrap">
			<?php foreach ( $ds_venue_badges as $badge ) : ?>
				<span class="taxonomy-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
			<?php endforeach; ?>
		</div>

		<h3 class="ds-subsection__title">Artists</h3>
		<div class="ds-badge-wrap">
			<?php foreach ( $ds_artist_badges as $badge ) : ?>
				<span class="taxonomy-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
			<?php endforeach; ?>
		</div>

		<h3 class="ds-subsection__title">Categories</h3>
		<div class="ds-badge-wrap">
			<?php foreach ( $ds_category_badges as $badge ) : ?>
				<span class="taxonomy-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
			<?php endforeach; ?>
		</div>
	</section>

</article>

<!-- ===================== TWEAK PANEL ===================== -->
<aside id="ds-tweak-panel" class="ds-tweak" aria-label="Design token tweak panel" hidden>
	<div class="ds-tweak__header">
		<h2 class="ds-tweak__title">Tweak Tokens</h2>
		<button type="button" class="ds-tweak__close button-3 button-small" data-ds-action="toggle" aria-expanded="true">Hide</button>
	</div>

	<p class="ds-tweak__hint">Local-only proposals. Copy or share the link to send them back.</p>

	<div class="ds-tweak__actions">
		<button type="button" class="button-2 button-small" data-ds-action="copy">Copy values</button>
		<button type="button" class="button-1 button-small" data-ds-action="share">Copy share link</button>
		<button type="button" class="button-danger button-small" data-ds-action="reset">Reset</button>
	</div>
	<p class="ds-tweak__feedback" data-ds-feedback aria-live="polite"></p>

	<div class="ds-tweak__group">
		<h3 class="ds-tweak__group-title">Core Palette</h3>
		<?php foreach ( $ds_palette_colors as $token => $label ) : ?>
			<label class="ds-tweak__control">
				<span class="ds-tweak__control-label"><?php echo esc_html( $label ); ?></span>
				<input type="color" data-ds-control="color" data-ds-token="<?php echo esc_attr( $token ); ?>" />
				<code class="ds-tweak__control-token"><?php echo esc_html( $token ); ?></code>
			</label>
		<?php endforeach; ?>
	</div>

	<div class="ds-tweak__group">
		<h3 class="ds-tweak__group-title">Type Scale</h3>
		<?php foreach ( $ds_font_sizes as $token => $label ) : ?>
			<label class="ds-tweak__control">
				<span class="ds-tweak__control-label"><?php echo esc_html( $label ); ?></span>
				<input type="text" inputmode="decimal" class="ds-tweak__text" data-ds-control="length" data-ds-token="<?php echo esc_attr( $token ); ?>" placeholder="e.g. 1.25rem" />
				<code class="ds-tweak__control-token"><?php echo esc_html( $token ); ?></code>
			</label>
		<?php endforeach; ?>
	</div>

	<div class="ds-tweak__group">
		<h3 class="ds-tweak__group-title">Border Radius</h3>
		<?php foreach ( $ds_radii as $token => $label ) : ?>
			<label class="ds-tweak__control">
				<span class="ds-tweak__control-label"><?php echo esc_html( $label ); ?></span>
				<input type="text" inputmode="decimal" class="ds-tweak__text" data-ds-control="length" data-ds-token="<?php echo esc_attr( $token ); ?>" placeholder="e.g. 8px" />
				<code class="ds-tweak__control-token"><?php echo esc_html( $token ); ?></code>
			</label>
		<?php endforeach; ?>
	</div>
</aside>

<button type="button" id="ds-tweak-toggle" class="button-2 button-medium" data-ds-action="toggle" aria-controls="ds-tweak-panel" aria-expanded="false" hidden>Tweak Tokens</button>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>

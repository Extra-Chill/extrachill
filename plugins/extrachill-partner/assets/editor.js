(function (wp) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	['upcoming-events', 'booking-embed'].forEach(function (slug) {
		var events = slug === 'upcoming-events';
		wp.blocks.registerBlockType('extrachill-partner/' + slug, {
			title: events ? __('Upcoming Events', 'extrachill-partner') : __('Booking Embed', 'extrachill-partner'),
			icon: 'calendar',
			category: 'widgets',
			attributes: events ? { limit: { type: 'number', default: 10 } } : {},
			edit: function (props) {
				return el('div', wp.blockEditor.useBlockProps(),
					events ? el(wp.blockEditor.InspectorControls, {},
						el(wp.components.PanelBody, { title: __('Events', 'extrachill-partner') },
							el(wp.components.RangeControl, {
								label: __('Number of shows', 'extrachill-partner'),
								min: 1, max: 100, value: props.attributes.limit,
								onChange: function (limit) { props.setAttributes({ limit: limit }); }
							}))) : null,
					el(wp.serverSideRender, { block: 'extrachill-partner/' + slug, attributes: props.attributes })
				);
			},
			save: function () { return null; }
		});
	});
})(window.wp);

export const DEFAULT_BOOKING_APPEARANCE = {
	mode: 'default',
	background_color: '#121212',
	surface_color: '#1f1f1f',
	text_color: '#e5e5e5',
	accent_color: '#0b5394',
	button_text_color: '#ffffff',
	border_color: '#3a3a3a',
	button_radius: 8,
	show_logo: true,
};

export const bookingAppearanceStyle = ( appearance ) => {
	const effective =
		appearance.mode === 'default' ? DEFAULT_BOOKING_APPEARANCE : appearance;
	return {
		'--ec-booking-background': effective.background_color,
		'--ec-booking-surface': effective.surface_color,
		'--ec-booking-text': effective.text_color,
		'--ec-booking-accent': effective.accent_color,
		'--ec-booking-button-text': effective.button_text_color,
		'--ec-booking-border': effective.border_color,
		'--ec-booking-button-radius': `${ effective.button_radius }px`,
	};
};

export const bookingQrRequest = ( bookingUrl, size ) => ( {
	path: '/extrachill/v1/tools/qr-code',
	method: 'POST',
	data: { url: bookingUrl, size },
} );

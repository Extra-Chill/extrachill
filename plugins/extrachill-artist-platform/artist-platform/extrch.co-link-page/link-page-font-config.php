<?php
/**
 * Centralized font configuration for extrch.co Link Page feature.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

global $extrch_link_page_fonts;
$extrch_link_page_fonts = [
    [
        'value' => 'Helvetica',
        'label' => 'Helvetica',
        'stack' => "'Helvetica', Arial, sans-serif",
        'google_font_param' => 'local_default', // System font
    ],
    [
        'value' => 'WilcoLoftSans',
        'label' => 'Wilco Loft Sans', // Updated Label
        'stack' => "'WilcoLoftSans', Helvetica, Arial, sans-serif",
        'google_font_param' => 'local_default',
    ],
    [
        'value' => 'Roboto',
        'label' => 'Roboto',
        'stack' => "'Roboto', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Roboto:wght@400;600;700',
    ],
    [
        'value' => 'Open Sans',
        'label' => 'Open Sans',
        'stack' => "'Open Sans', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Open+Sans:wght@400;600;700',
    ],
    [
        'value' => 'Lato',
        'label' => 'Lato',
        'stack' => "'Lato', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Lato:wght@400;600;700',
    ],
    [
        'value' => 'Montserrat',
        'label' => 'Montserrat',
        'stack' => "'Montserrat', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Montserrat:wght@400;600;700',
    ],
    [
        'value' => 'Oswald',
        'label' => 'Oswald',
        'stack' => "'Oswald', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Oswald:wght@400;600;700',
    ],
    [
        'value' => 'Raleway',
        'label' => 'Raleway',
        'stack' => "'Raleway', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Raleway:wght@400;600;700',
    ],
    [
        'value' => 'Poppins',
        'label' => 'Poppins',
        'stack' => "'Poppins', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Poppins:wght@400;600;700',
    ],
    [
        'value' => 'Nunito',
        'label' => 'Nunito',
        'stack' => "'Nunito', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Nunito:wght@400;600;700',
    ],
    [
        'value' => 'Caveat',
        'label' => 'Caveat',
        'stack' => "'Caveat', Helvetica, Arial, cursive",
        'google_font_param' => 'Caveat:wght@400;600;700',
    ],
    [
        'value' => 'Space Grotesk',
        'label' => 'Space Grotesk',
        'stack' => "'Space Grotesk', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Space+Grotesk:wght@400;600;700',
    ],
    [
        'value' => 'Bebas Neue',
        'label' => 'Bebas Neue',
        'stack' => "'Bebas Neue', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Bebas+Neue:wght@400', // Bebas Neue is typically available in Regular 400
    ],
    [
        'value' => 'Inter',
        'label' => 'Inter',
        'stack' => "'Inter', Helvetica, Arial, sans-serif",
        'google_font_param' => 'Inter:wght@400;600;700',
    ],
]; 
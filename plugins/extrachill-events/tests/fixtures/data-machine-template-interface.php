<?php
/**
 * Minimal Data Machine template contract shim for isolated template tests.
 *
 * @package ExtraChillEvents\Tests
 */

namespace DataMachine\Abilities\Media;

if ( ! interface_exists( TemplateInterface::class ) ) {
	/** Template contract shim. */
	interface TemplateInterface {}
}

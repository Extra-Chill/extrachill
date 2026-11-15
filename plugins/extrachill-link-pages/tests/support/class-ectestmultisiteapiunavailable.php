<?php
/**
 * Exception for multisite-only APIs called on single site.
 *
 * @package ExtraChillLinkPages
 */

/**
 * Raised when production code reaches for a multisite-only API on single site.
 */
class EcTestMultisiteApiUnavailable extends RuntimeException {}

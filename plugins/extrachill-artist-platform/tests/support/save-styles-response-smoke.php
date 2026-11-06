<?php
/** Standalone regression: execute real ability handlers and storage, not mocks. */
$argv[1] = 'save-styles-response';
require dirname( __DIR__ ) . '/fixtures/combined-runtime-smoke.php';

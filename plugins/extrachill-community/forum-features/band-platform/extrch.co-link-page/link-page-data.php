<?php
// This file is deprecated as of v1.6 (Refactoring Plan).
// The functionality of extrch_get_link_page_data() has been consolidated into
// LivePreviewManager::get_preview_data() in config/live-preview/LivePreviewManager.php
//
// This file can be removed once all dependencies are updated.
// For now, it's kept to avoid fatal errors if something still includes it directly,
// but its primary function is no longer used by core preview/template logic.

// If you need to ensure LivePreviewManager is available when this file might be included:
// if ( ! class_exists( 'LivePreviewManager' ) ) {
//     $live_preview_manager_path = dirname( __FILE__ ) . '/config/live-preview/LivePreviewManager.php';
//     if ( file_exists( $live_preview_manager_path ) ) {
//         require_once $live_preview_manager_path;
//     }
// }
?>
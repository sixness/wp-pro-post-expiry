<?php
// Remove settings when the plugin is deleted. Expiry dates stored on posts are kept.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'wppe_settings' );
delete_option( 'wppe_legacy_migrated' );
delete_transient( 'wppe_check_lock' );
wp_clear_scheduled_hook( 'wppe_check_expired' );

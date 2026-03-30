<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Remove plugin options.
delete_option( 'easysender_settings' );
delete_option( 'easysender_error_messages' );

// Remove all plugin transients.
// Bulk deletion by name pattern requires a direct query — there is no WordPress
// API that accepts a LIKE pattern for transient keys.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_easysender_%'
        OR option_name LIKE '_transient_timeout_easysender_%'"
);

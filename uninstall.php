<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Remove plugin options.
delete_option( 'easysender_settings' );
delete_option( 'easysender_error_messages' );

// Remove all plugin transients.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_easysender_%'
        OR option_name LIKE '_transient_timeout_easysender_%'"
);

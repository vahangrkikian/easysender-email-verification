<?php
/*
Plugin Name: EasyDMARC Email Verification
Plugin URI: https://easydmarc.com/easysender
Description: Verifies emails using the EasyDMARC API across forms (Elementor, Contact Form 7, Gravity Forms, WooCommerce, WP registration).
Version: 1.1.0
Author: Vahan Grkikian
Author URI: https://easydmarc.com/
Text Domain: easydmarc-email-verification
Domain Path: /languages
Requires at least: 5.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/


if (!defined('ABSPATH')) exit;

// Core constants
define('EASYSENDER_VERSION', '1.1.0');
define('EASYSENDER_PLUGIN_FILE', __FILE__);
define('EASYSENDER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Fixed API endpoints
if (!defined('EASYSENDER_API_BASE_URL'))  define('EASYSENDER_API_BASE_URL',  'https://sender-api.easydmarc.com');
if (!defined('EASYSENDER_TOKEN_URL'))     define('EASYSENDER_TOKEN_URL',     EASYSENDER_API_BASE_URL . '/api/v0.0/auth/token');
if (!defined('EASYSENDER_REFRESH_URL'))   define('EASYSENDER_REFRESH_URL',   EASYSENDER_API_BASE_URL . '/api/v0.0/auth/refresh');
if (!defined('EASYSENDER_VERIFY_URL'))    define('EASYSENDER_VERIFY_URL',    EASYSENDER_API_BASE_URL . '/api/v0.0/verify/sync');

// Encryption helpers (WP AUTH_KEY based, with HMAC + random IV; backward-compatible with legacy values)
function easysender_encrypt($data) {
    if ($data === '' || $data === null) return '';

    $key_source = defined('AUTH_KEY') ? AUTH_KEY : 'es_fallback_key';
    $enc_key    = hash('sha256', $key_source, true);
    $mac_key    = hash('sha256', 'mac_' . $key_source, true);

    try {
        $iv = random_bytes(16);
    } catch (Throwable $e) {
        $iv = substr(hash('sha256', $key_source), 0, 16);
    }

    $cipher = openssl_encrypt($data, 'AES-256-CBC', $enc_key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return '';

    $mac = hash_hmac('sha256', $iv . $cipher, $mac_key, true);

    return 'v2:' . base64_encode($iv . $cipher . $mac);
}
function easysender_decrypt($data) {
    if ($data === '' || $data === null) return '';

    $key_source = defined('AUTH_KEY') ? AUTH_KEY : 'es_fallback_key';
    $enc_key    = hash('sha256', $key_source, true);
    $mac_key    = hash('sha256', 'mac_' . $key_source, true);

    // New-format envelope
    if (strpos($data, 'v2:') === 0) {
        $raw = base64_decode(substr($data, 3));
        if ($raw === false || strlen($raw) < 48) return '';

        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16, -32);
        $mac    = substr($raw, -32);

        $calc_mac = hash_hmac('sha256', $iv . $cipher, $mac_key, true);
        if (!hash_equals($mac, $calc_mac)) return '';

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $enc_key, OPENSSL_RAW_DATA, $iv);
        return ($plain === false) ? '' : $plain;
    }

    // Legacy deterministic format (no HMAC)
    $iv = substr(hash('sha256', $key_source), 0, 16);
    $plain = openssl_decrypt(base64_decode($data), 'AES-256-CBC', $key_source, 0, $iv);
    return ($plain === false) ? '' : $plain;
}

// Make activation show readable errors instead of white screen.
register_activation_hook(EASYSENDER_PLUGIN_FILE, function () {
    // PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        wp_die('EasyDMARC requires PHP 7.4+. Current: ' . PHP_VERSION);
    }
    // OpenSSL check (for encrypt/decrypt)
    if (!function_exists('openssl_encrypt')) {
        wp_die('EasyDMARC requires the OpenSSL PHP extension.');
    }
    // Files exist?
    $required = [
        EASYSENDER_PLUGIN_DIR . 'includes/logging.php',
        EASYSENDER_PLUGIN_DIR . 'includes/email-check.php',
        EASYSENDER_PLUGIN_DIR . 'admin-settings.php',
    ];
    foreach ($required as $file) {
        if (!is_readable($file)) {
            wp_die('EasyDMARC missing file: ' . esc_html(basename($file)));
        }
    }
});

// Lazy-load everything after plugins_loaded to avoid early fatals
add_action('plugins_loaded', function () {
    // Logger first and once
    require_once EASYSENDER_PLUGIN_DIR . 'includes/logging.php';

    // Core helpers (shared by admin and all form handlers)
    require_once EASYSENDER_PLUGIN_DIR . 'token-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'includes/email-check.php';
    require_once EASYSENDER_PLUGIN_DIR . 'includes/csv-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'includes/plans-data.php';

    // Admin UI & AJAX
    require_once EASYSENDER_PLUGIN_DIR . 'admin-settings.php';

    // Form integration handlers
    require_once EASYSENDER_PLUGIN_DIR . 'elementor-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'contact-form-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'wpforms-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'ninjaforms-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'fluentforms-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'gravityforms-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'sureforms-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'woocommerce-handler.php';
    require_once EASYSENDER_PLUGIN_DIR . 'wp-registration-handler.php';
});

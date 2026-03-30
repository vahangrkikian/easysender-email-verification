<?php
if (!defined('ABSPATH')) exit;

/**
 * Elementor form email verification handler.
 * Validates email via EasyDMARC before Elementor saves/sends the submission.
 */

// Initialize a per-request flag
add_action('init', function () {
    $GLOBALS['easysender_email_verified'] = false;
}, 1);

// Register validation only (prevents double-calls)
add_action('elementor_pro/init', function () {
    $opts    = get_option('easysender_settings', []);
    $enabled = !empty($opts['enable_elementor']) && $opts['enable_elementor'] === '1';
    if (!$enabled) return;

    add_action('elementor_pro/forms/validation', 'easysender_elementor_validate_email', 10, 2);
});

// Hide Elementor's empty danger message bar when we already show field-level errors
add_action('wp_head', function () {
    $opts    = get_option('easysender_settings', []);
    $enabled = !empty($opts['enable_elementor']) && $opts['enable_elementor'] === '1';
    if (!$enabled) return;
    ?>
    <style>
        .elementor-form .elementor-message.elementor-message-danger:empty,
        .elementor-form .elementor-message.elementor-message-danger:empty::before {
            display: none !important;
        }
    </style>
    <?php
}, 100);

/**
 * Send a validation error response without Elementor's default generic message.
 */
function easysender_elementor_send_validation_error($ajax_handler, $field_id, $message) {
    $field = $field_id ?: 'email';
    $ajax_handler->add_error($field, $message);

    wp_send_json_error([
        'message' => '',
        'errors'  => $ajax_handler->errors,
        'data'    => $ajax_handler->data,
    ]);
}

/**
 * Validate email with EasyDMARC during Elementor validation.
 *
 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $record
 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
 */
function easysender_elementor_validate_email($record, $ajax_handler) {
    $GLOBALS['easysender_email_verified'] = false;

    $raw_fields = $record->get('fields');
    if (empty($raw_fields) || !is_array($raw_fields)) {
        easysender_elementor_send_validation_error($ajax_handler, '__all__', 'Form error: missing fields.');
    }

    // Customizable error messages from settings
    $error_opts  = get_option('easysender_error_messages', []);
    $msg_invalid = !empty($error_opts['msg_invalid'])   ? $error_opts['msg_invalid']   : __('Please enter a valid email address.', 'easysender-email-verification');
    $msg_risky   = !empty($error_opts['msg_risky'])     ? $error_opts['msg_risky']     : __('Risky email address.', 'easysender-email-verification');
    $msg_api     = !empty($error_opts['msg_api_error']) ? $error_opts['msg_api_error'] : __('Verification error. Please try again.', 'easysender-email-verification');

    // Find the email field/value robustly
    $email_value    = '';
    $email_field_id = null;

    // Prefer by field type=Email
    foreach ($raw_fields as $id => $field) {
        $type = strtolower($field['type'] ?? '');
        if ($type === 'email') {
            $email_value    = (string) ($field['value'] ?? '');
            $email_field_id = $id;
            break;
        }
    }
    // Fallback by common IDs if not found
    if ($email_value === '') {
        foreach (['email', 'your-email', 'user_email', 'user-email'] as $cid) {
            if (!empty($raw_fields[$cid]['value'])) {
                $email_value    = (string) $raw_fields[$cid]['value'];
                $email_field_id = $cid;
                break;
            }
        }
    }

    if ($email_value === '') {
        easysender_elementor_send_validation_error($ajax_handler, 'email', 'Email field is missing.');
    }

    // Prefer centralized helper so Elementor shares messages with other integrations
    if (function_exists('easysender_do_email_check')) {
        $check = easysender_do_email_check($email_value);

        if (!empty($check['ok'])) {
            $GLOBALS['easysender_email_verified'] = true;
            return;
        }

        $status = strtolower($check['status'] ?? '');
        $reason = isset($check['reason']) ? (string) $check['reason'] : '';

        if ($reason === '' && $status !== '') {
            $reason = ($status === 'risky') ? $msg_risky : $msg_invalid;
        }
        if ($reason === '') {
            $reason = $msg_api;
        }

        easysender_elementor_send_validation_error($ajax_handler, $email_field_id, $reason);
    }

    // easysender_do_email_check unavailable — allow through silently.
    $GLOBALS['easysender_email_verified'] = true;
}

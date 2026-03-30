<?php
/**
 * SureForms handler for EasySender Email Verification
 *
 * Validates email fields in SureForms submissions using easysender_do_email_check().
 *
 * Hook used: srfm_validate_form_data (filter, introduced in SureForms ~1.x)
 * Fires once per field during the validation pass, before the entry is saved.
 * Only fields whose key contains '-lbl-' are processed by SureForms.
 * Returning ['validated' => false, 'error' => '...'] blocks the submission and
 * shows the message beneath the email field.
 *
 * Field type identifier: $data['block_slug'] === 'email'
 * Plugin detection:      class_exists('\SRFM\Plugin_Loader')
 * Plugin slug:           sureforms/sureforms.php
 *
 * Requires: SureForms 1.x+, PHP 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_sureforms_is_enabled' ) ) {
    /**
     * Check whether SureForms integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_sureforms_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_sureforms'] ) && $opts['enable_sureforms'] === '1' );
    }
}

if ( ! function_exists( 'easysender_sureforms_validate_field' ) ) {
    /**
     * Validate a field in a SureForms submission.
     *
     * Called by the filter: srfm_validate_form_data
     *
     * @param array $data {
     *     Field data passed by SureForms.
     *
     *     @type string     $field_key    Full field key, e.g. 'srfm-email-c867d9d9-lbl-email'.
     *     @type mixed      $field_value  Submitted field value.
     *     @type int|mixed  $form_id      Form post ID.
     *     @type array|null $form_config  Block configuration metadata.
     *     @type string     $block_id     Unique block identifier, e.g. 'c867d9d9'.
     *     @type string     $block_slug   Field type slug, e.g. 'email'.
     *     @type string     $name_with_id Field name without label part, e.g. 'srfm-email-c867d9d9'.
     *     @type string     $field_name   Base field type name, e.g. 'srfm-email'.
     * }
     *
     * @return array {
     *     Validation result consumed by SureForms.
     *
     *     @type bool   $validated true = valid, false = invalid.
     *     @type string $error     Error message shown beneath the field (only when validated is false).
     * }
     */
    function easysender_sureforms_validate_field( $data ) {
        // Only process email-type fields.
        if ( ! isset( $data['block_slug'] ) || $data['block_slug'] !== 'email' ) {
            return $data; // Not an email field — return untouched so SureForms skips it.
        }

        // Extract and normalise the submitted email value.
        $email = isset( $data['field_value'] ) ? strtolower( trim( sanitize_email( (string) $data['field_value'] ) ) ) : '';

        // Skip empty fields — SureForms' own required rule handles those.
        if ( $email === '' ) {
            return $data;
        }

        // Customizable error messages from settings.
        $error_opts  = get_option( 'easysender_error_messages', [] );
        $msg_invalid = ! empty( $error_opts['msg_invalid'] )
                       ? $error_opts['msg_invalid']
                       : __( 'Please enter a valid email address.', 'easysender-email-verification' );
        $msg_risky   = ! empty( $error_opts['msg_risky'] )
                       ? $error_opts['msg_risky']
                       : __( 'Risky email address.', 'easysender-email-verification' );
        $msg_api     = ! empty( $error_opts['msg_api_error'] )
                       ? $error_opts['msg_api_error']
                       : __( 'Verification error. Please try again.', 'easysender-email-verification' );

        // Fallback: if main helper is missing, do basic format check only.
        if ( ! function_exists( 'easysender_do_email_check' ) ) {
            if ( ! is_email( $email ) ) {
                return [
                    'validated' => false,
                    'error'     => $msg_invalid,
                ];
            }
            return $data;
        }

        try {
            $check = easysender_do_email_check( $email );
        } catch ( Exception $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'sureforms', 0, $e->getMessage() );
            }
            // On unexpected exception, allow through to avoid blocking legitimate submissions.
            return $data;
        } catch ( Error $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'sureforms', 0, $e->getMessage() );
            }
            return $data;
        }

        if ( empty( $check['ok'] ) ) {
            $status = isset( $check['status'] ) ? strtolower( (string) $check['status'] ) : '';
            $reason = ! empty( $check['reason'] ) ? (string) $check['reason'] : '';

            if ( $reason === '' ) {
                if ( $status === 'risky' ) {
                    $reason = $msg_risky;
                } elseif ( $status !== '' ) {
                    // translators: %s: Verification status returned by the API.
                    $reason = sprintf(
                        __( 'This email is marked as %s and is not allowed by the current rules.', 'easysender-email-verification' ),
                        $status
                    );
                } else {
                    $reason = $msg_api;
                }
            }

            // Returning validated = false blocks the submission.
            // SureForms shows $error beneath the field and sends HTTP 400.
            return [
                'validated' => false,
                'error'     => $reason,
            ];
        }

        // Email passed — return with validated = true so SureForms skips further checks.
        return [
            'validated' => true,
            'error'     => '',
        ];
    }
}

add_action(
    'init',
    function () {
        // Only register hook when integration is enabled and SureForms is active.
        if ( ! easysender_sureforms_is_enabled() ) {
            return;
        }

        // SRFM\Plugin_Loader is SureForms' main class — present in all versions.
        if ( ! class_exists( '\SRFM\Plugin_Loader' ) ) {
            return;
        }

        // srfm_validate_form_data — filter, fires per field during validation.
        // 1 parameter: $data array (field_key, field_value, form_id, form_config,
        //              block_id, block_slug, name_with_id, field_name).
        // Return ['validated' => bool, 'error' => string] to set validation result,
        // or return $data unchanged to let SureForms handle it normally.
        add_filter(
            'srfm_validate_form_data',
            'easysender_sureforms_validate_field',
            10,
            1
        );
    }
);

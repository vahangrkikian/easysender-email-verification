<?php
/**
 * Gravity Forms handler for EasySender Email Verification
 *
 * Validates email fields in Gravity Forms submissions using easysender_do_email_check().
 *
 * Hook used: gform_field_validation (filter, stable since GF 1.x)
 * Fires once per field during the validation pass, before the entry is saved.
 * Returning ['is_valid' => false, 'message' => '...'] blocks the submission and
 * shows the message beneath the email field.
 *
 * Requires: Gravity Forms 2.5+, PHP 7.4+
 * Detected via: class_exists('GFForms')
 * Plugin slug:  gravityforms/gravityforms.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_gravityforms_is_enabled' ) ) {
    /**
     * Check whether Gravity Forms integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_gravityforms_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_gravityforms'] ) && $opts['enable_gravityforms'] === '1' );
    }
}

if ( ! function_exists( 'easysender_gravityforms_validate_email' ) ) {
    /**
     * Validate an email field in a Gravity Forms submission.
     *
     * Called by the filter: gform_field_validation
     *
     * @param array    $result  Validation result: ['is_valid' => bool, 'message' => string].
     * @param mixed    $value   Submitted field value (string for email fields).
     * @param array    $form    Form array: $form['id'], $form['fields'], $form['title'], etc.
     * @param GF_Field $field   Field object: $field->type, $field->id, $field->label, etc.
     *
     * @return array Modified $result array.
     */
    function easysender_gravityforms_validate_email( $result, $value, $form, $field ) {
        // Only process email-type fields.
        if ( ! isset( $field->type ) || $field->type !== 'email' ) {
            return $result;
        }

        // If Gravity Forms' own validation already failed (e.g. bad format),
        // don't make an unnecessary API call — just return the existing error.
        if ( empty( $result['is_valid'] ) ) {
            return $result;
        }

        // Gravity Forms passes the primary email as a string.
        // Handle array defensively (e.g. email + confirmation field combined).
        $email = is_array( $value ) ? (string) reset( $value ) : (string) $value;
        $email = strtolower( trim( sanitize_email( $email ) ) );

        // Skip empty fields — GF's own required rule handles those.
        if ( $email === '' ) {
            return $result;
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
                $result['is_valid'] = false;
                $result['message']  = $msg_invalid;
            }
            return $result;
        }

        try {
            $check = easysender_do_email_check( $email );
        } catch ( Exception $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'gravityforms', 0, $e->getMessage() );
            }
            // On unexpected exception, allow through to avoid blocking legitimate submissions.
            return $result;
        } catch ( Error $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'gravityforms', 0, $e->getMessage() );
            }
            return $result;
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

            // Setting is_valid to false blocks the submission and shows
            // $message beneath the field. Gravity Forms handles all the
            // JSON response and scroll-to-field behaviour automatically.
            $result['is_valid'] = false;
            $result['message']  = $reason;
        }

        return $result;
    }
}

add_action(
    'init',
    function () {
        // Only register hook when integration is enabled and Gravity Forms is active.
        if ( ! easysender_gravityforms_is_enabled() ) {
            return;
        }

        // GFForms is Gravity Forms' main class — present in all versions.
        if ( ! class_exists( 'GFForms' ) ) {
            return;
        }

        // gform_field_validation — filter, fires per field during validation.
        // 4 parameters: $result, $value, $form, $field.
        // Stable since Gravity Forms 1.x, documented at:
        // https://docs.gravityforms.com/gform_field_validation/
        add_filter(
            'gform_field_validation',
            'easysender_gravityforms_validate_email',
            10,
            4
        );
    }
);

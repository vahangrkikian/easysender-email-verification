<?php
/**
 * Fluent Forms handler for EasySender Email Verification
 *
 * Validates email fields in Fluent Forms submissions using easysender_do_email_check().
 *
 * Hook used: fluentform/validate_input_item_input_email (filter, introduced in FF 4.3.22)
 * Fires once per email-type field during the validation pass, before submission is saved.
 * Returning a non-empty string blocks the submission and shows the message under the field.
 *
 * Field type identifier: $field['element'] === 'input_email'
 * The hook name already scopes to email fields, so no extra type check is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'easysender_fluentforms_is_enabled' ) ) {
    /**
     * Check whether Fluent Forms integration is enabled in plugin settings.
     *
     * @return bool
     */
    function easysender_fluentforms_is_enabled() {
        $opts = get_option( 'easysender_settings', [] );
        return ( ! empty( $opts['enable_fluentforms'] ) && $opts['enable_fluentforms'] === '1' );
    }
}

if ( ! function_exists( 'easysender_fluentforms_validate_email_field' ) ) {
    /**
     * Validate an email field in a Fluent Forms submission.
     *
     * Called by the filter: fluentform/validate_input_item_input_email
     *
     * @param string|array $error    Current error value — empty string '' when no error yet.
     * @param array        $field    Parsed field definition: 'name', 'element', 'rules', 'raw'.
     * @param array        $formData All submitted form values keyed by field name (input name attr).
     * @param array        $fields   All parsed fields in this form.
     * @param \stdClass    $form     Form object: ->id, ->form_fields (JSON), ->type.
     * @param array        $errors   Accumulated errors from previous rules/fields.
     *
     * @return string|array Non-empty = error (blocks submission). Empty string = no error.
     */
    function easysender_fluentforms_validate_email_field( $error, $field, $formData, $fields, $form, $errors ) {
        if ( ! easysender_fluentforms_is_enabled() ) {
            return $error;
        }

        // $field['name'] is the HTML input name attribute and the $formData array key.
        $input_name  = isset( $field['name'] ) ? (string) $field['name'] : '';
        $email_value = ( $input_name !== '' && isset( $formData[ $input_name ] ) )
                       ? (string) $formData[ $input_name ]
                       : '';

        // Skip empty fields — Fluent Forms' own required/email rules handle those.
        if ( $email_value === '' ) {
            return $error;
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
            if ( ! is_email( $email_value ) ) {
                return $msg_invalid;
            }
            return $error;
        }

        try {
            $check = easysender_do_email_check( $email_value );
        } catch ( Exception $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'fluentforms', 0, $e->getMessage() );
            }
            return $error;
        } catch ( Error $e ) {
            if ( function_exists( 'easysender_log_api_error' ) ) {
                easysender_log_api_error( 'fluentforms', 0, $e->getMessage() );
            }
            return $error;
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

            // Returning a non-empty string causes Fluent Forms to:
            // 1. Add this message as a field-level error under the email input.
            // 2. Throw a ValidationException (HTTP 423).
            // 3. Never save or process the submission.
            return $reason;
        }

        return $error; // Empty string → no error, submission proceeds normally.
    }
}

add_action(
    'init',
    function () {
        // Only register the hook when integration is enabled and Fluent Forms is present.
        if ( ! easysender_fluentforms_is_enabled() ) {
            return;
        }

        // wpFluentForm() is Fluent Forms' global bootstrap function — present in both free and Pro.
        if ( ! function_exists( 'wpFluentForm' ) ) {
            return;
        }

        // fluentform/validate_input_item_input_email — filter, fires per email field during validation.
        // 6 accepted parameters: $error, $field, $formData, $fields, $form, $errors.
        // Hook introduced in Fluent Forms 4.3.22 (replaces deprecated fluentform_validate_input_item_input_email).
        add_filter(
            'fluentform/validate_input_item_input_email',
            'easysender_fluentforms_validate_email_field',
            10,
            6
        );
    }
);

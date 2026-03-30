<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('easysender_log_api_error')) {
    /**
     * Centralized API error logger for EasySender.
     * Redacts email-like data before writing to WordPress debug log.
     *
     * Logs are written to wp-content/debug.log when WP_DEBUG_LOG is enabled.
     *
     * @param string $endpoint Endpoint identifier (e.g., 'verify', 'token', 'usage').
     * @param mixed  $response_or_error WP_Error, HTTP response array, or HTTP code.
     * @param mixed  $extra Additional context data to log.
     * @return void
     */
    function easysender_log_api_error(string $endpoint, $response_or_error, $extra = []) : void {
        // Only log if WordPress debug logging is enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }

        $scrub = static function ($value) use (&$scrub) {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $lk = strtolower((string) $k);
                    if (in_array($lk, ['payload', 'email', 'emails', 'emailaddresses'], true)) {
                        $out[$k] = '[redacted]';
                        continue;
                    }
                    $out[$k] = $scrub($v);
                }
                return $out;
            }
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return '[redacted_email]';
            }
            if (is_string($value)) {
                return preg_replace('/[\\w.+-]+@[\\w.-]+/', '[redacted_email]', $value);
            }
            return $value;
        };

        $extra_str = '';
        if (is_array($extra)) {
            $json = wp_json_encode($scrub($extra));
            $extra_str = $json ? (' ' . (strlen($json) > 400 ? substr($json, 0, 400) . '…' : $json)) : '';
        } elseif (is_string($extra) && $extra !== '') {
            $safe = $scrub($extra);
            $extra_str = ' ' . (strlen($safe) > 400 ? substr($safe, 0, 400) . '…' : $safe);
        }

        $log_message = '';

        if (is_wp_error($response_or_error)) {
            $log_message = "[EasySender] API {$endpoint} WP_Error: {$response_or_error->get_error_code()} {$response_or_error->get_error_message()}{$extra_str}";
        } elseif (is_numeric($response_or_error)) {
            $log_message = "[EasySender] API {$endpoint} HTTP {$response_or_error}{$extra_str}";
        } else {
            // Handle HTTP response array - could contain WP_Error or valid response
            $code = function_exists('wp_remote_retrieve_response_code') ? wp_remote_retrieve_response_code($response_or_error) : 0;

            // Check if the response is actually a WP_Error (happens with transport errors)
            if (is_wp_error($response_or_error)) {
                $log_message = "[EasySender] API {$endpoint} WP_Error: {$response_or_error->get_error_code()} {$response_or_error->get_error_message()}{$extra_str}";
            } else {
                $body = function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response_or_error) : '';

                $msg = '';
                if (is_string($body) && $body !== '') {
                    $json = json_decode($body, true);
                    if (is_array($json) && isset($json['message'])) {
                        $msg = (string) $json['message'];
                    } elseif (is_string($body)) {
                        $msg = substr($body, 0, 240);
                    }
                }
                if ($msg) {
                    $msg = preg_replace('/[\\w.+-]+@[\\w.-]+/', '[redacted_email]', $msg);
                }

                // If HTTP code is 0 and body is empty, check for transport errors
                if ($code === 0 && empty($body)) {
                    // Try to extract error from response array
                    if (is_array($response_or_error)) {
                        if (isset($response_or_error['response']['code'])) {
                            $code = $response_or_error['response']['code'];
                        }
                        if (isset($response_or_error['response']['message'])) {
                            $msg = $response_or_error['response']['message'];
                        }
                        // Check for errors array (common in wp_remote_* failures)
                        if (isset($response_or_error['errors']) && is_array($response_or_error['errors'])) {
                            $errors = [];
                            foreach ($response_or_error['errors'] as $err_key => $err_msgs) {
                                $errors[] = $err_key . ': ' . implode(', ', (array) $err_msgs);
                            }
                            $msg = implode('; ', $errors);
                        }
                    }
                }

                $tail = $msg ? " msg: {$msg}" : '';
                $log_message = "[EasySender] API {$endpoint} HTTP {$code}{$tail}{$extra_str}";
            }
        }

        // Use WordPress error_log wrapper which respects WP_DEBUG_LOG
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging for debugging when WP_DEBUG_LOG is enabled
        error_log($log_message);
    }
}
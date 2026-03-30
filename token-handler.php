<?php
/**
 * Token helper for EasySender
 * - Single source of truth for fetching/caching token
 * - Safe to include multiple times thanks to function_exists guard
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('easysender_get_access_token')) {
    /**
     * Get EasyDMARC access token (cached with transient).
     *
     * @param bool $force_refresh When true, bypass cache and fetch a new token.
     * @return string|false|\WP_Error Access token string on success, false or WP_Error on failure.
     */
    function easysender_get_access_token($force_refresh = false) {
        // Ensure constants exist
        if (!defined('EASYSENDER_TOKEN_URL')) {
            return new WP_Error('easysender_missing_constant', 'EASYSENDER_TOKEN_URL is not defined.');
        }

        $options = get_option('easysender_settings');
        if (!$options || !is_array($options)) {
            return new WP_Error('easysender_no_settings', 'Settings not found.');
        }

        $client_id     = !empty($options['client_id'])     ? easysender_decrypt($options['client_id'])     : '';
        $client_secret = !empty($options['client_secret']) ? easysender_decrypt($options['client_secret']) : '';

        if (!$client_id || !$client_secret) {
            return new WP_Error('easysender_missing_creds', 'Missing Client ID or Client Secret.');
        }

        // Cache key scoped by client id hash
        $transient_key = 'easysender_access_token_' . substr(md5($client_id), 0, 10);

        if (!$force_refresh) {
            $cached = get_transient($transient_key);
            if (!empty($cached) && is_string($cached)) {
                return $cached;
            }
        }

        // Build token request
        $body = [
            'grant_type' => 'password',
            'client_id'  => 'customer-api-console',
            'username'   => $client_id,
            'password'   => $client_secret,
        ];

        $response = wp_remote_post(EASYSENDER_TOKEN_URL, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => $body, // wp_remote_post handles array for form-encoded
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && !empty($json['access_token'])) {
            // Add a safety buffer so we refresh before expiry
            $ttl = !empty($json['expires_in']) ? max(((int)$json['expires_in']) - 60, 60) : 600;
            set_transient($transient_key, $json['access_token'], $ttl);
            return $json['access_token'];
        }

        $msg = !empty($json['message']) ? $json['message'] : 'Token request failed.';
        return new WP_Error('easysender_token_error', $msg, ['status' => $code, 'response' => $json]);
    }
}

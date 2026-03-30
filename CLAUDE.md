# EasySender Email Verification — Plugin Reference

**Version:** 1.0.0 | **Author:** Vahan Grkikian / EasyDMARC | **License:** GPLv2+
**Requires:** WordPress 5.8+, PHP 7.4+, OpenSSL extension

---

## What This Plugin Does

EasySender Email Verification integrates with EasyDMARC's EasySender API to validate email addresses in real time when users submit WordPress forms. It blocks submissions containing invalid, risky, undeliverable, or unknown emails based on admin-configured rules.

**Supported form plugins:** Elementor Pro Forms, Contact Form 7 (CF7), WPForms, Ninja Forms, Fluent Forms (free + Pro), Gravity Forms, SureForms.
**Planned (not yet implemented):** WooCommerce Checkout, WordPress Registration Form.

---

## File Structure

```
easysender-email-verification/
│
├── easysender-email-verification.php   — Main bootstrap: constants, encryption helpers,
│                                         activation hook, plugins_loaded loader
├── admin-settings.php                  — Admin UI: menus, settings fields, AJAX handlers,
│                                         tab renderers, low-balance notice, logo overlay
├── token-handler.php                   — OAuth2 token fetch + transient caching
├── elementor-handler.php               — Elementor Pro Forms integration
├── contact-form-handler.php            — Contact Form 7 integration
├── wpforms-handler.php                 — WPForms integration
├── ninjaforms-handler.php              — Ninja Forms integration
├── fluentforms-handler.php             — Fluent Forms (free + Pro) integration
├── gravityforms-handler.php            — Gravity Forms integration
├── sureforms-handler.php               — SureForms integration
│
├── includes/
│   ├── email-check.php                 — Core easysender_do_email_check() + URL resolver
│   ├── logging.php                     — Centralized error logger with email redaction
│   ├── api.php                         — Placeholder (formerly dead code, now empty)
│   └── constants.php                   — Empty placeholder (unused)
│
├── assets/
│   ├── css/admin.css                   — All admin panel CSS (test card, usage, logo overlay)
│   └── js/
│       ├── admin-api.js                — Verify API Key button JS
│       ├── admin-test.js               — Test Email tab JS
│       └── admin-usage.js              — Usage tab auto-fetch JS
│
├── uninstall.php                       — Deletes all options and transients on plugin deletion
└── README.txt                          — WordPress.org-style readme
```

**Load order (plugins_loaded):**
1. `includes/logging.php`
2. `token-handler.php`
3. `includes/email-check.php`
4. `admin-settings.php`
5. `elementor-handler.php`
6. `contact-form-handler.php`
7. `wpforms-handler.php`
8. `ninjaforms-handler.php`
9. `fluentforms-handler.php`

---

## Constants

All defined in `easysender-email-verification.php` with `if (!defined(...))` guards:

| Constant | Value |
|----------|-------|
| `EASYSENDER_PLUGIN_FILE` | `__FILE__` of main plugin file |
| `EASYSENDER_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` |
| `EASYSENDER_API_BASE_URL` | `https://sender-api.easydmarc.com` |
| `EASYSENDER_TOKEN_URL` | `.../api/v0.0/auth/token` |
| `EASYSENDER_REFRESH_URL` | `.../api/v0.0/auth/refresh` |
| `EASYSENDER_VERIFY_URL` | `.../api/v0.0/verify/sync` |
| `EASYSENDER_USAGE_URL` | `.../api/v0.0/credit/stats` (defined in admin-settings.php) |
| `EASYSENDER_API_BASE` | `https://sender-api.easydmarc.com` (defined in admin-settings.php) |

---

## WordPress Options

### `easysender_settings` (array)

Stored via WordPress Settings API, group: `easysender_settings_group`.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `client_id` | string (encrypted) | — | EasyDMARC API Client ID |
| `client_secret` | string (encrypted) | — | EasyDMARC API Client Secret / API Key |
| `allow_deliverable` | '0'/'1' | '1' | Accept deliverable emails |
| `allow_risky` | '0'/'1' | '1' | Accept risky emails |
| `allow_undeliverable` | '0'/'1' | '0' | Accept undeliverable emails |
| `allow_unknown` | '0'/'1' | '0' | Accept unknown-status emails |
| `allow_on_api_error` | '0'/'1' | '0' | Allow form submission when API is unavailable |
| `enable_cf7` | '0'/'1' | '0' | Enable Contact Form 7 integration |
| `enable_elementor` | '0'/'1' | '0' | Enable Elementor Pro Forms integration |
| `enable_wpforms` | '0'/'1' | '0' | Enable WPForms integration |
| `enable_ninjaforms` | '0'/'1' | '0' | Enable Ninja Forms integration |
| `enable_fluentforms` | '0'/'1' | '0' | Enable Fluent Forms integration |
| `enable_gravityforms` | '0'/'1' | '0' | Enable Gravity Forms integration |
| `enable_sureforms` | '0'/'1' | '0' | Enable SureForms integration |
| `enable_woocommerce` | '0'/'1' | '0' | Always forced to '0' (not implemented) |
| `enable_wp_registration` | '0'/'1' | '0' | Always forced to '0' (not implemented) |
| `verify_url` | string | — | Legacy override for verify endpoint (optional) |

### `easysender_error_messages` (array)

Stored via WordPress Settings API, group: `easysender_error_group`.

| Key | Default (when empty) | Description |
|-----|---------------------|-------------|
| `msg_invalid` | `'Invalid email address.'` | Shown when email fails format check or is blocked |
| `msg_risky` | `'Risky email address.'` | Shown when email status is risky |
| `msg_api_error` | `'Verification error. Please try again.'` | Shown on API failures |

---

## Transients

| Transient Key | TTL | Content |
|--------------|-----|---------|
| `easysender_access_token_[md5(client_id):10]` | `expires_in - 60` seconds (min 60s) | OAuth access token string |
| `easysender_last_checked_[md5(email)]` | 8 seconds | Full result array from `easysender_do_email_check()` |
| `easysender_usage_cache` | 5 minutes | Usage stats array: `allocated`, `balance`, `spent`, `pct`, `quota` |

**Uninstall cleanup:** All `_transient_easysender_*` and `_transient_timeout_easysender_*` rows are deleted from `wp_options` when the plugin is deleted.

---

## API Integration

### Authentication — POST `/api/v0.0/auth/token`

OAuth2 password grant flow.

**Request body (form-encoded):**
```
grant_type=password
client_id=customer-api-console
username=[client_id decrypted]
password=[client_secret decrypted]
```

**Success response:** JSON with `access_token` (string) and `expires_in` (int seconds).
**Failure response:** JSON with `message`, `error_description`, or `error` fields.

### Email Verification — POST `/api/v0.0/verify/sync`

**Request headers:** `Authorization: Bearer [token]`, `Content-Type: application/json`
**Request body:** `{"emailAddresses": ["user@example.com"]}`

**Response shapes supported (plugin checks all):**
- `results.items[0].result`
- `items[0].status`
- `status`
- `results[0].status`
- `[0].status`

**HTTP codes handled:**
| Code | Meaning | Action |
|------|---------|--------|
| 200–299 | Success | Parse result status |
| 401 | Token expired | Refresh token once, retry |
| 402 | Credit limit | Block or allow per `allow_on_api_error` |
| 408 + requestId | Timeout | Block or allow per `allow_on_api_error` |
| Other | API error | Log raw message, show generic user message |

**Verification statuses returned by API:**
- `deliverable` — Valid, reachable mailbox
- `risky` — Email exists but may bounce (spam traps, role addresses, etc.)
- `undeliverable` — Invalid or non-existent mailbox
- `unknown` — API could not determine status

**Internal plugin statuses (not from API):**
- `invalid_format` — Email failed PHP `is_email()` check before API call
- `auth_error` — Token could not be acquired
- `config_error` — Verify URL not set
- `api_error` — HTTP transport error
- `timeout` — 408 timeout
- `quota` — 402 credit exhausted
- `allowed_on_error` — API unavailable but `allow_on_api_error = '1'`

### Usage / Credit Stats — GET `/api/v0.0/credit/stats`

**Request headers:** `Authorization: Bearer [token]`, `Accept: application/json`
**Response fields used:** `allocated` (int), `balance` (int), `spent` (int).

---

## Core Functions

### `easysender_do_email_check($raw_email)` — `includes/email-check.php`

The single verification entry point used by all four form integrations.

**Flow:**
1. Sanitize and lowercase the email; run `is_email()` — return `invalid_format` immediately if fails (no API call)
2. Check 8-second dupe transient — return cached result if hit
3. Get access token via `easysender_get_access_token(false)` — return `auth_error` on failure
4. Resolve verify URL via `easysender_get_verify_url()`
5. POST to verify endpoint with 25-second timeout
6. If 401 → refresh token and retry once
7. Handle 402 (quota) and 408+requestId (timeout) as soft errors
8. For non-2xx: log raw API message, return generic `msg_api_error` to user
9. Parse result status from response body
10. Compare against `allow_*` settings; return `ok: true` if status is in allowed list
11. Cache result in 8-second transient
12. Return `ok: false` with appropriate `reason` (user-facing message from settings)

**Return value:**
```php
[
  'ok'      => bool,
  'status'  => string,       // e.g. 'deliverable', 'risky', 'api_error', etc.
  'reason'  => string,       // user-facing message (only when ok=false)
  'details' => array,        // raw API response body (for admin test tab)
]
```

If `allow_on_api_error = '1'` and an infrastructure error occurs, returns:
```php
['ok' => true, 'status' => 'allowed_on_error', 'details' => $original_error_array]
```

### `easysender_get_access_token($force_refresh = false)` — `token-handler.php`

Returns cached token string, or fetches a new one.
Returns `WP_Error` on failure. Always call `is_wp_error()` on the result.

### `easysender_encrypt($data)` / `easysender_decrypt($data)` — main plugin file

AES-256-CBC encryption with a random 16-byte IV and HMAC-SHA256 integrity check.
Key material derived from WordPress `AUTH_KEY` constant (falls back to `'es_fallback_key'`).
Encrypted format: `v2:[base64(IV . ciphertext . HMAC)]`
Backward-compatible with legacy deterministic format (no `v2:` prefix, no HMAC).

### `easysender_log_api_error($endpoint, $response_or_error, $extra)` — `includes/logging.php`

Writes to WordPress debug log only when both `WP_DEBUG` and `WP_DEBUG_LOG` are `true`.
**Automatically redacts** email addresses from all logged strings and arrays.
Truncates log entries at 400 characters.

---

## WordPress Hooks

### Actions registered by the plugin

| Hook | Priority | File | Callback |
|------|----------|------|----------|
| `plugins_loaded` | default | main | Loads all plugin files |
| `admin_enqueue_scripts` | default | admin-settings.php | `easysender_admin_enqueue_scripts` |
| `admin_menu` | default | admin-settings.php | `easysender_add_admin_menu` |
| `admin_init` | default | admin-settings.php | `easysender_settings_init` |
| `admin_notices` | default | admin-settings.php | `easysender_low_balance_notice` |
| `admin_footer` | default | admin-settings.php | `easysender_add_logo_overlay` |
| `wp_ajax_easysender_verify_api_key` | — | admin-settings.php | `easysender_verify_api_key` |
| `wp_ajax_easysender_test_email` | — | admin-settings.php | `easysender_test_email` |
| `wp_ajax_easysender_get_usage` | — | admin-settings.php | `easysender_get_usage` |
| `init` (priority 1) | 1 | elementor-handler.php | Sets `$GLOBALS['easysender_email_verified'] = false` |
| `elementor_pro/init` | default | elementor-handler.php | Conditionally registers `elementor_pro/forms/validation` |
| `wp_head` | 100 | elementor-handler.php | Injects CSS to hide empty Elementor error bar |
| `init` | default | contact-form-handler.php | Registers CF7 validation filters if enabled |
| `init` | default | wpforms-handler.php | Registers `wpforms_process` action if enabled |
| `init` | default | ninjaforms-handler.php | Registers `ninja_forms_submit_data` filter if enabled |

### Filters registered by the plugin

| Hook | Priority | File | Callback |
|------|----------|------|----------|
| `elementor_pro/forms/validation` | 10 | elementor-handler.php | `easysender_elementor_validate_email` |
| `wpcf7_validate_email*` | 10 | contact-form-handler.php | CF7 required email validation |
| `wpcf7_validate_email` | 10 | contact-form-handler.php | CF7 optional email validation |
| `wpforms_process` | 10 | wpforms-handler.php | `easysender_wpforms_validate` |
| `ninja_forms_submit_data` | 10 | ninjaforms-handler.php | `easysender_ninjaforms_validate` |
| `fluentform/validate_input_item_input_email` | 10 | fluentforms-handler.php | `easysender_fluentforms_validate_email_field` |
| `gform_field_validation` | 10 | gravityforms-handler.php | `easysender_gravityforms_validate_email` |
| `srfm_validate_form_data` | 10 | sureforms-handler.php | `easysender_sureforms_validate_field` |

### Filters the plugin exposes (for customization)

| Filter | Default | Description |
|--------|---------|-------------|
| `easysender_low_balance_threshold` | `50` | Credits threshold for low-balance admin warning |
| `easysender_usage_url` | `EASYSENDER_USAGE_URL` | Override usage stats API endpoint |

---

## AJAX Endpoints

All endpoints require `manage_options` capability and nonce verification.

### `easysender_verify_api_key`
**Purpose:** Test API credentials without saving them.
**JS file:** `assets/js/admin-api.js` — localized as `easysenderApiData.nonce`
**POST params:** `action`, `_wpnonce`, `client_id`, `client_secret`
**Success:** `{success: true, data: {message: "Credentials are valid."}}`
**Failure:** `{success: false, data: {message: "..."}}`

### `easysender_test_email`
**Purpose:** Verify a single email from the admin Test tab.
**JS file:** `assets/js/admin-test.js` — localized as `easysenderTestData.nonce`
**POST params:** `action`, `_wpnonce`, `email`
**Success:** `{success: true, data: {verdict: "valid"|"risky"|"invalid", status: string, details: object}}`
**Failure:** `{success: false, data: {message: string}}`
**Note:** `verdict` is derived: `deliverable`→`valid`, `risky`→`risky`, everything else→`invalid`

### `easysender_get_usage`
**Purpose:** Fetch credit stats for the Usage tab.
**JS file:** `assets/js/admin-usage.js` — localized as `easysenderUsageData.nonce`
**POST params:** `action`, `_wpnonce`
**Success:** `{success: true, data: {allocated: int, balance: int, spent: int, pct: int, quota: bool}}`
**Failure:** `{success: false, data: {message: string}}`

---

## Admin UI

### Menu Structure

```
EasySender (toplevel_page_easysender_welcome)
├── Welcome        — Onboarding instructions and quick links
├── Settings       — Main config with 5 tabs (see below)
└── Domain Scanner — Links out to EasyDMARC domain scanner tool
```

### Settings Page Tabs (`admin.php?page=easysender_settings&tab=...`)

| Tab | URL param | Content |
|-----|-----------|---------|
| API Settings | `tab=api` | Client ID/Secret fields, Verify API Key button, form integration toggles, allowed status checkboxes, allow-on-error toggle |
| Logging & Usage | `tab=usage` | Live credit stats (allocated/balance/spent), usage progress bar, upgrade CTA |
| Test Email | `tab=test` | Single-email test with result display (status pill, request ID) |
| Error Messages | `tab=messages` | Custom messages for invalid, risky, and API error scenarios |
| Documentation | `tab=documentation` | Links to API docs, privacy policy, terms |

### Enqueue Logic (`easysender_admin_enqueue_scripts`)

CSS `assets/css/admin.css` is loaded on **all** plugin admin pages (`strpos($hook, 'easysender') !== false`).
JS files are loaded **only on** `page=easysender_settings` and only for the active tab:
- `tab=api` → `admin-api.js` + `easysenderApiData` nonce
- `tab=test` → `admin-test.js` + `easysenderTestData` nonce
- `tab=usage` → `admin-usage.js` + `easysenderUsageData` nonce

### Low-Balance Admin Notice

Fires on `admin_notices` for any admin page. Checks `easysender_usage_cache` transient (no forced refresh). If `balance <= threshold` (default 50, filterable via `easysender_low_balance_threshold`), displays a dismissible-style warning banner with an upgrade link.

---

## Form Integration Details

### Elementor Pro Forms

**Hook:** `elementor_pro/forms/validation` (action, 10, 2 — `$record`, `$ajax_handler`)
**Enabled by:** `enable_elementor = '1'` in settings
**Email field detection:**
1. Searches `$record->get('fields')` for a field with `type === 'email'`
2. Falls back to common IDs: `email`, `your-email`, `user_email`, `user-email`

**Error delivery:** `$ajax_handler->add_error($field_id, $message)` then `wp_send_json_error()` + `die()`
**CSS override:** Injects `wp_head` CSS to hide Elementor's empty red danger bar when field-level errors are shown.
**Global flag:** `$GLOBALS['easysender_email_verified']` set to `true` on pass, `false` at start of validation.

### Contact Form 7

**Hooks:** `wpcf7_validate_email*` (required fields) and `wpcf7_validate_email` (optional fields)
**Enabled by:** `enable_cf7 = '1'` in settings
**Note:** CF7 verifies its own nonce before the validation hooks fire.
**Email extraction:** Reads from `$_POST[$tag->name]` directly (phpcs:ignore justified — CF7 handles nonce).
**Error delivery:** `$result->invalidate($tag, $message)`

### WPForms

**Hook:** `wpforms_process` (action, 10, 3 — `$fields`, `$entry`, `$form_data`)
**Enabled by:** `enable_wpforms = '1'` in settings
**Email field detection:** Iterates all fields, checks `$field['type'] === 'email'`
**Error delivery:** `wpforms()->process->errors[$form_id][$field_id] = $message`

### Ninja Forms

**Hook:** `ninja_forms_submit_data` (filter, 10, 1 — `$form_data`)
**Enabled by:** `enable_ninjaforms = '1'` in settings
**Note:** Submitted JSON does not include field types — loads field objects from DB via `Ninja_Forms()->form($id)->get_fields()`
**Form ID parsing:** Strips render-instance suffix (e.g. `"3_1"` → `3`)
**Error delivery:** `$form_data['errors']['fields'][$field_id] = $message`; returns modified `$form_data`

### Gravity Forms

**Hook:** `gform_field_validation` (filter, 10, 4 — `$result, $value, $form, $field`)
**Enabled by:** `enable_gravityforms = '1'` in settings
**Detection:** `class_exists('GFForms')` — plugin slug `gravityforms/gravityforms.php`
**Requires:** Gravity Forms 2.5+, PHP 7.4+
**Hook signature:** `$result, $value, $form, $field`
- `$result` — array `['is_valid' => bool, 'message' => string]`, starts as `['is_valid' => true, 'message' => '']`
- `$value` — submitted field value (string for email fields; array for email+confirmation fields)
- `$form` — form array: `$form['id']`, `$form['title']`, `$form['fields']`
- `$field` — `GF_Field` object: `$field->type`, `$field->id`, `$field->label`
**Email field detection:** `$field->type === 'email'` (checked inside callback since hook fires for all field types)
**Skip condition:** Returns early if `$result['is_valid']` is already `false` — avoids API call when GF's own format check already failed
**Error delivery:** Set `$result['is_valid'] = false` and `$result['message'] = $reason`, return `$result` — GF handles display, scroll-to-field, and JSON response automatically
**Docs:** https://docs.gravityforms.com/gform_field_validation/

### Fluent Forms

**Hook:** `fluentform/validate_input_item_input_email` (filter, 10, 6)
**Enabled by:** `enable_fluentforms = '1'` in settings
**Detection:** `function_exists('wpFluentForm')` — works for both free (`fluentform/fluentform.php`) and Pro (`fluentformpro/fluentformpro.php`)
**Requires:** Fluent Forms 4.3.22+, PHP 7.4+, WordPress 6.4+
**Hook signature:** `$error, $field, $formData, $fields, $form, $errors`
- `$field['name']` — HTML input name and `$formData` key
- `$field['element']` — always `'input_email'` for this hook
- `$formData[$field['name']]` — submitted email value (already sanitized/lowercased by FF)
- `$form->id` — form ID
**Email field detection:** Not needed — the hook fires only for `input_email` element type fields
**Error delivery:** Return a non-empty string → FF adds it as field-level error, throws `ValidationException` (HTTP 423), submission is never saved
**Note:** Returns `$error` unchanged (empty string `''`) to allow the submission through

### SureForms

**Hook:** `srfm_validate_form_data` (filter, 10, 1)
**Enabled by:** `enable_sureforms = '1'` in settings
**Detection:** `class_exists('\SRFM\Plugin_Loader')` — plugin slug `sureforms/sureforms.php`
**Requires:** SureForms 1.x+, PHP 7.4+
**Hook signature:** `$data` (single array parameter)
- `$data['block_slug']` — field type slug; `'email'` for email fields
- `$data['field_value']` — submitted field value
- `$data['form_id']` — form post ID
- `$data['block_id']` — unique block identifier (UUID fragment)
- `$data['field_key']` — full field key, e.g. `srfm-email-c867d9d9-lbl-email`
- `$data['name_with_id']` — field name without label, e.g. `srfm-email-c867d9d9`
**Email field detection:** `$data['block_slug'] === 'email'`
**Skip condition:** Returns `$data` untouched when not an email field or value is empty — SureForms checks `isset($result['validated'])` and skips if missing
**Error delivery:** Return `['validated' => false, 'error' => $reason]` — SureForms adds it as field-level error and sends `wp_send_json_error()` (HTTP 400) with `field_errors` map
**Pass delivery:** Return `['validated' => true, 'error' => '']` — SureForms skips further validation for the field
**Source:** `sureforms/inc/field-validation.php` lines 248–275

---

## Security Model

| Concern | Implementation |
|---------|---------------|
| Credential storage | AES-256-CBC + HMAC-SHA256 using `AUTH_KEY`; encrypted values stored in `wp_options` |
| AJAX authorization | All handlers: `current_user_can('manage_options')` + `check_ajax_referer()` |
| Input sanitization | `sanitize_text_field()`, `sanitize_email()`, `wp_unslash()`, `sanitize_key()` |
| Output escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()` throughout templates |
| Log privacy | `easysender_log_api_error()` scrubs email addresses and `emailAddresses` keys from all log entries |
| Error messages | Raw API error bodies are logged only; users always see custom/generic messages |
| Settings whitelist | `easysender_settings_sanitize()` enforces an explicit key whitelist; unknown keys are dropped |

---

## Encryption Format

**v2 format (current):**
```
"v2:" + base64( IV[16 bytes] + AES256CBC(plaintext)[variable] + HMAC-SHA256[32 bytes] )
```
Key derivation: `enc_key = sha256(AUTH_KEY)`, `mac_key = sha256('mac_' + AUTH_KEY)`
IV: `random_bytes(16)` (falls back to deterministic SHA-256 slice if `random_bytes` unavailable)

**Legacy format (backward-compatible read):**
No `v2:` prefix. Deterministic IV, no HMAC. Written by older plugin versions.

---

## Plugin Detection Logic

`easysender_plugin_checks()` returns an array indexed by form plugin key. Each entry has:
- `installed` (bool) — plugin directory exists
- `active` (bool) — plugin is activated
- `label` (string) — display name
- `key` (string) — settings key (e.g. `enable_cf7`)
- `available` (bool) — integration is implemented in this version

Checkboxes are **disabled** in the UI if `!installed || !active || !available`.
WooCommerce and WP Registration show `(not available in this version)` note.

---

## Error Message Fallback Chain

When displaying a validation error to the user, each integration follows this priority:

1. `$check['reason']` if set and non-empty — already resolved from `easysender_do_email_check()`
2. If `$check['reason']` is empty and `$check['status'] === 'risky'` → `msg_risky` from settings
3. If `$check['reason']` is empty and status is another value → `msg_invalid` from settings
4. If all above empty → `msg_api_error` from settings
5. If settings message also empty → hardcoded English fallback string

---

## Known Limitations / Future Work

- **WooCommerce, WP Registration** — listed in admin UI as "not available in this version"; handler files do not exist
- **No `.pot` file** — text domain `easysender-email-verification` is set but no translation template exists
- **No deactivation hook** — transients and options persist after deactivation (cleaned only on full deletion via `uninstall.php`)
- **8-second dupe guard** — prevents rapid resubmission re-checks but provides no meaningful bot protection
- **`includes/constants.php`** — empty file, not loaded anywhere

---

## How to Add a New Form Integration

1. Create `{formname}-handler.php` in the plugin root
2. Define `easysender_{formname}_is_enabled()` — checks `enable_{formname}` option key
3. Define `easysender_{formname}_validate(...)` — calls `easysender_do_email_check($email)`, writes errors using that form's API
4. Register the validation hook inside an `init` action, guarded by `is_enabled()` and `function_exists('{FormClass}')` or equivalent
5. Add the integration to `easysender_plugin_checks()` in `admin-settings.php` with `'available' => true`
6. Add `enable_{formname}` to the whitelist in `easysender_settings_sanitize()`
7. Add `require_once` for the new file in `easysender-email-verification.php`

---

## Debugging

Enable WordPress debug logging:
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Log entries are written to `wp-content/debug.log` and prefixed with `[EasySender]`.
Email addresses are always scrubbed from log output.

Common log patterns:
```
[EasySender] API verify HTTP 402           — credit quota exhausted
[EasySender] API verify HTTP 401           — token expired (auto-refreshes)
[EasySender] API token WP_Error: ...       — network failure reaching token endpoint
[EasySender] API transport HTTP 0 ...      — cURL/HTTP transport error
[EasySender] API usage HTTP 200 ...        — usage stats fetched successfully
```

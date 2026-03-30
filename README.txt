=== EasySender Email Verification ===
Contributors: easydmarc
Tags: email verification, spam prevention, contact form 7, elementor, wpforms
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Validate email addresses in real time inside WordPress forms using EasyDMARC's EasySender API.

== Description ==

**EasySender Email Verification** connects your WordPress forms to EasyDMARC's EasySender API and validates every submitted email address before the form is accepted. It helps you block invalid, disposable, risky, and undeliverable addresses from entering your system.

All verification requests are performed server-side — no JavaScript is sent to the browser. Credentials are encrypted with AES-256 using your site's `AUTH_KEY`.

= Supported form plugins =

* Elementor Pro Forms
* Contact Form 7
* WPForms
* Ninja Forms
* Fluent Forms (free and Pro)
* Gravity Forms
* SureForms
* WooCommerce Checkout
* WordPress Registration Form
* WooCommerce Checkout
* WordPress Registration Form

= How it works =

1. A visitor submits a form containing an email field.
2. The plugin sends the address to the EasySender API for real-time verification.
3. If the result is not in your allowed list (e.g. you block "undeliverable"), the form is rejected and a clear error message is shown under the email field.
4. Deliverable addresses pass through normally.

= Key features =

* **Per-status control** — independently allow or block deliverable, risky, undeliverable, and unknown results.
* **Allow on API error** — optionally permit submissions when the verification service is unavailable.
* **Custom error messages** — set your own text for invalid, risky, and API-error scenarios.
* **Test Email tab** — verify any address from the admin panel and inspect the raw API result.
* **Credit usage dashboard** — see your allocated credits, current balance, and usage at a glance.
* **Debug logging** — errors are written to `debug.log` (with email addresses redacted) when `WP_DEBUG_LOG` is enabled.

= Privacy =

Email addresses are sent to `https://sender-api.easydmarc.com` for verification. See EasyDMARC's privacy policy at https://easydmarc.com/privacy-policy/ for details on how verification data is handled.

== Requirements ==

* WordPress 5.8 or later
* PHP 7.4 or later
* OpenSSL PHP extension
* An EasyDMARC account with an EasySender API Client ID and Client Secret
* One or more supported form plugins (Elementor Pro, Contact Form 7, WPForms, Ninja Forms, Fluent Forms, Gravity Forms, or SureForms)

== Installation ==

1. Upload the `easysender-email-verification` folder to `/wp-content/plugins/` and activate it, **or** search for "EasySender Email Verification" in **Plugins → Add New** and click Install.
2. Go to **EasySender → Settings** and open the **API Settings** tab.
3. Enter your EasySender **Client ID** and **Client Secret**.
4. Click **Verify API Key** to confirm your credentials are working.
5. Enable the form integrations you use and configure which verification statuses are allowed.

== Frequently Asked Questions ==

= Where do I get the API credentials? =

Log in to your EasyDMARC account at https://easydmarc.com/ and navigate to the EasySender section to generate your Client ID and Client Secret.

= Does this work without a paid EasyDMARC plan? =

A free account includes a limited number of verification credits. Paid plans are required for higher volumes. See https://easydmarc.com/pricing/ for details.

= Will the plugin block my forms if the API is down? =

No — if you enable **Allow submission if verification service is unavailable** in the settings, form submissions will pass through normally when the API cannot be reached.

= Which email statuses should I allow? =

At minimum, enable **deliverable**. Enabling **risky** is optional — risky addresses exist but may bounce. We recommend blocking **undeliverable** and **unknown** to keep your list clean.

= Are email addresses stored or logged? =

No email addresses are stored by this plugin. Debug log entries automatically redact all email addresses before writing to the log file.

= Is the plugin compatible with caching plugins? =

Yes. All verification requests are made server-side during form submission (a POST request), which bypasses page caching.

= Where can I report a bug or request a feature? =

Please open a thread in the plugin's support forum on WordPress.org.

== Screenshots ==

1. API Settings tab — enter credentials, enable form integrations, and configure allowed statuses.
2. Credit Usage dashboard — donut chart showing available balance and credits used.
3. Test Email tab — verify any address and inspect the API result.
4. Error Messages tab — customise the validation messages shown to visitors.
5. Example of a blocked submission in Contact Form 7 with a field-level error message.

== Changelog ==

= 1.0.0 =
* Initial release.
* Integrations: Elementor Pro Forms, Contact Form 7, WPForms, Ninja Forms, Fluent Forms (free + Pro), Gravity Forms, SureForms.
* AES-256-CBC + HMAC-SHA256 credential encryption using WordPress AUTH_KEY.
* Per-status allow/block rules (deliverable, risky, undeliverable, unknown).
* Allow-on-API-error fallback option.
* Custom error messages per scenario.
* Test Email admin tab with live API result.
* Credit usage dashboard with visual donut chart.
* Debug logging with automatic email redaction.
* Plugin data cleanup on uninstall.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.

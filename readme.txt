=== Tuedion Cookie ===
Contributors: tuedion
Donate link: https://tuedion.com
Tags: cookie, consent, gdpr, privacy, cookieconsent
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, local-first cookie consent with Google Consent Mode v2, script & iframe blocking, WCAG accessible UI, and live preview.

== Description ==

Tuedion Cookie gives site owners and enterprise administrators complete control over visitor consent management on WordPress. Built on top of Orest Bida's lightweight and high-performance vanilla-cookieconsent (v3.1.0) library, it empowers sites to manage cookies, scripts, iframes, and privacy compliance without heavy dependencies, bloated cloud scripts, or third-party subscription lock-in.

= Key Features =

* **Local-First & Zero Cloud Lock-In:** Core banner assets and styles are bundled locally. No mandatory external CDN calls or remote runtime dependencies.
* **Granular Category & Service Controls:** Out-of-the-box support for Strictly Necessary, Functionality, Analytics, and Marketing categories, plus individual service toggles.
* **Interactive Live Preview Sandbox:** Real-time split-screen preview in the admin with desktop, tablet, and mobile viewport simulation.
* **Persistent Privacy Trigger (WCAG 2.2 AA):** Reversible consent made accessible via a floating trigger button with customizable positions, offsets, and mobile collision avoidance.
* **Script & Iframe Enforcement:** Native deny-before-consent script blocking (`type="text/plain"` and `data-category`) and accessible placeholder cards for YouTube, Vimeo, Google Maps, and OpenStreetMap iframes.
* **Google Consent Mode v2 First-Class Support:** Early `<head>` default state injection (priority 0) with automatic gtag updates and GTM custom event (`tuedion_consent_update`). Includes Site Kit duplicate detector.
* **Internationalization & RTL Support:** Built-in translations for 9 languages (English, Turkish, German, French, Spanish, Italian, Dutch, Arabic, Russian) with automatic document language detection, long-word hyphenation, and true RTL mirroring.
* **GDPR Consent Audit Logging (Art. 7(1)):** Minimal, privacy-first consent logging with zero DB writes when disabled, strict IP anonymization (`wp_privacy_anonymize_ip()`), non-reversible User Agent hashing, automated retention cleanup, and CSV export.
* **WordPress Ecosystem Adapters:**
  - **WooCommerce:** Strict protection of essential cart/session cookies with zero checkout disruption.
  - **Elementor:** Automatic transformation of Video and Google Maps widgets into consent cards with dynamic popup reactivation.
  - **Form Plugins (CF7, WPForms, Gravity Forms, Fluent Forms):** Anti-spam CAPTCHA challenge protection with accessible consent notices.
  - **Speed & Cache Optimizers:** Built-in delay/defer exclusions and cache flushing for WP Rocket, LiteSpeed, Autoptimize, SiteGround, Perfmatters, FlyingPress, and 6 more engines.
* **Developer Platform & Diagnostics:** Live configuration inspector, enqueued script tracker scanner, developer hooks reference, and one-click sanitized support report export.

== Installation ==

1. Upload the `tuedion-cookie` folder to the `/wp-content/plugins/` directory, or install via WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Access **Tuedion Cookie** in the admin sidebar to customize your banner theme, enable Google Consent Mode, or inspect active trackers.

== Frequently Asked Questions ==

= Does this plugin guarantee 100% legal compliance? =
No plugin can guarantee 100% legal compliance automatically. Tuedion Cookie provides the technical tools to implement granular consent, Google Consent Mode v2, and script blocking. Legal compliance depends on your site's specific cookies, accurate disclosures, privacy policy, and applicable laws in your jurisdiction.

= Does Tuedion Cookie slow down my website? =
No. The core frontend payload is ultra-lightweight (under 25KB gzipped combined), executes synchronously in native vanilla JavaScript, and avoids heavy frontend frameworks.

= How do I reopen cookie preferences from a link in my Privacy Policy? =
You can use the shortcode `[tuedion_cookie_policy_link]` or add the attribute `data-cc="show-preferencesModal"` to any button or link on your site.

= Does it work with Google Tag Manager? =
Yes. GTM integration is supported either by managing tags with Google Consent Mode v2 built-in checks, or by listening for the custom event `tuedion_consent_update`.

== Third-Party Services ==

Tuedion Cookie does NOT transmit personal visitor data to external cloud services. The plugin operates 100% locally on your WordPress server.

To empower site administrators to enforce GDPR/ePrivacy compliance, the plugin maintains an offline catalogue of detection signatures ("recipes") to identify and block third-party services installed on your site until visitors grant explicit consent:

* **Google Analytics & Google Tag Manager** (Google LLC)
  - Purpose: Pre-consent script blocking and Google Consent Mode v2 signal coordination.
  - Terms of Service: https://marketingplatform.google.com/about/analytics/terms/us/
  - Privacy Policy: https://policies.google.com/privacy

* **Meta Pixel** (Meta Platforms, Inc.)
  - Purpose: Pre-consent blocking of Meta advertising scripts and pixel events.
  - Terms of Service: https://www.facebook.com/legal/terms
  - Privacy Policy: https://www.facebook.com/privacy/policy/

* **Microsoft Clarity** (Microsoft Corporation)
  - Purpose: Pre-consent blocking of session replay and heatmap tracking.
  - Terms of Use: https://clarity.microsoft.com/terms
  - Privacy Statement: https://privacy.microsoft.com/privacystatement

* **Hotjar** (Hotjar Ltd)
  - Purpose: Pre-consent blocking of user behavior analytics scripts.
  - Terms of Service: https://www.hotjar.com/legal/policies/terms-of-service/
  - Privacy Policy: https://www.hotjar.com/legal/policies/privacy/

* **YouTube & Vimeo**
  - Purpose: Blocking embedded iframes and presenting local placeholder cards until media consent is granted.
  - YouTube Terms: https://www.youtube.com/t/terms
  - Vimeo Terms: https://vimeo.com/terms

* **Open Cookie Database** (GitHub / Jan Kwakman)
  - Purpose: Site administrators can optionally click "Update Cookie DB" in the admin scanner to download the latest community-curated cookie definitions and classification patterns.
  - Data Transmitted: No visitor, site, or administrator personal data is transmitted. Only a standard HTTP GET request is performed to fetch the public JSON file.
  - Endpoint: https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.json
  - Repository: https://github.com/jkwakman/Open-Cookie-Database
  - License: Apache-2.0 License

Note: Tuedion Cookie does NOT inject or initiate any of the above external services on its own. It only monitors and enforces consent on scripts and embeds that already exist on your site.

== Screenshots ==

1. **Dashboard & Quick Status:** Overview of consent settings, revision status, and quick links.
2. **Experience & Live Preview:** Real-time split-screen customization with desktop, tablet, and mobile viewports.
3. **Categories & Services Catalogue:** Granular category definitions, auto-cleared cookie patterns, and ecosystem adapter statuses.
4. **Consent Audit Logs:** Searchable audit trail with masked IPs, policy revision filters, and CSV export.
5. **Diagnostics & Developer Hub:** Runtime configuration inspector, script tracker inventory, and cache hints.

== Changelog ==

= 1.5.0 =
* Performance: Consolidated public frontend into unified, minified JS and CSS bundles (tuedion-cookie.bundle.min.js & .bundle.min.css), eliminating 1.6s+ PageSpeed render-blocking network waterfall.
* WordPress 7.1 Architecture: Implemented native dependency-aware script loading strategy (strategy => 'defer') with full backward and forward compatibility (WordPress 6.3 - 7.1+).
* Cache Compatibility: Refined speed optimizer integration across WP Rocket, LiteSpeed Cache, Perfmatters, and FlyingPress—preserving delay-until-interaction immunity while permitting non-blocking browser defer.
* Google Consent Mode v2: Introduced Zero-Latency Early Hydration in wp_head priority 0, restoring returning visitor consent immediately for Google Tag Manager and GA4 without external JS dependencies.
* WordPress Dev Tooling: Added official @wordpress/scripts toolchain with package.json (npm run build, lint, format) and standalone dual-runtime (Node.js & PHP CLI) production bundlers.

= 1.4.2 =
* Feature: Introduced Smart Scan with automated XML sitemap discovery and dynamic navigation link crawling.
* Feature: Added Full Website Scan mode with real-time multi-phase progress tracking.
* Performance: Optimized database queries when auditing large catalogs of posts and custom post types.
* Enhancement: Improved cookie expiration (duration) accuracy in public declaration tables across all languages.
* Security: Hardened internal crawler network requests and admin scan endpoints.
* Compatibility: Fully verified and updated for WordPress 6.x and VIP coding standards.

= 1.4.1 =
* Privacy: Ensured WordPress authentication and administrator UI cookies (wordpress_logged_in_*, wp-settings-*) are excluded from public visitor cookie tables.
* Feature: Added dynamic Custom Post Type (CPT) and Page scan scope selector with chips and multi-step progress bar.
* Feature: Added configurable scan scope for WP-Cron background crawler.
* Performance: Updated runtime configuration cache for instant asset synchronization.

= 1.4.0 =
* Feature: Scanner v2 Architecture decoupling Cookie Database, Service Registry (40+ global services), and Multi-Factor Confidence Scoring (Confirmed, Very Likely, Probable, Possible, Weak).
* Feature: Integrated offline Open Cookie Database with exact and wildcard matching, with fail-safe manual admin-triggered remote updater.
* Feature: Expanded client-side scanner to safely audit HTML5 localStorage and sessionStorage key names.
* UI: Added confidence badges, evidence breakdown trails, Open Cookie Database statistics, and verified cookie markers.
* Cache Busting: Incremented runtime transient cache keys to v140.

= 1.3.3 =
* Security: Hardened Cookie Scanner to extract non-sensitive cookie names only and isolate WordPress session/auth cookies.
* Security: Enforced strict origin verification on postMessage events and audit frame listeners.
* Security: Added comprehensive AJAX payload sanitization, HTTP POST validation, and payload size bounds.
* Standards: Migrated inline scripts and styles to modular assets via WordPress Enqueue API and replaced JS redirects with wp_safe_redirect().
* Hardening: Strengthened ScriptEnforcer attribute whitelist and RecipeRegistry domain matching against query string spoofing.
* Compliance: Documented local offline detection signatures and Open Cookie Database endpoint in readme.

= 1.3.2 =
* Bug Fixed.


= 1.3.1 =
* Scheduled Automated Scanner: Integrated continuous background site crawl with daily/weekly/monthly frequency via WP-Cron.
* Drift Detection & Email Alerts: Instant notification dispatched to security/admin contact upon discovering new unclassified cookies.
* Admin Quick Navigation: Added anchor jump menu on Settings page for instant access to Scheduled Scanner and compliance tools.
* Category Console Integration: Added Automated Scan status badge and configuration shortcut directly inside Categories & Services tracker hero.
* Auth Cookie Visibility Fix: Prevented WordPress login session cookies (wordpress_logged_in_*) from showing in public declaration table on cached pages for guest visitors.
* Cache Busting: Incremented runtime transient cache key to v131 for immediate opcache, object cache, and transient invalidation.

= 1.3.0 =
* GDPR Cookie Lifespan Compliance: Added mandatory cookie expiration (duration) column to CookieTableBuilder across all 9 supported languages (GDPR Art. 13 & EDPB compliance).
* Iframemanager 9-Language Dictionary: Expanded embed blocking notice and action buttons to tr, en, de, fr, es, it, nl, ar, ru.
* Live Declaration Shortcodes: Added [tuedion_cookie_declaration] and [tuedion_cookie_table] for automated compliance policy pages.
* Scheduled WP-Cron Cookie Scanner: Continuous automated background site crawl with instant email alerts when newly installed unclassified cookies are discovered.
* Global Output Buffer Inline Script Blocker: Intercepts and pauses hardcoded inline script tags in themes and headers prior to consent.
* Gettext i18n & Turkish Translation: Generated comprehensive 560+ string translation template tuedion-cookie.pot and Turkish tuedion-cookie-tr_TR.po.
* Fixed postMessage DOMException loop on unconsented embed frames. Added Window.prototype.postMessage shield.
* Resilient extraction of video URL from Elementor settings and iframe data attributes.
* Reordered stylesheet enqueues so public CSS cleanly overrides iframemanager defaults.
* Removed data-widget on video placeholders to preserve responsive aspect-ratio padding.

= 1.2.9 =
* Fixed IframeManager placeholder layout inside Elementor responsive wrappers by positioning the card absolutely and suppressing conflicting :before padding.
* Added prominent consent notice ("Bu videoyu izlemek için lütfen çerezleri kabul edin...") with modern primary and secondary action buttons matching Orest Bida demo.
* Added native data-thumbnail and data-title attributes to IframeEnforcer DIV output to display the YouTube cover preview immediately.
* Added single-video load button synchronization with CookieConsent service acceptance.

= 1.2.8 =
* Re-architected IframeEnforcer with Orest Bida Iframemanager compliant DIV structure for 100% pre-consent blocking.
* Added native support for WP Rocket lazyload attributes (data-lazy-src) and automatic rocket_lazyload_iframe exclusion.
* Implemented client-side HTMLIFrameElement prototype interception to prevent dynamic unconsented YouTube embeds.
* Added instant DOM scanner for static page-cached HTML and early MutationObserver for Elementor widgets.
* Fixed Elementor overlay click listener to prevent hijacking non-video page clicks and lightbox elements.
* Added full multilingual support and instant consent synchronization between CookieConsent and Iframemanager.

= 1.2.4 =
* Fix: Prevent Configured Categories from becoming empty during edge-case installations via robust self-heal mechanism.
* Fix: Prevent JSON translation export from opening as a page in browser by forcing octet-stream headers.
* Fix: CookieConsent library auto-detect language configuration now defaults to "document" instead of "browser" to accurately respect the WordPress site language.
* Tweak: Removed redundant translation fields for Privacy Policy and Terms titles from Settings; they now fully synchronize with the JSON translation dictionary.
* Minor code refinements in Admin pages and Repositories.

= 1.2.3 =
* Implemented reactive, asynchronous service catalogue: 1-click preset addition and deletion without full page reloads.
* Added real-time DOM synchronization across Detected Services and Supported Presets catalogue panes.
* Added non-intrusive floating Toast notifications for admin actions with auto-dismissal.
* Added catalogue active tab session persistence across navigation.
* Added asynchronous batch sync for discovered tracking services.
* Bumped runtime compilation cache version to v123.

= 1.2.2 =
* Added custom title/label management for Privacy Policy and Terms & Conditions across global defaults and per-language overrides.
* Dynamically connected localized legal titles to CookieConsent banner and modal footers.
* Fixed translation JSON export: eliminated raw page rendering by enforcing output buffer cleanup, binary download headers, and HTML5 download attribute.
* Fixed CSV and diagnostics JSON export handlers to support direct one-click browser download.
* Bumped runtime compilation cache version to v122.

= 1.2.1 =
* Fixed FormsAdapter fatal TypeError on WPForms and Fluent Forms by converting filters to type-safe action hooks.
* Fixed Contact Form 7 captcha consent notice placement before form submit button.
* Fixed Elementor iframe blocking during editor and preview modes, preserving builder usability.
* Fixed iframe enforcement to strictly respect both category and granular service acceptance.
* Fixed iframe re-blocking and reset on consent revocation.
* Fixed Google Consent Mode v2 cache safety: non-essential default signals are denied in HTML and restored early via client-side cookie validation with revision checks.
* Fixed script enforcer module preservation (type="module") and added immune handles for WooCommerce core scripts.
* Fixed RecipeRegistry to respect user-customized category assignments.
* Fixed consent logging rate limiting, UUID validation, allowlist category filtering, and 403 expired nonce auto-refresh retry.
* Fixed translation keys sanitization in TranslationManager to preserve camelCase for CookieConsent v3.
* Fixed CSV formula injection vulnerability and added dedicated admin-post export handlers for audit logs, translations, and diagnostics.
* Fixed Compiler transient caching to be locale-dependent and respect language settings.
* Fixed WooCommerce adapter essential cookie injection hook.
* Fixed CacheCompatibility to eliminate redundant admin_init database writes.
* Fixed CookieScanner to enforce SSL verification by default and accurately flag Google Fonts management.
* Enhanced multisite network activation, deactivation cron cleanup, and uninstall data purging.
* Added full unmodified GNU GPLv2 license documentation.

= 1.2.0 =
* Added Website Tracker & Cookie Scanner with active service crawl and cookie mapping inventory.
* Added 22 Global Service Recipes catalogue with one-click preferences synchronization.
* Added real-time Design Preset & Surface Color Customizer with zero-delay live frame synchronizer.
* Refactored admin styling architecture to fully eliminate template inline CSS and optimize Materio compatibility.
* Implemented dynamic asset cache-busting via file modification timestamps.

= 1.1.0 =
* Enhanced Polylang & WPML multi-language policy URL resolver.
* Added granular cookie inventory tables in visitor preferences modal.
* Upgraded privacy trigger icon library with native SVG cookie icons.

= 1.0.0 =
* Official production 1.0.0 release.
* Upstream Orest Bida vanilla-cookieconsent bundled and pinned at v3.1.0.
* Enterprise admin interface with tabbed navigation, live sandbox preview, and responsive viewport toggles.
* WCAG 2.2 AA compliant persistent privacy trigger with collision avoidance.
* Native script rewriting and accessible iframe placeholder cards.
* Google Consent Mode v2 integration with early default state injection and Google Site Kit conflict detection.
* 9 built-in language dictionaries with automatic locale detection and RTL mirroring.
* Consent audit logging with strict IP anonymization, daily cleanup cron, and CSV export.
* Ecosystem adapters for WooCommerce, Elementor, Contact Form 7, WPForms, Gravity Forms, and Fluent Forms.
* Universal caching compatibility across 12 WordPress performance plugins and Cloudflare Rocket Loader.
* Diagnostics platform featuring Config Inspector, Script Tracker Inventory, and Support Report Exporter.

== Copyright & Credits ==

This plugin incorporates and bundles open-source software under separate copyright and license conditions:

* CookieConsent (vanilla-cookieconsent) v3.1.0
  * Author: Orest Bida
  * Source: https://github.com/orestbida/cookieconsent
  * License: MIT License (https://opensource.org/licenses/MIT)
  * Copyright (c) 2024 Orest Bida

* Iframemanager v1.2.5
  * Author: Orest Bida
  * Source: https://github.com/orestbida/iframemanager
  * License: MIT License (https://opensource.org/licenses/MIT)
  * Copyright (c) Orest Bida

* Open Cookie Database
  * Author: Jan Kwakman and contributors
  * Source: https://github.com/jkwakman/Open-Cookie-Database
  * License: Apache License 2.0 (https://www.apache.org/licenses/LICENSE-2.0)
  * Bundled files: data/cookies/open-cookie-database.json, data/cookies/metadata.json


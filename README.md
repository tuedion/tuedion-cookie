# Tuedion Cookie — Enterprise WordPress Consent Management & Cookie Banner

[![WordPress Version](https://img.shields.io/badge/WordPress-%3E%3D%206.2-blue.svg?style=flat-square&logo=wordpress)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2-777bb4.svg?style=flat-square&logo=php)](https://php.net)
[![Stable Release](https://img.shields.io/badge/version-1.3.3-success.svg?style=flat-square)](https://github.com/tuedion/tuedion-cookie)
[![Accessibility](https://img.shields.io/badge/WCAG-2.2%20AA%20Compliant-green.svg?style=flat-square)](https://www.w3.org/WAI/standards-guidelines/wcag/)
[![Google Consent Mode](https://img.shields.io/badge/Google%20Consent%20Mode-v2%20Ready-F4B400.svg?style=flat-square&logo=google)](https://developers.google.com/tag-platform/security/guides/consent)
[![License](https://img.shields.io/badge/License-GPLv2%20or%20later-orange.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

**Tuedion Cookie** is an enterprise-grade, privacy-first WordPress plugin that gives site owners, agencies, and developers complete control over visitor consent management, tracker blocking, and legal compliance (GDPR, ePrivacy, CCPA).

Built natively on top of Orest Bida’s high-performance [vanilla-cookieconsent](https://github.com/orestbida/cookieconsent) (v3.1.0) and [iframemanager](https://github.com/orestbida/iframemanager) (v1.2.5), Tuedion Cookie delivers a lightweight, lightning-fast frontend footprint (**< 25KB gzipped combined**) with zero external cloud subscriptions or vendor lock-in.

---

## Table of Contents

- [Key Features](#-key-features)
- [Why Tuedion Cookie?](#-why-tuedion-cookie)
- [Supported Ecosystem & Compatibility](#-supported-ecosystem--compatibility)
- [Architecture & Directory Structure](#-architecture--directory-structure)
- [Installation & Quick Start](#-installation--quick-start)
- [Developer API & Hooks](#-developer-api--hooks)
- [Changelog](#-changelog)
- [Credits & Licenses](#-credits--licenses)

---

## 🚀 Key Features

### 1. Local-First & Zero Cloud Lock-In
- All JavaScript bundles, stylesheets, and assets are hosted locally on your WordPress server.
- No remote external CDNs, tracking telemetry, or third-party monthly subscriptions.

### 2. Granular Category & Service Controls
- 4 default categories out-of-the-box: **Strictly Necessary**, **Functionality**, **Analytics**, and **Marketing**.
- Granular service-level toggles (e.g. YouTube, Google Maps, Hotjar, Facebook Pixel) allowing visitors to accept or reject individual services independently.

### 3. Dynamic Iframe Enforcement & Placeholder Cards
- Deny-before-consent blocking for **YouTube**, **Vimeo**, **Google Maps**, and generic iframes.
- Automatic transformation of blocked embeds into interactive, responsive Orest Bida placeholder cards featuring video cover thumbnails, custom notices, and single-click unlock buttons (*"Çerezleri Kabul Et ve İzle"*, *"Her Zaman İzin Ver"*).
- Includes an integrated `Window.prototype.postMessage` shield that absorbs polling loops from Elementor and YouTube API on blocked frames, eliminating console origin errors.

### 4. Google Consent Mode v2 (CoExists with Site Kit & GTM)
- Injects early `<head>` default signals (priority 0) before any tracker script evaluates.
- Native gtag updates for `ad_storage`, `analytics_storage`, `ad_user_data`, and `ad_personalization`.
- Fires custom GTM dataLayer event: `tuedion_consent_update`.
- Automatic detection and collision warning if Google Site Kit or other plugins inject redundant consent defaults.

### 5. Persistent Floating Privacy Trigger (WCAG 2.2 AA)
- Allows visitors to easily reopen and adjust their consent preferences anytime.
- Customizable corner positions (bottom-left, bottom-right, top-left, top-right), offsets, and mobile safe-area insets (`env(safe-area-inset-bottom)`).
- Full keyboard navigation accessibility (`Tab`, `Enter`, `Escape`) with high-contrast visible focus rings.

### 6. GDPR Consent Audit Logging (Art. 7(1))
- Zero database writes when logging is disabled.
- Full IP anonymization via `wp_privacy_anonymize_ip()`, non-reversible User Agent hashing, and unique consent UUIDs.
- Automatic retention cleanup via daily WP-Cron and one-click CSV audit trail export.

### 7. 9 Built-in Languages & True RTL Mirroring
- Out-of-the-box support for: **English**, **Turkish**, **German**, **French**, **Spanish**, **Italian**, **Dutch**, **Arabic**, and **Russian**.
- Automatic language detection based on document locale (`document.documentElement.lang`).
- Full Right-to-Left (RTL) layout mirroring for Arabic and Hebrew locales.

---

## ⚡ Why Tuedion Cookie?

| Capability | Tuedion Cookie | Traditional SaaS Cloud Plugins |
|---|---|---|
| **Data Privacy** | 100% On-Premise / Self-Hosted | Visitor IPs routed through US/EU SaaS servers |
| **Pricing** | Free & Open Source (GPLv2+) | Recurring monthly subscription per domain/pageviews |
| **Frontend Weight** | < 25KB gzipped (Vanilla JS) | 80KB – 250KB heavy bundled frameworks |
| **PageSpeed Impact** | Negligible (0ms render block) | High (delaying LCP, CLS shifts) |
| **Iframe Handling** | Dynamic placeholders with cover preview | Empty white boxes or broken layouts |
| **Cache Compatibility** | Native bypass rules for 12+ caching engines | Prone to serving stale cached consent state |

---

## 🧩 Supported Ecosystem & Compatibility

### Page Builders & Themes
- **Elementor & Elementor Pro:** Full support for Video widgets, Google Maps embeds, dynamic popups, and custom image overlays.
- **Gutenberg / Block Editor:** Embed block filtering, custom HTML block isolation.
- **WooCommerce:** Protection of essential cart, session, and checkout cookies with zero checkout disruption.

### Caching & Speed Engines
- **WP Rocket:** Automatically excludes iframes from `rocket_lazyload_iframe`, bypasses Delay JS for consent controllers, and flushes cache on version update.
- **LiteSpeed Cache:** ESI and cookie bypass integration.
- **Autoptimize, SiteGround Optimizer, Perfmatters, FlyingPress, WP-Optimize, W3 Total Cache, WP Super Cache, Cache Enabler, Cloudflare Rocket Loader.**

### Form & Anti-Spam Plugins
- **Contact Form 7**, **WPForms**, **Gravity Forms**, **Fluent Forms** (reCAPTCHA and hCaptcha challenge notices).

---

## 📂 Architecture & Directory Structure

```text
tuedion-cookie/
├── assets/
│   ├── admin/             # React/Vanilla Admin UI scripts and styles
│   ├── public/
│   │   ├── css/           # tuedion-cookie.css (theme overrides & Elementor rules)
│   │   └── js/            # bootstrap.js (orchestrator & postMessage shield), event-bridge.js, persistent-trigger.js
│   └── vendor/
│       ├── cookieconsent/ # Upstream Orest Bida CookieConsent v3.1.0
│       └── iframemanager/ # Upstream Orest Bida IframeManager v1.2.5
├── includes/
│   ├── Admin/             # Admin menus, dashboard pages, wizard, settings
│   ├── Consent/           # Frontend controller, IframeEnforcer, ScriptEnforcer
│   ├── Core/              # Plugin bootstrap, autoloader, lifecycle hooks
│   ├── Diagnostics/       # Scanner, system profiler, debugger
│   ├── I18n/              # Language dictionaries, translation manager
│   ├── Integrations/      # Adapters (Elementor, WooCommerce, Forms, CacheCompatibility)
│   │   └── Google/        # ConsentModeV2 implementation
│   ├── Logs/              # Audit logger, CSV exporter, retention cleaner
│   └── Settings/          # Repository, defaults, validator
├── languages/             # .po/.mo localization files
├── readme.txt             # WordPress.org standard metadata
├── README.md              # GitHub documentation
└── tuedion-cookie.php     # Main entrypoint
```

---

## 📥 Installation & Quick Start

1. **Download or Clone:**
   Clone this repository directly into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/tuedion/tuedion-cookie.git
   ```
2. **Activate:**
   Go to **WP Admin -> Plugins** and activate **Tuedion Cookie**.
3. **Configure:**
   - Navigate to **Tuedion Cookie** in the admin sidebar.
   - Run the initial Quick Setup Wizard to select your legal jurisdiction and categories.
   - Choose or customize your color palette with the real-time Split-Screen Preview Sandbox.
   - Enable **Google Consent Mode v2** if you are running Google Analytics 4 or Google Ads.

---

## 🛠 Developer API & Hooks

### Client-Side JavaScript API
```javascript
// Check if a category is accepted
window.CookieConsent.acceptedCategory('analytics');

// Open the Preferences Modal programmatically
window.CookieConsent.showPreferences();

// Re-synchronize managed iframes after dynamic DOM injection
window.TuedionCookie.activateIframes();

// Listen for consent changes via Tuedion Event Bridge
window.addEventListener('tuedion:consent', function(event) {
    console.log('Consent saved:', event.detail);
});
```

### Server-Side PHP Filters
```php
// Enforce custom iframes in any custom post type or template
$safe_html = \Tuedion\CookieConsent\Consent\IframeEnforcer::enforce($custom_html);

// Add custom bypass handles to ScriptEnforcer
add_filter('tuedion_cookie_immune_script_handles', function(array $handles): array {
    $handles[] = 'my-essential-script';
    return $handles;
});
```

### Policy Page Shortcodes
Tuedion Cookie includes ready-to-use shortcodes for your Cookie Policy and Privacy Policy pages:
- `[tuedion_cookie_declaration]` — Renders a complete, responsive declaration of all active cookies and tracking services categorized by purpose, with localized Cookie name, Domain, Duration (lifespan), Description, and a "Change your consent" trigger button.
- `[tuedion_cookie_table category="analytics"]` — Renders an isolated cookie table for a specific category (`necessary`, `functionality`, `analytics`, `marketing`).
- `[tuedion_cookie_policy_link]` — Outputs an inline button to open the Cookie Preferences center from anywhere in your content.

---

## 📝 Changelog

### = 1.3.3 =
* **Security & Isolation:** Hardened Cookie Scanner to extract non-sensitive cookie names only; isolated WordPress authentication and session cookies.
* **postMessage Validation:** Enforced strict `window.location.origin` target and receiver verification, eliminating wildcard targets.
* **AJAX Hardening:** Added strict HTTP method verification, 200KB payload limit, and granular type-based sanitization for scanner data.
* **WordPress Standards:** Replaced inline scripts, styles, and redirects with WordPress Enqueue API and `wp_safe_redirect()`.
* **Enforcer & Registry Hardening:** Implemented strict attribute whitelist in `ScriptEnforcer` and host-aware domain matching in `RecipeRegistry`.
* **Transparency:** Added detailed offline signature disclosures in documentation.

### = 1.3.2 =
* **Cache Busting:** Incremented runtime transient cache key to `v132`.

### = 1.3.1 =
* **Scheduled Automated Scanner:** Integrated continuous background site crawl with daily/weekly/monthly frequency via WP-Cron.
* **Drift Detection & Email Alerts:** Instant notification dispatched to security/admin contact upon discovering new unclassified cookies.
* **Admin Quick Navigation:** Added anchor jump menu on Settings page for instant access to Scheduled Scanner and compliance tools.
* **Category Console Integration:** Added Automated Scan status badge and configuration shortcut directly inside Categories & Services tracker hero.
* **Auth Cookie Visibility Fix:** Prevented WordPress login session cookies (`wordpress_logged_in_*`) from showing in public declaration table on cached pages for guest visitors.
* **Cache Busting:** Incremented runtime transient cache key to `v131` for immediate opcache, object cache, and transient invalidation.

### = 1.3.0 =
* **GDPR Cookie Lifespan Compliance:** Added mandatory cookie expiration (`duration`) column to `CookieTableBuilder` across all 9 supported languages (GDPR Art. 13 & EDPB compliance).
* **Iframemanager 9-Language Dictionary:** Expanded embed blocking notice and action buttons to `tr`, `en`, `de`, `fr`, `es`, `it`, `nl`, `ar`, `ru`.
* **Live Declaration Shortcodes:** Added `[tuedion_cookie_declaration]` and `[tuedion_cookie_table]` for automated compliance policy pages.
* **Scheduled WP-Cron Cookie Scanner:** Added continuous automated background site crawl with instant email alerts when newly installed unclassified cookies are discovered.
* **Global Output Buffer Inline Script Blocker:** Intercepts and pauses hardcoded inline `<script>` tags pasted directly in themes or headers prior to consent.
* **Gettext i18n & Turkish Translation:** Generated comprehensive 560+ string translation template `tuedion-cookie.pot` and Turkish `tuedion-cookie-tr_TR.po`.
* **Fixed postMessage DOMException loop** on unconsented embed frames by introducing a `Window.prototype.postMessage` shield.
* **Resilient video URL extraction** from Elementor widget metadata and iframe data attributes.
* **Reordered stylesheet enqueues** so public CSS cleanly overrides iframemanager defaults.
* **Removed data-widget attribute** on video placeholders to preserve responsive 16:9 aspect-ratio padding.

### = 1.2.9 =
* **Fixed IframeManager placeholder layout** inside Elementor responsive wrappers by positioning the card absolutely and suppressing conflicting `:before` padding.
* **Added prominent consent notice** (*"Bu videoyu izlemek için lütfen çerezleri kabul edin..."*) with modern primary and secondary action buttons matching Orest Bida demo.
* **Added native data-thumbnail and data-title attributes** to IframeEnforcer DIV output to display YouTube cover preview immediately.
* **Added single-video load button synchronization** with CookieConsent service acceptance.

### = 1.2.8 =
* **Re-architected IframeEnforcer** with Orest Bida Iframemanager compliant DIV structure for 100% pre-consent blocking.
* **Added native support for WP Rocket lazyload attributes** (`data-lazy-src`) and automatic `rocket_lazyload_iframe` exclusion.
* **Implemented client-side HTMLIFrameElement prototype interception** to prevent dynamic unconsented YouTube embeds.
* **Added instant DOM scanner** for static page-cached HTML and early MutationObserver for Elementor widgets.
* **Fixed Elementor overlay click listener** to prevent hijacking non-video page clicks and lightbox elements.
* **Added full multilingual support** and instant consent synchronization between CookieConsent and Iframemanager.

### = 1.2.4 =
* **Fix:** Prevent Configured Categories from becoming empty during edge-case installations via robust self-heal mechanism.
* **Fix:** Prevent JSON translation export from opening as a page in browser by forcing octet-stream headers.
* **Fix:** CookieConsent library auto-detect language configuration now defaults to `"document"` instead of `"browser"` to accurately respect the WordPress site language.
* **Tweak:** Removed redundant translation fields for Privacy Policy and Terms titles from Settings; they now fully synchronize with the JSON translation dictionary.
* Minor code refinements in Admin pages and Repositories.

### = 1.2.3 =
* Implemented reactive, asynchronous service catalogue: 1-click preset addition and deletion without full page reloads.
* Added real-time DOM synchronization across Detected Services and Supported Presets catalogue panes.
* Added non-intrusive floating Toast notifications for admin actions with auto-dismissal.
* Added catalogue active tab session persistence across navigation.
* Added asynchronous batch sync for discovered tracking services.
* Bumped runtime compilation cache version to v123.

### = 1.2.2 =
* Added custom title/label management for Privacy Policy and Terms & Conditions across global defaults and per-language overrides.
* Dynamically connected localized legal titles to CookieConsent banner and modal footers.
* Fixed translation JSON export: eliminated raw page rendering by enforcing output buffer cleanup, binary download headers, and HTML5 download attribute.
* Fixed CSV and diagnostics JSON export handlers to support direct one-click browser download.
* Bumped runtime compilation cache version to v122.

### = 1.2.1 =
* Fixed FormsAdapter fatal TypeError on WPForms and Fluent Forms by converting filters to type-safe action hooks.
* Fixed Contact Form 7 captcha consent notice placement before form submit button.
* Fixed Elementor iframe blocking during editor and preview modes, preserving builder usability.
* Fixed iframe enforcement to strictly respect both category and granular service acceptance.
* Fixed iframe re-blocking and reset on consent revocation.
* Fixed Google Consent Mode v2 cache safety: non-essential default signals are denied in HTML and restored early via client-side cookie validation with revision checks.
* Fixed script enforcer module preservation (`type="module"`) and added immune handles for WooCommerce core scripts.
* Fixed RecipeRegistry to respect user-customized category assignments.
* Fixed consent logging rate limiting, UUID validation, allowlist category filtering, and 403 expired nonce auto-refresh retry.
* Fixed translation keys sanitization in TranslationManager to preserve camelCase for CookieConsent v3.
* Fixed CSV formula injection vulnerability and added dedicated admin-post export handlers for audit logs, translations, and diagnostics.
* Fixed Compiler transient caching to be locale-dependent and respect language settings.
* Fixed WooCommerce adapter essential cookie injection hook.
* Fixed CacheCompatibility to eliminate redundant `admin_init` database writes.
* Fixed CookieScanner to enforce SSL verification by default and accurately flag Google Fonts management.
* Enhanced multisite network activation, deactivation cron cleanup, and uninstall data purging.
* Added full unmodified GNU GPLv2 license documentation.

### = 1.2.0 =
* Added Website Tracker & Cookie Scanner with active service crawl and cookie mapping inventory.
* Added 22 Global Service Recipes catalogue with one-click preferences synchronization.
* Added real-time Design Preset & Surface Color Customizer with zero-delay live frame synchronizer.
* Refactored admin styling architecture to fully eliminate template inline CSS and optimize Materio compatibility.
* Implemented dynamic asset cache-busting via file modification timestamps.

### = 1.1.0 =
* Enhanced Polylang & WPML multi-language policy URL resolver.
* Added granular cookie inventory tables in visitor preferences modal.
* Upgraded privacy trigger icon library with native SVG cookie icons.

### = 1.0.0 =
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

---

## ⚖ Credits & Licenses

- **Tuedion Cookie** is licensed under the [GNU General Public License v2.0 or later](LICENSE).
- Powered by [Orest Bida's CookieConsent](https://github.com/orestbida/cookieconsent) (MIT License) and [IframeManager](https://github.com/orestbida/iframemanager) (MIT License).
- Designed and maintained by [Tuedion](https://tuedion.com).

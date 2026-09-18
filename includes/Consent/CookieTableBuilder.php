<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Consent;

use Tuedion\CookieConsent\Diagnostics\CookieScanner;
use Tuedion\CookieConsent\Integrations\Adapters\WooCommerceAdapter;
use Tuedion\CookieConsent\Integrations\RecipeRegistry;
use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds localized cookieTable specifications for CookieConsent v3 preferencesModal sections.
 *
 * Adheres strictly to international CMP & GDPR/ePrivacy standards (OneTrust, Cookiebot, Usercentrics):
 * ONLY cookies and tracking services that are actually present on this site (configured by admin
 * or detected by the site crawler) are declared to visitors. Unused catalogue presets are NEVER
 * displayed as ghost cookies.
 */
final class CookieTableBuilder
{
    /**
     * Localized table headers across 9 languages.
     */
    private const HEADERS = [
        'en' => [
            'name'     => 'Cookie',
            'domain'   => 'Domain',
            'duration' => 'Duration',
            'desc'     => 'Description',
        ],
        'tr' => [
            'name'     => 'Çerez',
            'domain'   => 'Alan Adı',
            'duration' => 'Süre',
            'desc'     => 'Açıklama',
        ],
        'de' => [
            'name'     => 'Cookie',
            'domain'   => 'Domain',
            'duration' => 'Dauer',
            'desc'     => 'Beschreibung',
        ],
        'fr' => [
            'name'     => 'Cookie',
            'domain'   => 'Domaine',
            'duration' => 'Durée',
            'desc'     => 'Description',
        ],
        'es' => [
            'name'     => 'Cookie',
            'domain'   => 'Dominio',
            'duration' => 'Duración',
            'desc'     => 'Descripción',
        ],
        'it' => [
            'name'     => 'Cookie',
            'domain'   => 'Dominio',
            'duration' => 'Durata',
            'desc'     => 'Descrizione',
        ],
        'nl' => [
            'name'     => 'Cookie',
            'domain'   => 'Domein',
            'duration' => 'Bewaartermijn',
            'desc'     => 'Beschrijving',
        ],
        'ar' => [
            'name'     => 'ملف تعريف الارتباط',
            'domain'   => 'النطاق',
            'duration' => 'المدة',
            'desc'     => 'الوصف',
        ],
        'ru' => [
            'name'     => 'Файл cookie',
            'domain'   => 'Домен',
            'duration' => 'Срок действия',
            'desc'     => 'Описание',
        ],
    ];

    /**
     * Localized duration labels across 9 languages.
     */
    public const DURATIONS = [
        'session' => [
            'en' => 'Session',
            'tr' => 'Oturum',
            'de' => 'Sitzung',
            'fr' => 'Session',
            'es' => 'Sesión',
            'it' => 'Sessione',
            'nl' => 'Sessie',
            'ar' => 'جلسة',
            'ru' => 'Сессия',
        ],
        '1min' => [
            'en' => '1 minute',
            'tr' => '1 dakika',
            'de' => '1 Minute',
            'fr' => '1 minute',
            'es' => '1 minuto',
            'it' => '1 minuto',
            'nl' => '1 minuut',
            'ar' => 'دقيقة واحدة',
            'ru' => '1 минута',
        ],
        '24h' => [
            'en' => '24 hours',
            'tr' => '24 saat',
            'de' => '24 Stunden',
            'fr' => '24 heures',
            'es' => '24 horas',
            'it' => '24 ore',
            'nl' => '24 uur',
            'ar' => '24 ساعة',
            'ru' => '24 часа',
        ],
        '48h' => [
            'en' => '48 hours',
            'tr' => '48 saat',
            'de' => '48 Stunden',
            'fr' => '48 heures',
            'es' => '48 horas',
            'it' => '48 ore',
            'nl' => '48 uur',
            'ar' => '48 ساعة',
            'ru' => '48 часов',
        ],
        '1m' => [
            'en' => '1 month',
            'tr' => '1 ay',
            'de' => '1 Monat',
            'fr' => '1 mois',
            'es' => '1 mes',
            'it' => '1 mese',
            'nl' => '1 maand',
            'ar' => 'شهر واحد',
            'ru' => '1 месяц',
        ],
        '90d' => [
            'en' => '90 days',
            'tr' => '90 gün',
            'de' => '90 Tage',
            'fr' => '90 jours',
            'es' => '90 días',
            'it' => '90 giorni',
            'nl' => '90 dagen',
            'ar' => '90 يوماً',
            'ru' => '90 дней',
        ],
        '180d' => [
            'en' => '6 months',
            'tr' => '6 ay',
            'de' => '6 Monate',
            'fr' => '6 mois',
            'es' => '6 meses',
            'it' => '6 mesi',
            'nl' => '6 maanden',
            'ar' => '6 أشهر',
            'ru' => '6 месяцев',
        ],
        '1y' => [
            'en' => '1 year',
            'tr' => '1 yıl',
            'de' => '1 Jahr',
            'fr' => '1 an',
            'es' => '1 año',
            'it' => '1 anno',
            'nl' => '1 jaar',
            'ar' => 'سنة واحدة',
            'ru' => '1 год',
        ],
        '13m' => [
            'en' => '13 months',
            'tr' => '13 ay',
            'de' => '13 Monate',
            'fr' => '13 mois',
            'es' => '13 meses',
            'it' => '13 mesi',
            'nl' => '13 maanden',
            'ar' => '13 شهراً',
            'ru' => '13 месяцев',
        ],
        '2y' => [
            'en' => '2 years',
            'tr' => '2 yıl',
            'de' => '2 Jahre',
            'fr' => '2 ans',
            'es' => '2 años',
            'it' => '2 anni',
            'nl' => '2 jaar',
            'ar' => 'سنتان',
            'ru' => '2 года',
        ],
        'persistent' => [
            'en' => 'Persistent',
            'tr' => 'Kalıcı',
            'de' => 'Dauerhaft',
            'fr' => 'Persistant',
            'es' => 'Persistente',
            'it' => 'Persistente',
            'nl' => 'Permanent',
            'ar' => 'دائم',
            'ru' => 'Постоянный',
        ],
    ];

    /**
     * Resolve localized duration string from key.
     */
    public static function resolveDuration(string $durationKey, string $lang): string
    {
        $lang = strtolower($lang);
        if (isset(self::DURATIONS[$durationKey])) {
            return self::DURATIONS[$durationKey][$lang] ?? self::DURATIONS[$durationKey]['en'] ?? $durationKey;
        }
        return $durationKey;
    }

    /**
     * Multilingual Cookie Knowledge Base / Dictionary for enriching declared cookies.
     *
     * @var array<string, array{domain_type: string, duration: string, desc: array<string, string>}>
     */
    private const COOKIE_DICTIONARY = [
        // 1. Core Platform & Necessary
        'cc_cookie' => [
            'domain_type' => 'current',
            'duration'    => '180d',
            'desc' => [
                'en' => 'Stores visitor cookie consent choices, category permissions, and revision status.',
                'tr' => 'Kullanıcının çerez izin tercihlerini ve revizyon durumunu saklar.',
                'de' => 'Speichert die Cookie-Einwilligungsentscheidungen des Nutzers.',
                'fr' => 'Enregistre les choix de consentement des cookies de l\'utilisateur.',
                'es' => 'Guarda las preferencias de consentimiento de cookies del usuario.',
                'it' => 'Memorizza le preferenze di consenso ai cookie dell\'utente.',
                'nl' => 'Bewaart de keuzes van de gebruiker voor cookie-toestemming.',
                'ar' => 'يخزن خيارات موافقة المستخدم على ملفات تعريف الارتباط.',
                'ru' => 'Сохраняет выбор согласия пользователя на использование файлов cookie.',
            ],
        ],
        'wordpress_logged_in_*' => [
            'domain_type' => 'current',
            'duration'    => 'session',
            'desc' => [
                'en' => 'WordPress session state for authenticated user authentication.',
                'tr' => 'Giriş yapmış kullanıcılar için WordPress oturum doğrulamasını sağlar.',
                'de' => 'WordPress-Sitzungsstatus für authentifizierte Benutzer.',
                'fr' => 'État de session WordPress pour les utilisateurs authentifiés.',
                'es' => 'Estado de sesión de WordPress para autenticación de usuarios.',
                'it' => 'Stato della sessione di WordPress per l\'autenticazione.',
                'nl' => 'WordPress-sessiestatus voor geverifieerde gebruikers.',
                'ar' => 'حالة جلسة ووردبريس لمصادقة المستخدم.',
                'ru' => 'Сессия WordPress для аутентификации пользователя.',
            ],
        ],
        'wp-settings-*' => [
            'domain_type' => 'current',
            'duration'    => '1y',
            'desc' => [
                'en' => 'Customizes user view of admin interface and main site UI state.',
                'tr' => 'Kullanıcı arayüzü ve görünüm tercihlerini saklar.',
                'de' => 'Speichert individuelle Einstellungen für die Benutzeroberfläche.',
                'fr' => 'Personnalise l\'affichage de l\'interface pour l\'utilisateur.',
                'es' => 'Personaliza las preferencias de la interfaz de usuario.',
                'it' => 'Personalizza la visualizzazione dell\'interfaccia utente.',
                'nl' => 'Onthoudt weergavevoorkeuren van de gebruiker.',
                'ar' => 'يخصص تفضيلات واجهة المستخدم.',
                'ru' => 'Сохраняет настройки пользовательского интерфейса.',
            ],
        ],
        'woocommerce_items_in_cart' => [
            'domain_type' => 'current',
            'duration'    => 'session',
            'desc' => [
                'en' => 'Helps WooCommerce determine when cart contents and session change.',
                'tr' => 'Sepet içeriğinin ve oturumun ne zaman değiştiğini takip eder.',
                'de' => 'Hilft WooCommerce festzustellen, wann sich der Warenkorbinhalt ändert.',
                'fr' => 'Aide WooCommerce à déterminer quand le panier change.',
                'es' => 'Ayuda a WooCommerce a determinar cuándo cambia el contenido del carrito.',
                'it' => 'Aiuta WooCommerce a determinare le modifiche al carrello.',
                'nl' => 'Helpt WooCommerce te bepalen wanneer de winkelwageninhoud verandert.',
                'ar' => 'يساعد ووكومرس على تحديد وقت تغير محتويات سلة التسوق.',
                'ru' => 'Помогает WooCommerce определять изменения содержимого корзины.',
            ],
        ],
        'wp_woocommerce_session_*' => [
            'domain_type' => 'current',
            'duration'    => '48h',
            'desc' => [
                'en' => 'Contains a unique code for each customer to find cart data in the database.',
                'tr' => 'Her müşteri için sepetteki sipariş verilerini eşleştiren benzersiz kod içerir.',
                'de' => 'Enthält einen eindeutigen Code für jeden Kunden, um Warenkorbdaten zu finden.',
                'fr' => 'Contient un identifiant unique pour lier le panier du client en base de données.',
                'es' => 'Contiene un código único para cada cliente para vincular los datos del carrito.',
                'it' => 'Contiene un codice univoco per ciascun cliente per trovare i dati del carrello.',
                'nl' => 'Bevat een unieke code voor elke klant om winkelwagengegevens te koppelen.',
                'ar' => 'يحتوي على رمز فريد لكل عميل لمطابقة بيانات السلة.',
                'ru' => 'Содержит уникальный код для связывания данных корзины покупателя.',
            ],
        ],
        'woocommerce_cart_hash' => [
            'domain_type' => 'current',
            'duration'    => 'session',
            'desc' => [
                'en' => 'Cryptographic hash of the shopping basket to prevent stale checkout cache.',
                'tr' => 'Sepet içeriğinin şifrelenmiş özeti; önbellek çakışmalarını önler.',
                'de' => 'Kryptografischer Hash des Warenkorbs zur Vermeidung von Cache-Problemen.',
                'fr' => 'Empreinte cryptographique du panier pour éviter les conflits de cache.',
                'es' => 'Hash criptográfico del carrito para evitar conflictos de caché.',
                'it' => 'Hash crittografico del carrello per evitare conflitti di cache.',
                'nl' => 'Cryptografische hash van de winkelwagen om cacheproblemen te voorkomen.',
                'ar' => 'تجزئة تشفيرية لسلة التسوق لتجنب أخطاء التخزين المؤقت.',
                'ru' => 'Хеш корзины для предотвращения конфликтов кеширования.',
            ],
        ],

        // 2. Language & Functionality
        'pll_language' => [
            'domain_type' => 'current',
            'duration'    => '1y',
            'desc' => [
                'en' => 'Remembers visitor chosen language preference across site visits.',
                'tr' => 'Ziyaretçinin seçtiği dil tercihini sonraki ziyaretlerde hatırlar.',
                'de' => 'Speichert die vom Besucher gewählte Spracheinstellung.',
                'fr' => 'Mémorise la préférence linguistique choisie par le visiteur.',
                'es' => 'Recuerda la preferencia de idioma seleccionada por el visitante.',
                'it' => 'Ricorda la preferenza di lingua selezionata dal visitatore.',
                'nl' => 'Onthoudt de door de bezoeker gekozen taalvoorkeur.',
                'ar' => 'يتذكر تفضيل اللغة الذي اختاره الزائر.',
                'ru' => 'Запоминает выбранный язык сайта.',
            ],
        ],
        '_icl_visitor_lang_js' => [
            'domain_type' => 'current',
            'duration'    => '24h',
            'desc' => [
                'en' => 'Stores visitor redirect language for WPML multilingual navigation.',
                'tr' => 'WPML çok dilli yönlendirmeler için ziyaretçi dilini saklar.',
                'de' => 'Speichert die Besuchersprache für WPML-Weiterleitungen.',
                'fr' => 'Enregistre la langue du visiteur pour les redirections WPML.',
                'es' => 'Guarda el idioma del visitante para redirecciones WPML.',
                'it' => 'Memorizza la lingua del visitatore per i reindirizzamenti WPML.',
                'nl' => 'Bewaart de taal van de bezoeker voor WPML-doorverwijzingen.',
                'ar' => 'يخزن لغة الزائر لإعادة توجيه WPML متعدد اللغات.',
                'ru' => 'Сохраняет язык посетителя для перенаправлений WPML.',
            ],
        ],

        // 3. Analytics
        '_ga' => [
            'domain_type' => 'current',
            'duration'    => '2y',
            'desc' => [
                'en' => 'Google Analytics identifier used to distinguish unique visitors.',
                'tr' => 'Google Analytics tekil ziyaretçileri ayırt etmek için benzersiz kimlik saklar.',
                'de' => 'Google Analytics-Identifikator zur Unterscheidung einzelner Besucher.',
                'fr' => 'Identifiant Google Analytics permettant de distinguer les visiteurs uniques.',
                'es' => 'Identificador de Google Analytics utilizado para distinguir visitantes únicos.',
                'it' => 'Identificatore di Google Analytics per distinguere i visitatori unici.',
                'nl' => 'Google Analytics-ID om unieke bezoekers te onderscheiden.',
                'ar' => 'معرف غوغل أناليتكس لتمييز الزوار الفريدين.',
                'ru' => 'Идентификатор Google Analytics для различения уникальных посетителей.',
            ],
        ],
        '_gid' => [
            'domain_type' => 'current',
            'duration'    => '24h',
            'desc' => [
                'en' => 'Used by Google Analytics to persist session state over a 24-hour window.',
                'tr' => 'Google Analytics oturum durumunu 24 saatlik pencerede takip etmek için kullanılır.',
                'de' => 'Wird von Google Analytics verwendet, um den Sitzungsstatus über 24 Stunden zu speichern.',
                'fr' => 'Utilisé par Google Analytics pour maintenir la session sur 24 heures.',
                'es' => 'Utilizado por Google Analytics para mantener la sesión durante 24 horas.',
                'it' => 'Utilizzato da Google Analytics per mantenere la sessione per 24 ore.',
                'nl' => 'Gebruikt door Google Analytics om de sessiestatus 24 uur vast te houden.',
                'ar' => 'يستخدمه غوغل أناليتكس للحفاظ على حالة الجلسة خلال 24 ساعة.',
                'ru' => 'Используется Google Analytics для отслеживания сессии в течение 24 часов.',
            ],
        ],
        '_ga_*' => [
            'domain_type' => 'current',
            'duration'    => '2y',
            'desc' => [
                'en' => 'Maintains session state and campaign parameters for Google Analytics 4.',
                'tr' => 'Google Analytics 4 oturum durumunu ve kampanya verilerini takip eder.',
                'de' => 'Speichert den Sitzungsstatus und Kampagnendaten für Google Analytics 4.',
                'fr' => 'Maintient l\'état de la session et les données de campagne pour Google Analytics 4.',
                'es' => 'Mantiene el estado de la session y datos de campaña para Google Analytics 4.',
                'it' => 'Mantiene lo stato della sessione e i parametri di campagna per GA4.',
                'nl' => 'Behoudt de sessiestatus en campagneparameters voor Google Analytics 4.',
                'ar' => 'يحافظ على حالة الجلسة ومعلمات الحملة لـ GA4.',
                'ru' => 'Сохраняет состояние сессии для Google Analytics 4.',
            ],
        ],
        '_gat' => [
            'domain_type' => 'current',
            'duration'    => '1min',
            'desc' => [
                'en' => 'Used to throttle request rate to Google Analytics servers on high-traffic sites.',
                'tr' => 'Google Analytics sunucularına giden istek oranını sınırlamak için kullanılır.',
                'de' => 'Dient zur Drosselung der Anfragerate an die Google Analytics-Server.',
                'fr' => 'Utilisé pour limiter le taux de requêtes vers les serveurs Google Analytics.',
                'es' => 'Utilizado para limitar la tasa de peticiones a los servidores de GA.',
                'it' => 'Utilizzato per limitare la frequenza delle richieste ai server GA.',
                'nl' => 'Gebruikt om de verzoeksnelheid naar Google Analytics-servers te beperken.',
                'ar' => 'يُستخدم لتحديد معدل الطلبات إلى خوادم غوغل أناليتكس.',
                'ru' => 'Используется для ограничения частоты запросов к Google Analytics.',
            ],
        ],
        '_gcl_au' => [
            'domain_type' => 'current',
            'duration'    => '90d',
            'desc' => [
                'en' => 'Used by Google Tag Manager and AdSense to store conversion experiments.',
                'tr' => 'Google Tag Manager ve AdSense dönüşüm deneylerini saklar.',
                'de' => 'Wird von Google Tag Manager für Conversion-Experimente verwendet.',
                'fr' => 'Utilisé par GTM pour mesurer l\'efficacité des conversions publicitaires.',
                'es' => 'Utilizado por GTM para almacenar experimentos de conversión.',
                'it' => 'Utilizzato da GTM per memorizzare esperimenti di conversione.',
                'nl' => 'Gebruikt door GTM om conversie-experimenten op te slaan.',
                'ar' => 'يستخدمه غوغل تاج مانجر لتخزين تجارب التحويل.',
                'ru' => 'Используется Google Tag Manager для отслеживания конверсий.',
            ],
        ],
        '_clck' => [
            'domain_type' => 'current',
            'duration'    => '1y',
            'desc' => [
                'en' => 'Microsoft Clarity cookie persisting user ID and heatmap session unique to site.',
                'tr' => 'Microsoft Clarity kullanıcı kimliğini ve ısı haritası oturumunu saklar.',
                'de' => 'Microsoft Clarity-Cookie zur Speicherung der Nutzer-ID für Heatmaps.',
                'fr' => 'Cookie Microsoft Clarity pour l\'analyse comportementale et cartes de chaleur.',
                'es' => 'Cookie de Microsoft Clarity para análisis de comportamiento y mapas de calor.',
                'it' => 'Cookie di Microsoft Clarity per analisi comportamentale e mappe di calore.',
                'nl' => 'Microsoft Clarity-cookie voor gedragsanalyse en heatmaps.',
                'ar' => 'ملف تعريف ارتباط مايكروسوفت كلاريتي لتحليل السلوك والخرائط الحرارية.',
                'ru' => 'Файл cookie Microsoft Clarity для записи сессий и тепловых карт.',
            ],
        ],
        '_clsk' => [
            'domain_type' => 'current',
            'duration'    => '24h',
            'desc' => [
                'en' => 'Connects multiple page views by a user into a single Clarity session record.',
                'tr' => 'Birden fazla sayfa görüntülemesini tek bir Clarity oturumunda birleştirir.',
                'de' => 'Verbindet mehrere Seitenaufrufe zu einer einzigen Clarity-Sitzung.',
                'fr' => 'Relie plusieurs vues de page en un enregistrement de session Clarity.',
                'es' => 'Conecta múltiples vistas de página en una sola sesión de Clarity.',
                'it' => 'Collega più visualizzazioni di pagina in una singola sessione Clarity.',
                'nl' => 'Voegt meerdere paginaweergaven samen in één Clarity-sessie.',
                'ar' => 'يدمج مشاهدات الصفحات المتعددة في جلسة كلاريتي واحدة.',
                'ru' => 'Объединяет просмотры страниц в одну сессию Clarity.',
            ],
        ],
        '_hjSessionUser_*' => [
            'domain_type' => 'current',
            'duration'    => '1y',
            'desc' => [
                'en' => 'Hotjar cookie that persists user journey and unique ID across visits.',
                'tr' => 'Hotjar kullanıcı kimliğini ve gezinme geçmişini saklar.',
                'de' => 'Hotjar-Cookie zur Speicherung der Nutzer-ID über Besuche hinweg.',
                'fr' => 'Cookie Hotjar conservant l\'identifiant utilisateur entre les visites.',
                'es' => 'Cookie de Hotjar que persiste el ID de usuario entre visitas.',
                'it' => 'Cookie Hotjar che memorizza l\'ID utente tra le visite.',
                'nl' => 'Hotjar-cookie die het gebruikers-ID tussen bezoeken bewaart.',
                'ar' => 'ملف تعريف ارتباط هوتجار لحفظ معرف المستخدم عبر الزيارات.',
                'ru' => 'Файл cookie Hotjar для сохранения идентификатора пользователя.',
            ],
        ],

        // 4. Marketing
        '_fbp' => [
            'domain_type' => 'current',
            'duration'    => '90d',
            'desc' => [
                'en' => 'Meta (Facebook) Pixel cookie for conversion tracking and personalized ad delivery.',
                'tr' => 'Meta (Facebook) Pixel dönüşüm takibi ve reklam kişiselleştirmesi için kullanılır.',
                'de' => 'Meta (Facebook) Pixel-Cookie zur Conversion-Messung und Werbeanpassung.',
                'fr' => 'Cookie Meta (Facebook) Pixel pour le suivi des conversions publicitaires.',
                'es' => 'Cookie de Meta (Facebook) Pixel para seguimiento de conversiones y anuncios.',
                'it' => 'Cookie Meta (Facebook) Pixel per il tracciamento delle conversioni pubblicitarie.',
                'nl' => 'Meta (Facebook) Pixel-cookie voor conversiemeting en advertenties.',
                'ar' => 'ملف تعريف ارتباط ميتا (فيسبوك) لتتبع التحويلات والإعلانات المخصصة.',
                'ru' => 'Файл cookie Meta (Facebook) Pixel для отслеживания конверсий и рекламы.',
            ],
        ],
        '_fbc' => [
            'domain_type' => 'current',
            'duration'    => '90d',
            'desc' => [
                'en' => 'Stores last click identifier for Meta conversion attribution and retargeting.',
                'tr' => 'Meta reklam tıklama kimliğini ve dönüşüm ilişkilendirmesini saklar.',
                'de' => 'Speichert den letzten Klick-Identifikator für Meta-Werbezuordnungen.',
                'fr' => 'Enregistre le dernier clic pour l\'attribution publicitaire Meta.',
                'es' => 'Guarda el identificador de clic para atribución de anuncios Meta.',
                'it' => 'Memorizza l\'identificatore dell\'ultimo clic per l\'attribuzione Meta.',
                'nl' => 'Bewaart de laatste klik-ID voor Meta-conversietoewijzing.',
                'ar' => 'يخزن معرف النقرة الأخيرة لإسناد إعلانات ميتا.',
                'ru' => 'Сохраняет идентификатор клика для атрибуции рекламы Meta.',
            ],
        ],
        'VISITOR_INFO1_LIVE' => [
            'domain_type' => 'youtube.com',
            'duration'    => '180d',
            'desc' => [
                'en' => 'Estimates bandwidth on pages with embedded YouTube video players.',
                'tr' => 'Gömülü YouTube video oynatıcılarında bant genişliğini ölçer.',
                'de' => 'Schätzt die Bandbreite auf Seiten mit eingebetteten YouTube-Videos.',
                'fr' => 'Mesure la bande passante sur les pages intégrant des vidéos YouTube.',
                'es' => 'Estima el ancho de banda en páginas con reproductores de YouTube.',
                'it' => 'Stima la larghezza di banda nelle pagine con video YouTube incorporati.',
                'nl' => 'Schat de bandbreedte op pagina\'s met ingesloten YouTube-video\'s.',
                'ar' => 'يقيس النطاق الترددي على الصفحات التي تتضمن مقاطع يوتيوب.',
                'ru' => 'Оценивает пропускную способность для встроенных видео YouTube.',
            ],
        ],
        'YSC' => [
            'domain_type' => 'youtube.com',
            'duration'    => 'session',
            'desc' => [
                'en' => 'Stores unique identifier to track statistics of viewed embedded YouTube videos.',
                'tr' => 'Gömülü YouTube videolarının görüntüleme istatistiklerini takip eder.',
                'de' => 'Speichert eine ID zur Erfassung von Statistiken angesehener Videos.',
                'fr' => 'Enregistre un identifiant pour suivre les statistiques de visionnage YouTube.',
                'es' => 'Guarda un ID único para registrar estadísticas de vídeos vistos en YouTube.',
                'it' => 'Memorizza un ID per tracciare le statistiche dei video visualizzati.',
                'nl' => 'Slaat een unieke ID op voor statistieken van bekeken YouTube-video\'s.',
                'ar' => 'يخزن معرفاً فريداً لتتبع إحصائيات مقاطع فيديو يوتيوب المشاهدة.',
                'ru' => 'Сохраняет уникальный ID для статистики просмотров видео YouTube.',
            ],
        ],
        '_ttp' => [
            'domain_type' => 'current',
            'duration'    => '13m',
            'desc' => [
                'en' => 'Tracks performance of TikTok advertising campaigns and visitor event actions.',
                'tr' => 'TikTok reklam kampanyalarının performansını ve dönüşümlerini ölçer.',
                'de' => 'TikTok Pixel-Cookie zur Messung von Werbekampagnen und Aktionen.',
                'fr' => 'Mesure la performance des campagnes publicitaires et événements TikTok.',
                'es' => 'Mide el rendimiento de campañas y acciones de eventos de TikTok.',
                'it' => 'Misura le prestazioni delle campagne e azioni pubblicitarie TikTok.',
                'nl' => 'Meet de prestaties van TikTok-advertentiecampagnes en acties.',
                'ar' => 'يقيس أداء حملات تيك توك الإعلانية وإجراءات الزوار.',
                'ru' => 'Оценивает эффективность рекламных кампаний TikTok Pixel.',
            ],
        ],
    ];

    /**
     * Build cookieTable structure for a specific category and language.
     *
     * @param string $categoryId
     * @param string $langCode
     * @param bool $includeAll When false, only cookies active for the current visitor context are declared.
     * @return array{caption: string, headers: array<string, string>, body: list<array<string, string>>}|null
     */
    public static function build(string $categoryId, string $langCode, bool $includeAll = false): ?array
    {
        $lang = strtolower($langCode);
        $headers = self::HEADERS[$lang] ?? self::HEADERS['en'];

        $currentDomain = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($currentDomain) || empty($currentDomain)) {
            $currentDomain = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost';
        }

        $body = [];
        $seen = [];

        // ---------------------------------------------------------------------
        // 1. CORE PLATFORM COOKIES (Only for strictly necessary or active CMS plugins)
        // ---------------------------------------------------------------------
        if ($categoryId === 'necessary') {
            $coreNecessary = ['cc_cookie'];

            // Authentication & admin UI state cookies must NEVER be declared to guest / unauthenticated visitors
            if (is_user_logged_in() || $includeAll) {
                $coreNecessary[] = 'wordpress_logged_in_*';
                $coreNecessary[] = 'wp-settings-*';
            }

            if (WooCommerceAdapter::isActive() || class_exists('WooCommerce')) {
                $coreNecessary[] = 'woocommerce_items_in_cart';
                $coreNecessary[] = 'wp_woocommerce_session_*';
                $coreNecessary[] = 'woocommerce_cart_hash';
            }

            foreach ($coreNecessary as $name) {
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $dict = self::COOKIE_DICTIONARY[$name] ?? null;
                $desc = $dict['desc'][$lang] ?? $dict['desc']['en'] ?? __('Essential system cookie.', 'tuedion-cookie');
                $durKey = $dict['duration'] ?? 'session';
                $body[] = [
                    'name'     => $name,
                    'domain'   => $currentDomain,
                    'duration' => self::resolveDuration($durKey, $lang),
                    'desc'     => $desc,
                ];
            }
        } elseif ($categoryId === 'functionality') {
            // Check Polylang
            if (defined('POLYLANG_VERSION') || function_exists('pll_current_language')) {
                $seen['pll_language'] = true;
                $dict = self::COOKIE_DICTIONARY['pll_language'] ?? null;
                $durKey = $dict['duration'] ?? '1y';
                $body[] = [
                    'name'     => 'pll_language',
                    'domain'   => $currentDomain,
                    'duration' => self::resolveDuration($durKey, $lang),
                    'desc'     => $dict['desc'][$lang] ?? $dict['desc']['en'] ?? __('Stores chosen language.', 'tuedion-cookie'),
                ];
            }

            // Check WPML
            if (defined('ICL_SITEPRESS_VERSION')) {
                $seen['_icl_visitor_lang_js'] = true;
                $dict = self::COOKIE_DICTIONARY['_icl_visitor_lang_js'] ?? null;
                $durKey = $dict['duration'] ?? '24h';
                $body[] = [
                    'name'     => '_icl_visitor_lang_js',
                    'domain'   => $currentDomain,
                    'duration' => self::resolveDuration($durKey, $lang),
                    'desc'     => $dict['desc'][$lang] ?? $dict['desc']['en'] ?? __('Stores chosen language.', 'tuedion-cookie'),
                ];
            }
        }

        // ---------------------------------------------------------------------
        // 2. CONFIGURED TRACKING SERVICES ($settings['services'])
        // ---------------------------------------------------------------------
        $settings = Repository::getSettings();
        $configuredServices = (array) ($settings['services'] ?? []);

        foreach ($configuredServices as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            $svcCat = (string) ($svc['category'] ?? '');
            if ($svcCat === 'functional') {
                $svcCat = 'functionality';
            }
            if ($svcCat !== $categoryId) {
                continue;
            }

            $svcId = (string) ($svc['id'] ?? '');
            $recipe = RecipeRegistry::get($svcId);

            // Determine cookies to declare for this service
            $cookieNames = [];
            if (!empty($svc['cookies']) && is_array($svc['cookies'])) {
                $cookieNames = $svc['cookies'];
            } elseif (!empty($recipe['auto_clear'])) {
                $cookieNames = (array) $recipe['auto_clear'];
            }

            foreach ($cookieNames as $raw) {
                $cleanName = trim(str_replace(['/^', '/', '\\'], '', (string) $raw));
                if ($cleanName === '' || isset($seen[$cleanName])) {
                    continue;
                }
                $seen[$cleanName] = true;

                // Lookup in multilingual dictionary
                $dict = self::COOKIE_DICTIONARY[$cleanName] ?? null;
                if ($dict !== null) {
                    $desc   = $dict['desc'][$lang] ?? $dict['desc']['en'] ?? '';
                    $domain = $dict['domain_type'] === 'current' ? $currentDomain : $dict['domain_type'];
                    $durKey = $dict['duration'] ?? '1y';
                } else {
                    /* translators: %s: Service name or identifier */
                    $fallbackDesc = sprintf(__('Cookie used by %s.', 'tuedion-cookie'), $svc['label'] ?? $svcId);
                    $desc   = (string) ($recipe['description'] ?? $fallbackDesc);
                    $domain = $currentDomain;
                    $durKey = (string) ($recipe['duration'] ?? '1y');
                }

                $body[] = [
                    'name'     => $cleanName,
                    'domain'   => $domain,
                    'duration' => self::resolveDuration($durKey, $lang),
                    'desc'     => $desc,
                ];
            }
        }

        // ---------------------------------------------------------------------
        // 3. SCANNED & DETECTED COOKIES / SERVICES (Live site discovery)
        // ---------------------------------------------------------------------
        $scanResults = CookieScanner::getResults();
        $detectedCookies = (array) ($scanResults['detected_cookies'] ?? []);

        foreach ($detectedCookies as $dc) {
            if (!is_array($dc)) {
                continue;
            }
            $dcCat = (string) ($dc['category'] ?? '');
            if ($dcCat === 'functional') {
                $dcCat = 'functionality';
            }
            if ($dcCat !== $categoryId) {
                continue;
            }

            $name = (string) ($dc['name'] ?? '');
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            // Never leak logged-in session cookies to guest / unauthenticated visitors, even if present in scan history
            if (($name === 'wordpress_logged_in_*' || str_starts_with($name, 'wordpress_logged_in') || str_starts_with($name, 'wp-settings-')) && !is_user_logged_in() && !$includeAll) {
                continue;
            }

            $seen[$name] = true;

            // Check dictionary for translated description first
            $dict = self::COOKIE_DICTIONARY[$name] ?? null;
            if ($dict !== null) {
                $desc   = $dict['desc'][$lang] ?? $dict['desc']['en'] ?? '';
                $domain = $dict['domain_type'] === 'current' ? $currentDomain : $dict['domain_type'];
                $durKey = $dict['duration'] ?? 'session';
            } else {
                $desc   = (string) ($dc['description'] ?? __('Detected tracking cookie.', 'tuedion-cookie'));
                $domain = (string) ($dc['domain'] ?? $currentDomain);
                $durKey = (string) ($dc['duration'] ?? 'session');
            }

            $body[] = [
                'name'     => $name,
                'domain'   => $domain,
                'duration' => self::resolveDuration($durKey, $lang),
                'desc'     => $desc,
            ];
        }

        // ---------------------------------------------------------------------
        // 4. ZERO-GHOST VERIFICATION: Return null if category has no active cookies
        // ---------------------------------------------------------------------
        if (empty($body)) {
            return null;
        }

        /**
         * Filter generated cookie table rows.
         *
         * @param list<array<string, string>> $body
         * @param string $categoryId
         * @param string $langCode
         */
        $body = (array) apply_filters('tuedion_cookie_table_body', $body, $categoryId, $langCode);

        $caption = ($lang === 'tr')
            ? 'Bu kategoride kullanılan çerezlerin listesi'
            : 'List of cookies used in this category';

        return [
            'caption' => $caption,
            'headers' => $headers,
            'body'    => $body,
        ];
    }
}


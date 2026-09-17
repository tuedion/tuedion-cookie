<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\I18n;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Built-in production-grade language packs for 9 major languages.
 * Covers consentModal and preferencesModal with localized category sections.
 */
final class LanguagePacks
{
    public const SUPPORTED_LANGUAGES = [
        'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
        'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'dir' => 'ltr'],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'dir' => 'ltr'],
        'fr' => ['name' => 'French', 'native' => 'Français', 'dir' => 'ltr'],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'dir' => 'ltr'],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'dir' => 'ltr'],
        'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'dir' => 'ltr'],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl'],
        'ru' => ['name' => 'Russian', 'native' => 'Русский', 'dir' => 'ltr'],
    ];

    /**
     * Check if a language code is directly supported by built-in packs.
     */
    public static function isSupported(string $langCode): bool
    {
        return array_key_exists(strtolower($langCode), self::SUPPORTED_LANGUAGES);
    }

    /**
     * Get dictionary for a specific language or return English fallback.
     *
     * @param string $langCode
     * @param list<array<string, mixed>> $categories
     * @return array<string, mixed>
     */
    public static function getPack(string $langCode, array $categories = []): array
    {
        $code = strtolower($langCode);
        $packs = self::getAll($categories);

        return $packs[$code] ?? $packs['en'];
    }

    /**
     * Build all 9 language packs populated with dynamic category sections.
     *
     * @param list<array<string, mixed>> $categories
     * @return array<string, array<string, mixed>>
     */
    public static function getAll(array $categories = []): array
    {
        $packs = [
            // 1. ENGLISH (EN)
            'en' => [
                'consentModal' => [
                    'title'              => 'We value your privacy',
                    'description'        => 'This site uses cookies to optimize your experience, analyze traffic, and display personalized content.',
                    'acceptAllBtn'       => 'Accept All',
                    'acceptNecessaryBtn' => 'Reject Non-Essential',
                    'showPreferencesBtn' => 'Manage Preferences',
                    'footer'             => self::buildFooter('en'),
                ],
                'preferencesModal' => [
                    'title'              => 'Privacy Preference Center',
                    'acceptAllBtn'       => 'Accept All',
                    'acceptNecessaryBtn' => 'Reject All',
                    'savePreferencesBtn' => 'Save Preferences',
                    'closeIconLabel'     => 'Close Modal',
                    'serviceCounterLabel'=> 'Service|Services',
                    'sections'           => self::buildSections('en', $categories),
                ],
            ],

            // 2. TURKISH (TR)
            'tr' => [
                'consentModal' => [
                    'title'              => 'Gizliliğinize Değer Veriyoruz',
                    'description'        => 'Web sitemizde deneyiminizi geliştirmek, site trafiğini analiz etmek ve kişiselleştirilmiş içerik sunmak için çerezler kullanılmaktadır.',
                    'acceptAllBtn'       => 'Tümünü Kabul Et',
                    'acceptNecessaryBtn' => 'Yalnızca Zorunlular',
                    'showPreferencesBtn' => 'Tercihleri Yönet',
                    'footer'             => self::buildFooter('tr'),
                ],
                'preferencesModal' => [
                    'title'              => 'Gizlilik ve Çerez Tercih Merkezi',
                    'acceptAllBtn'       => 'Tümünü Kabul Et',
                    'acceptNecessaryBtn' => 'Tümünü Reddet',
                    'savePreferencesBtn' => 'Tercihleri Kaydet',
                    'closeIconLabel'     => 'Pencereyi Kapat',
                    'serviceCounterLabel'=> 'Servis|Servisler',
                    'sections'           => self::buildSections('tr', $categories),
                ],
            ],

            // 3. GERMAN (DE)
            'de' => [
                'consentModal' => [
                    'title'              => 'Wir schätzen Ihre Privatsphäre',
                    'description'        => 'Diese Website verwendet Cookies zur Optimierung Ihrer Nutzererfahrung, zur Website-Analyse und zur Anzeige relevanter Inhalte.',
                    'acceptAllBtn'       => 'Alle akzeptieren',
                    'acceptNecessaryBtn' => 'Nur essenzielle akzeptieren',
                    'showPreferencesBtn' => 'Einstellungen verwalten',
                    'footer'             => self::buildFooter('de'),
                ],
                'preferencesModal' => [
                    'title'              => 'Datenschutz-Präferenzzentrum',
                    'acceptAllBtn'       => 'Alle akzeptieren',
                    'acceptNecessaryBtn' => 'Alle ablehnen',
                    'savePreferencesBtn' => 'Einstellungen speichern',
                    'closeIconLabel'     => 'Fenster schließen',
                    'serviceCounterLabel'=> 'Dienst|Dienste',
                    'sections'           => self::buildSections('de', $categories),
                ],
            ],

            // 4. FRENCH (FR)
            'fr' => [
                'consentModal' => [
                    'title'              => 'Nous respectons votre vie privée',
                    'description'        => 'Ce site utilise des cookies pour optimiser votre expérience, analyser le trafic et afficher des contenus personnalisés.',
                    'acceptAllBtn'       => 'Tout accepter',
                    'acceptNecessaryBtn' => 'Refuser les non essentiels',
                    'showPreferencesBtn' => 'Gérer les préférences',
                    'footer'             => self::buildFooter('fr'),
                ],
                'preferencesModal' => [
                    'title'              => 'Centre de préférences de confidentialité',
                    'acceptAllBtn'       => 'Tout accepter',
                    'acceptNecessaryBtn' => 'Tout refuser',
                    'savePreferencesBtn' => 'Enregistrer les choix',
                    'closeIconLabel'     => 'Fermer la fenêtre',
                    'serviceCounterLabel'=> 'Service|Services',
                    'sections'           => self::buildSections('fr', $categories),
                ],
            ],

            // 5. SPANISH (ES)
            'es' => [
                'consentModal' => [
                    'title'              => 'Valoramos su privacidad',
                    'description'        => 'Este sitio web utiliza cookies para optimizar su experiencia, analizar el tráfico y ofrecer contenido personalizado.',
                    'acceptAllBtn'       => 'Aceptar todo',
                    'acceptNecessaryBtn' => 'Rechazar no esenciales',
                    'showPreferencesBtn' => 'Gestionar preferencias',
                    'footer'             => self::buildFooter('es'),
                ],
                'preferencesModal' => [
                    'title'              => 'Centro de preferencias de privacidad',
                    'acceptAllBtn'       => 'Aceptar todo',
                    'acceptNecessaryBtn' => 'Rechazar todo',
                    'savePreferencesBtn' => 'Guardar preferencias',
                    'closeIconLabel'     => 'Cerrar ventana',
                    'serviceCounterLabel'=> 'Servicio|Servicios',
                    'sections'           => self::buildSections('es', $categories),
                ],
            ],

            // 6. ITALIAN (IT)
            'it' => [
                'consentModal' => [
                    'title'              => 'Rispettiamo la tua privacy',
                    'description'        => 'Questo sito utilizza i cookie per ottimizzare la tua esperienza, analizzare il traffico e mostrare contenuti personalizzati.',
                    'acceptAllBtn'       => 'Accetta tutti',
                    'acceptNecessaryBtn' => 'Rifiuta non necessari',
                    'showPreferencesBtn' => 'Gestisci preferenze',
                    'footer'             => self::buildFooter('it'),
                ],
                'preferencesModal' => [
                    'title'              => 'Centro preferenze sulla privacy',
                    'acceptAllBtn'       => 'Accetta tutti',
                    'acceptNecessaryBtn' => 'Rifiuta tutti',
                    'savePreferencesBtn' => 'Salva preferenze',
                    'closeIconLabel'     => 'Chiudi finestra',
                    'serviceCounterLabel'=> 'Servizio|Servizi',
                    'sections'           => self::buildSections('it', $categories),
                ],
            ],

            // 7. DUTCH (NL)
            'nl' => [
                'consentModal' => [
                    'title'              => 'Wij hechten waarde aan uw privacy',
                    'description'        => 'Deze website maakt gebruik van cookies om uw ervaring te optimaliseren, verkeer te analyseren en gepersonaliseerde inhoud te tonen.',
                    'acceptAllBtn'       => 'Alles accepteren',
                    'acceptNecessaryBtn' => 'Alleen noodzakelijk',
                    'showPreferencesBtn' => 'Voorkeuren beheren',
                    'footer'             => self::buildFooter('nl'),
                ],
                'preferencesModal' => [
                    'title'              => 'Privacyvoorkeurencentrum',
                    'acceptAllBtn'       => 'Alles accepteren',
                    'acceptNecessaryBtn' => 'Alles weigeren',
                    'savePreferencesBtn' => 'Voorkeuren opslaan',
                    'closeIconLabel'     => 'Venster sluiten',
                    'serviceCounterLabel'=> 'Dienst|Diensten',
                    'sections'           => self::buildSections('nl', $categories),
                ],
            ],

            // 8. ARABIC (AR) — RTL
            'ar' => [
                'consentModal' => [
                    'title'              => 'نحن نقدر خصوصيتك',
                    'description'        => 'يستخدم هذا الموقع ملفات تعريف الارتباط لتحسين تجربتك، وتحليل حركة المرور، وعرض محتوى مخصص يناسب اهتماماتك.',
                    'acceptAllBtn'       => 'قبول الكل',
                    'acceptNecessaryBtn' => 'رفض غير الضروري',
                    'showPreferencesBtn' => 'إدارة التفضيلات',
                    'footer'             => self::buildFooter('ar'),
                ],
                'preferencesModal' => [
                    'title'              => 'مركز تفضيلات الخصوصية',
                    'acceptAllBtn'       => 'قبول الكل',
                    'acceptNecessaryBtn' => 'رفض الكل',
                    'savePreferencesBtn' => 'حفظ التفضيلات',
                    'closeIconLabel'     => 'إغلاق النافذة',
                    'serviceCounterLabel'=> 'خدمة|خدمات',
                    'sections'           => self::buildSections('ar', $categories),
                ],
            ],

            // 9. RUSSIAN (RU)
            'ru' => [
                'consentModal' => [
                    'title'              => 'Мы уважаем вашу конфиденциальность',
                    'description'        => 'Этот сайт использует файлы cookie для оптимизации работы, анализа трафика и отображения персонализированного контента.',
                    'acceptAllBtn'       => 'Принять все',
                    'acceptNecessaryBtn' => 'Отклонить необязательные',
                    'showPreferencesBtn' => 'Настроить предпочтения',
                    'footer'             => self::buildFooter('ru'),
                ],
                'preferencesModal' => [
                    'title'              => 'Центр настроек конфиденциальности',
                    'acceptAllBtn'       => 'Принять все',
                    'acceptNecessaryBtn' => 'Отклонить все',
                    'savePreferencesBtn' => 'Сохранить настройки',
                    'closeIconLabel'     => 'Закрыть окно',
                    'serviceCounterLabel'=> 'Сервис|Сервисы',
                    'sections'           => self::buildSections('ru', $categories),
                ],
            ],
        ];

        /**
         * Filter all compiled language packs.
         *
         * @param array<string, array<string, mixed>> $packs
         * @param list<array<string, mixed>> $categories
         */
        return (array) apply_filters('tuedion_cookie_language_packs', $packs, $categories);
    }

    /**
     * Build localized modal sections for a specific language.
     *
     * @param string $lang
     * @param list<array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    private static function buildSections(string $lang, array $categories): array
    {
        $overviewMap = [
            'en' => [
                'title'       => 'Cookie & Privacy Overview',
                'description' => 'We use cookies to ensure the basic functionalities of the website and to enhance your online experience. You can choose for each category to opt-in/out whenever you want.',
            ],
            'tr' => [
                'title'       => 'Çerez ve Gizlilik Tercihleri',
                'description' => 'Web sitemizin temel işlevlerini sağlamak ve çevrim içi deneyiminizi geliştirmek için çerezler kullanıyoruz. Tercihlerinizi dilediğiniz zaman güncelleyebilirsiniz.',
            ],
            'de' => [
                'title'       => 'Cookie- und Datenschutzübersicht',
                'description' => 'Wir verwenden Cookies, um die grundlegenden Funktionen der Website zu gewährleisten und Ihr Online-Erlebnis zu verbessern. Sie können Ihre Einstellungen jederzeit anpassen.',
            ],
            'fr' => [
                'title'       => 'Aperçu des cookies et de la confidentialité',
                'description' => 'Nous utilisons des cookies pour garantir les fonctionnalités de base du site et améliorer votre expérience. Vous pouvez modifier vos préférences à tout moment.',
            ],
            'es' => [
                'title'       => 'Resumen de cookies y privacidad',
                'description' => 'Utilizamos cookies para garantizar las funciones básicas del sitio web y mejorar su experiencia en línea. Puede cambiar sus preferencias en cualquier momento.',
            ],
            'it' => [
                'title'       => 'Panoramica su cookie e privacy',
                'description' => 'Utilizziamo i cookie per garantire le funzionalità di base del sito e migliorare la tua esperienza online. Puoi modificare le tue preferenze in qualsiasi momento.',
            ],
            'nl' => [
                'title'       => 'Overzicht van cookies en privacy',
                'description' => 'Wij gebruiken cookies om de basisfuncties van de website te garanderen en uw online ervaring te verbeteren. U kunt uw voorkeuren op elk gewenst moment wijzigen.',
            ],
            'ar' => [
                'title'       => 'نظرة عامة على ملفات تعريف الارتباط والخصوصية',
                'description' => 'نستخدم ملفات تعريف الارتباط لضمان الوظائف الأساسية للموقع وتحسين تجربتك عبر الإنترنت. يمكنك تعديل تفضيلاتك في أي وقت تشاء.',
            ],
            'ru' => [
                'title'       => 'Обзор файлов cookie и конфиденциальности',
                'description' => 'Мы используем файлы cookie для обеспечения базовой функциональности сайта и повышения удобства пользователей. Вы можете изменить настройки в любое время.',
            ],
        ];

        $sections = [$overviewMap[$lang] ?? $overviewMap['en']];

        // Standard localized translations for default category IDs
        $labelsMap = [
            'necessary' => [
                'en' => 'Strictly Necessary',
                'tr' => 'Zorunlu Çerezler',
                'de' => 'Unbedingt erforderlich',
                'fr' => 'Strictement nécessaires',
                'es' => 'Estrictamente necesarias',
                'it' => 'Strettamente necessari',
                'nl' => 'Strikt noodzakelijk',
                'ar' => 'ضرورية للغاية',
                'ru' => 'Строго необходимые',
            ],
            'functionality' => [
                'en' => 'Functionality',
                'tr' => 'İşlevsel Çerezler',
                'de' => 'Funktionalität',
                'fr' => 'Fonctionnalité',
                'es' => 'Funcionalidad',
                'it' => 'Funzionalità',
                'nl' => 'Functionaliteit',
                'ar' => 'الوظائف والتفضيلات',
                'ru' => 'Функциональные',
            ],
            'analytics' => [
                'en' => 'Analytics & Performance',
                'tr' => 'Analitik ve Performans',
                'de' => 'Analyse und Leistung',
                'fr' => 'Statistiques et performance',
                'es' => 'Analítica y rendimiento',
                'it' => 'Analitica e prestazioni',
                'nl' => 'Analyses en prestaties',
                'ar' => 'التحليلات والأداء',
                'ru' => 'Аналитика и производительность',
            ],
            'marketing' => [
                'en' => 'Marketing & Advertising',
                'tr' => 'Pazarlama ve Reklam',
                'de' => 'Marketing und Werbung',
                'fr' => 'Marketing et publicité',
                'es' => 'Marketing y publicidad',
                'it' => 'Marketing e pubblicità',
                'nl' => 'Marketing en advertenties',
                'ar' => 'التسويق والإعلانات',
                'ru' => 'Маркетинг и реклама',
            ],
        ];

        $descMap = [
            'necessary' => [
                'en' => 'These cookies are essential for the proper functioning of the website and cannot be disabled.',
                'tr' => 'Web sitesinin düzgün çalışması için zorunludur ve kapatılamaz.',
                'de' => 'Diese Cookies sind für das ordnungsgemäße Funktionieren der Website unerlässlich und können nicht deaktiviert werden.',
                'fr' => 'Ces cookies sont indispensables au bon fonctionnement du site web et ne peuvent pas être désactivés.',
                'es' => 'Estas cookies son esenciales para el correcto funcionamiento del sitio web y no se pueden desactivar.',
                'it' => 'Questi cookie sono essenziali per il corretto funzionamento del sito web e non possono essere disattivati.',
                'nl' => 'Deze cookies zijn essentieel voor de goede werking van de website en kunnen niet worden uitgeschakeld.',
                'ar' => 'ملفات تعريف الارتباط هذه ضرورية لعمل الموقع بشكل سليم ولا يمكن تعطيلها.',
                'ru' => 'Эти файлы cookie необходимы для правильной работы сайта и не могут быть отключены.',
            ],
            'functionality' => [
                'en' => 'These cookies allow the website to remember choices you make (such as language or region).',
                'tr' => 'Dil ve bölge gibi tercihlerinizi hatırlamamızı sağlar.',
                'de' => 'Diese Cookies ermöglichen es der Website, von Ihnen getroffene Auswahlen (wie Sprache oder Region) zu speichern.',
                'fr' => 'Ces cookies permettent au site de mémoriser vos choix (comme la langue ou la région).',
                'es' => 'Estas cookies permiten que el sitio recuerde las opciones elegidas (como el idioma o la región).',
                'it' => 'Questi cookie consentono al sito di ricordare le scelte effettuate (come la lingua o la regione).',
                'nl' => 'Deze cookies stellen de website in staat keuzes te onthouden (zoals taal of regio).',
                'ar' => 'تتيح ملفات تعريف الارتباط هذه للموقع تذكر الخيارات التي تحددها (مثل اللغة أو المنطقة).',
                'ru' => 'Эти файлы cookie позволяют сайту запоминать сделанный вами выбор (например, язык или регион).',
            ],
            'analytics' => [
                'en' => 'These cookies help us understand how visitors interact with our website to improve performance.',
                'tr' => 'Sitemizi nasıl kullandığınızı anlayarak performansı artırmamıza yardımcı olur.',
                'de' => 'Diese Cookies helfen uns zu verstehen, wie Besucher mit der Website interagieren, um die Leistung zu verbessern.',
                'fr' => 'Ces cookies nous aident à comprendre comment les visiteurs interagissent avec le site afin d\'en améliorer les performances.',
                'es' => 'Estas cookies nos ayudan a entender cómo interactúan los visitantes con el sitio web para mejorar el rendimiento.',
                'it' => 'Questi cookie ci aiutano a capire come i visitatori interagiscono con il sito web per migliorarne le prestazioni.',
                'nl' => 'Deze cookies helpen ons te begrijpen hoe bezoekers omgaan met de website om de prestaties te verbeteren.',
                'ar' => 'تساعدنا ملفات تعريف الارتباط هذه على فهم كيفية تفاعل الزوار مع الموقع لتحسين الأداء.',
                'ru' => 'Эти файлы cookie помогают понять, как посетители взаимодействуют с сайтом, для повышения его эффективности.',
            ],
            'marketing' => [
                'en' => 'These cookies are used to deliver personalized advertisements relevant to your interests.',
                'tr' => 'İlgi alanlarınıza uygun kişiselleştirilmiş reklamlar sunmak için kullanılır.',
                'de' => 'Diese Cookies werden verwendet, um für Sie relevante, personalisierte Werbung bereitzustellen.',
                'fr' => 'Ces cookies sont utilisés pour diffuser des publicités personnalisées adaptées à vos centres d\'intérêt.',
                'es' => 'Estas cookies se utilizan para ofrecer publicidad personalizada relevante para sus intereses.',
                'it' => 'Questi cookie vengono utilizzati per fornire annunci pubblicitari personalizzati pertinenti ai tuoi interessi.',
                'nl' => 'Deze cookies worden gebruikt om gepersonaliseerde advertenties te leveren die relevant zijn voor uw interesses.',
                'ar' => 'تُستخدم ملفات تعريف الارتباط هذه لتقديم إعلانات مخصصة تناسب اهتماماتك.',
                'ru' => 'Эти файлы cookie используются для показа персонализированной рекламы, соответствующей вашим интересам.',
            ],
        ];

        if (is_array($categories)) {
            foreach ($categories as $cat) {
                if (!is_array($cat) || empty($cat['id'])) {
                    continue;
                }
                $catId = (string) $cat['id'];
                $defaultLabel = $cat['label'] ?? $catId;
                $defaultDesc = $cat['description'] ?? '';

                $title = $labelsMap[$catId][$lang] ?? $defaultLabel;
                $desc  = $descMap[$catId][$lang] ?? $defaultDesc;

                $section = [
                    'title'          => $title,
                    'description'    => $desc,
                    'linkedCategory' => $catId,
                ];

                $cookieTable = \Tuedion\CookieConsent\Consent\CookieTableBuilder::build($catId, $lang);
                if ($cookieTable !== null) {
                    $section['cookieTable'] = $cookieTable;
                }

                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * Build localized Privacy Policy & Terms of Service HTML links for modal footers.
     */
    public static function buildFooter(string $lang): string
    {
        $privacyUrl = \Tuedion\CookieConsent\Consent\LanguageResolver::getPrivacyPolicyUrl($lang);
        $termsUrl   = \Tuedion\CookieConsent\Consent\LanguageResolver::getTermsUrl($lang);

        $privLabel  = \Tuedion\CookieConsent\Consent\LanguageResolver::getPrivacyPolicyTitle($lang);
        $termsLabel = \Tuedion\CookieConsent\Consent\LanguageResolver::getTermsTitle($lang);

        $links = [];
        if (!empty($privacyUrl) && $privacyUrl !== '#') {
            $links[] = '<a href="' . esc_url($privacyUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($privLabel) . '</a>';
        }

        if (!empty($termsUrl)) {
            $links[] = '<a href="' . esc_url($termsUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($termsLabel) . '</a>';
        }

        return !empty($links) ? implode(' &nbsp;·&nbsp; ', $links) : '';
    }
}

<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class Schema
{
    public const OPTION_NAME = 'tuedion_cookie_settings';
    public const SCHEMA_VERSION_OPTION = 'tuedion_cookie_schema_version';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_DISABLED = 'disabled';

    public const ALLOWED_STATUSES = [
        self::STATUS_ENABLED,
        self::STATUS_DRAFT,
        self::STATUS_DISABLED,
    ];

    /**
     * Expected top-level keys in settings payload.
     *
     * @var array<string, string>
     */
    public const SCHEMA_STRUCTURE = [
        'status'       => 'string',
        'revision'     => 'integer',
        'banner'       => 'array',
        'preferences'  => 'array',
        'cookie'       => 'array',
        'trigger'      => 'array',
        'categories'   => 'array',
        'services'     => 'array',
        'languages'    => 'array',
        'advanced'     => 'array',
        'gcm'          => 'array',
        'logging'      => 'array',
        'theme'        => 'array',
        'legal'        => 'array',
    ];
}

<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object representing a service definition within Tuedion Service Registry.
 */
final class ServiceDefinition
{
    /**
     * @param string $id
     * @param string $name
     * @param string $category
     * @param string $provider
     * @param list<string> $domains
     * @param list<string> $scriptPatterns
     * @param list<string> $inlinePatterns
     * @param list<string> $iframePatterns
     * @param list<string> $wpHandles
     * @param list<string> $autoClear
     * @param string $description
     * @param string $type
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $category,
        public readonly string $provider,
        public readonly array $domains = [],
        public readonly array $scriptPatterns = [],
        public readonly array $inlinePatterns = [],
        public readonly array $iframePatterns = [],
        public readonly array $wpHandles = [],
        public readonly array $autoClear = [],
        public readonly string $description = '',
        public readonly string $type = 'script',
    ) {
    }

    /**
     * Convert to classic recipe array for backward compatibility.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'category'    => $this->category,
            'provider'    => $this->provider,
            'type'        => $this->type,
            'handles'     => $this->wpHandles,
            'domains'     => $this->domains,
            'patterns'    => $this->iframePatterns,
            'auto_clear'  => $this->autoClear,
            'description' => $this->description,
        ];
    }
}

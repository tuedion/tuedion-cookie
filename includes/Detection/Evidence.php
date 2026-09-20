<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Detection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object representing an individual detection evidence artifact.
 */
final class Evidence
{
    /**
     * @param string $type Evidence type ('cookie', 'script_domain', 'script_url', 'iframe', 'inline_signature', 'wp_handle', 'storage', 'plugin')
     * @param string $value Matched string or identifier
     * @param int $weight Confidence weight contribution (10-50)
     * @param string $label Human-readable description of the evidence
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $weight,
        public readonly string $label = '',
    ) {
    }

    /**
     * Export to array representation.
     *
     * @return array{type: string, value: string, weight: int, label: string}
     */
    public function toArray(): array
    {
        return [
            'type'   => $this->type,
            'value'  => $this->value,
            'weight' => $this->weight,
            'label'  => $this->label !== '' ? $this->label : sprintf('%s: %s', ucfirst($this->type), $this->value),
        ];
    }
}

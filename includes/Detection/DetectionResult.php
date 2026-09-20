<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Detection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Structured container representing a detected tracking service and its evidence trail.
 */
final class DetectionResult
{
    /**
     * @var list<Evidence>
     */
    private array $evidence = [];

    /**
     * @param string $id
     * @param string $name
     * @param string $category
     * @param string $provider
     * @param string $type
     * @param list<string> $autoClear
     * @param bool $isManaged
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $category,
        public readonly string $provider = '',
        public readonly string $type = 'script',
        public readonly array $autoClear = [],
        public readonly bool $isManaged = true,
    ) {
    }

    /**
     * Add an evidence artifact with deduplication.
     *
     * @param Evidence $evidence
     */
    public function addEvidence(Evidence $evidence): void
    {
        foreach ($this->evidence as $existing) {
            if ($existing->type === $evidence->type && $existing->value === $evidence->value) {
                return;
            }
        }
        $this->evidence[] = $evidence;
    }

    /**
     * Get calculated confidence score.
     *
     * @return int
     */
    public function getConfidenceScore(): int
    {
        return ConfidenceScorer::calculateScore($this->evidence);
    }

    /**
     * Export to normalized array structure for admin UI and persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $score = $this->getConfidenceScore();
        $tier  = ConfidenceScorer::getLevel($score);

        $evidenceExport = array_map(static fn(Evidence $e) => $e->toArray(), $this->evidence);
        $sourcesExport  = array_map(static fn(Evidence $e) => $e->label, $this->evidence);

        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'category'         => $this->category,
            'provider'         => $this->provider,
            'type'             => $this->type,
            'auto_clear'       => $this->autoClear,
            'is_managed'       => $this->isManaged,
            'confidence_score' => $score,
            'confidence_tier'  => $tier['level'],
            'confidence_label' => $tier['label'],
            'badge_class'      => $tier['badge_class'],
            'evidence'         => $evidenceExport,
            'source'           => !empty($sourcesExport[0]) ? $sourcesExport[0] : $this->name,
            'sources'          => $sourcesExport,
        ];
    }
}

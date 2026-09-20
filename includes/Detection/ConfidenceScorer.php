<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Detection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculates multi-factor confidence scores and classifies detection certainty.
 */
final class ConfidenceScorer
{
    /**
     * Compute composite confidence score (0 - 100) from list of evidence items.
     *
     * @param list<Evidence> $evidenceList
     * @return int
     */
    public static function calculateScore(array $evidenceList): int
    {
        if (empty($evidenceList)) {
            return 0;
        }

        $total = 0;
        $seenTypes = [];

        foreach ($evidenceList as $ev) {
            // First item of each evidence type gives full weight; duplicates of same type add diminishing weight (+5)
            $type = $ev->type;
            if (!isset($seenTypes[$type])) {
                $total += $ev->weight;
                $seenTypes[$type] = 1;
            } else {
                $total += 5;
            }
        }

        return min(100, max(0, $total));
    }

    /**
     * Determine the qualitative confidence tier based on score.
     *
     * @param int $score
     * @return array{level: string, label: string, badge_class: string}
     */
    public static function getLevel(int $score): array
    {
        if ($score >= 90) {
            return [
                'level'       => 'confirmed',
                'label'       => __('Confirmed', 'tuedion-cookie'),
                'badge_class' => 'tdcc-badge-confirmed',
            ];
        }

        if ($score >= 70) {
            return [
                'level'       => 'very_likely',
                'label'       => __('Very Likely', 'tuedion-cookie'),
                'badge_class' => 'tdcc-badge-very-likely',
            ];
        }

        if ($score >= 50) {
            return [
                'level'       => 'probable',
                'label'       => __('Probable', 'tuedion-cookie'),
                'badge_class' => 'tdcc-badge-probable',
            ];
        }

        if ($score >= 30) {
            return [
                'level'       => 'possible',
                'label'       => __('Possible', 'tuedion-cookie'),
                'badge_class' => 'tdcc-badge-possible',
            ];
        }

        return [
            'level'       => 'weak',
            'label'       => __('Weak Signal', 'tuedion-cookie'),
            'badge_class' => 'tdcc-badge-weak',
        ];
    }
}

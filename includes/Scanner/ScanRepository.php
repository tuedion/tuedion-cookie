<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent Storage & History Repository for Tuedion Cookie Scanner.
 * Guarantees non-autoloaded options and protects manual administrator overrides.
 */
final class ScanRepository
{
    public const OPTION_LATEST    = 'tdcc_scanner_results';
    public const OPTION_HISTORY   = 'tdcc_scan_history';
    public const OPTION_OVERRIDES = 'tdcc_scanner_manual_overrides';
    public const MAX_HISTORY_RUNS = 10;

    /**
     * Persist a completed or in-progress scan run.
     */
    public static function saveScan(ScanModel $scan): void
    {
        $scanArray = $scan->toArray();

        // 1. Save latest scan results (non-autoloaded)
        update_option(self::OPTION_LATEST, $scanArray, false);

        // 2. Append to scan history if completed or failed
        if (in_array($scan->status, [ScanModel::STATUS_COMPLETED, ScanModel::STATUS_FAILED], true)) {
            $history = self::getHistory(self::MAX_HISTORY_RUNS);

            // Prepend new scan
            array_unshift($history, [
                'id'               => $scan->id,
                'mode'             => $scan->mode,
                'status'           => $scan->status,
                'started_at'       => $scan->startedAt,
                'completed_at'     => $scan->completedAt,
                'duration_seconds' => $scan->durationSeconds,
                'urls_scanned'     => count($scan->scannedUrls),
                'services_count'   => count($scan->detectedServices),
                'cookies_count'    => count($scan->detectedCookies),
                'unknown_count'    => count($scan->unknownResources),
                'diff_summary'     => $scan->diff['summary'] ?? '',
                'has_changes'      => !empty($scan->diff['has_changes']),
            ]);

            // Keep max history
            if (count($history) > self::MAX_HISTORY_RUNS) {
                $history = array_slice($history, 0, self::MAX_HISTORY_RUNS);
            }

            update_option(self::OPTION_HISTORY, $history, false);
        }
    }

    /**
     * Retrieve the latest scan run.
     */
    public static function getLatestScan(): ?ScanModel
    {
        $raw = get_option(self::OPTION_LATEST, null);
        if (!is_array($raw) || empty($raw['id'])) {
            return null;
        }

        return ScanModel::fromArray($raw);
    }

    /**
     * Retrieve scan history entries.
     *
     * @return list<array<string, mixed>>
     */
    public static function getHistory(int $limit = 10): array
    {
        $history = get_option(self::OPTION_HISTORY, []);
        if (!is_array($history)) {
            return [];
        }

        return array_slice($history, 0, $limit);
    }

    /**
     * Clear all recorded scan history.
     */
    public static function clearHistory(): void
    {
        delete_option(self::OPTION_HISTORY);
    }

    /**
     * Retrieve manual overrides defined by the administrator.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getManualOverrides(): array
    {
        $overrides = get_option(self::OPTION_OVERRIDES, []);
        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Save a manual override for a service or unknown resource.
     *
     * @param string $key Resource or service identifier
     * @param array<string, mixed> $overrideData e.g. ['action' => 'ignore', 'category' => 'analytics', 'notes' => '...']
     */
    public static function setManualOverride(string $key, array $overrideData): void
    {
        $key = sanitize_key($key);
        if ($key === '') {
            return;
        }

        $overrides = self::getManualOverrides();
        $overrides[$key] = array_merge($overrides[$key] ?? [], $overrideData, [
            'updated_at' => current_time('mysql'),
        ]);

        update_option(self::OPTION_OVERRIDES, $overrides, false);
    }

    /**
     * Remove a manual override.
     */
    public static function removeManualOverride(string $key): void
    {
        $key = sanitize_key($key);
        $overrides = self::getManualOverrides();
        if (isset($overrides[$key])) {
            unset($overrides[$key]);
            update_option(self::OPTION_OVERRIDES, $overrides, false);
        }
    }

    /**
     * Apply manual administrator overrides onto detected findings.
     *
     * @param array<string, mixed> $detectedServices
     * @param list<array<string, mixed>> $unknownResources
     * @return array{services: array<string, mixed>, unknowns: list<array<string, mixed>>}
     */
    public static function applyManualOverrides(array $detectedServices, array $unknownResources): array
    {
        $overrides = self::getManualOverrides();
        if (empty($overrides)) {
            return [
                'services' => $detectedServices,
                'unknowns' => $unknownResources,
            ];
        }

        // Apply to detected services
        foreach ($detectedServices as $sId => &$svc) {
            if (isset($overrides[$sId])) {
                $ov = $overrides[$sId];
                if (!empty($ov['category'])) {
                    $svc['category'] = sanitize_key($ov['category']);
                    $svc['manual_override'] = true;
                }
                if (!empty($ov['name'])) {
                    $svc['name'] = sanitize_text_field($ov['name']);
                }
            }
        }
        unset($svc);

        // Apply to unknown resources (filter ignored or mapped)
        $cleanUnknowns = [];
        foreach ($unknownResources as $res) {
            $id = sanitize_key((string) ($res['identifier'] ?? $res['domain'] ?? ''));
            if (isset($overrides[$id])) {
                $ov = $overrides[$id];
                if (!empty($ov['action']) && $ov['action'] === 'ignore') {
                    continue; // Skipped per admin choice
                }
            }
            $cleanUnknowns[] = $res;
        }

        return [
            'services' => $detectedServices,
            'unknowns' => $cleanUnknowns,
        ];
    }
}

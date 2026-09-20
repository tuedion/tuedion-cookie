<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Type-safe Data Model for a Scanner Execution Run.
 */
final class ScanModel
{
    public const MODE_QUICK = 'quick';
    public const MODE_SMART = 'smart';
    public const MODE_FULL  = 'full';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    /**
     * @param string $id Unique scan run identifier (e.g. 'scan_65f8a...')
     * @param string $mode One of ScanModel::MODE_*
     * @param string $status One of ScanModel::STATUS_*
     * @param string $startedAt ISO 8601 or formatted date string
     * @param string|null $completedAt Completion timestamp or null if running
     * @param int $durationSeconds Scan execution duration in seconds
     * @param list<string> $discoveredUrls List of all URLs discovered during planning
     * @param list<string> $scannedUrls List of URLs successfully crawled
     * @param list<string> $failedUrls List of URLs that encountered errors
     * @param array<string, mixed> $detectedServices Map of detected service records
     * @param list<array<string, mixed>> $detectedCookies List of discovered cookie records
     * @param list<array<string, mixed>> $unknownResources List of unidentified external resources
     * @param array<string, mixed> $diff Differences compared to the preceding scan run
     * @param array<string, mixed> $settings Configuration options used for this scan run
     */
    public function __construct(
        public readonly string $id,
        public readonly string $mode = self::MODE_QUICK,
        public string $status = self::STATUS_PENDING,
        public readonly string $startedAt = '',
        public ?string $completedAt = null,
        public int $durationSeconds = 0,
        public array $discoveredUrls = [],
        public array $scannedUrls = [],
        public array $failedUrls = [],
        public array $detectedServices = [],
        public array $detectedCookies = [],
        public array $unknownResources = [],
        public array $diff = [],
        public array $settings = []
    ) {
    }

    /**
     * Create a ScanModel instance from an array representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? uniqid('scan_', true)),
            mode: (string) ($data['mode'] ?? self::MODE_QUICK),
            status: (string) ($data['status'] ?? self::STATUS_COMPLETED),
            startedAt: (string) ($data['started_at'] ?? current_time('mysql')),
            completedAt: isset($data['completed_at']) ? (string) $data['completed_at'] : null,
            durationSeconds: (int) ($data['duration_seconds'] ?? 0),
            discoveredUrls: (array) ($data['discovered_urls'] ?? []),
            scannedUrls: (array) ($data['scanned_urls'] ?? []),
            failedUrls: (array) ($data['failed_urls'] ?? []),
            detectedServices: (array) ($data['detected_services'] ?? []),
            detectedCookies: (array) ($data['detected_cookies'] ?? []),
            unknownResources: (array) ($data['unknown_resources'] ?? []),
            diff: (array) ($data['diff'] ?? []),
            settings: (array) ($data['settings'] ?? [])
        );
    }

    /**
     * Serialize to array for storage in wp_options or JSON transport.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'mode'             => $this->mode,
            'status'           => $this->status,
            'started_at'       => $this->startedAt,
            'completed_at'     => $this->completedAt,
            'duration_seconds' => $this->durationSeconds,
            'discovered_urls'  => $this->discoveredUrls,
            'scanned_urls'     => $this->scannedUrls,
            'failed_urls'      => $this->failedUrls,
            'detected_services'=> $this->detectedServices,
            'detected_cookies' => $this->detectedCookies,
            'unknown_resources'=> $this->unknownResources,
            'diff'             => $this->diff,
            'settings'         => $this->settings,
            'stats'            => [
                'urls_discovered'  => count($this->discoveredUrls),
                'urls_scanned'     => count($this->scannedUrls),
                'urls_failed'      => count($this->failedUrls),
                'service_count'    => count($this->detectedServices),
                'cookie_count'     => count($this->detectedCookies),
                'unknown_count'    => count($this->unknownResources),
                'new_count'        => count($this->diff['new_services'] ?? []) + count($this->diff['new_cookies'] ?? []),
            ],
        ];
    }
}

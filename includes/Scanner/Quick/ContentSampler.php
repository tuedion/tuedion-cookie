<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Scanner\Quick;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Intelligent Content Sampler for Quick Scan.
 * Supports Representative, Recently Modified, Recently Published, and Random strategies.
 */
final class ContentSampler
{
    public const STRATEGY_REPRESENTATIVE    = 'representative';
    public const STRATEGY_RECENTLY_MODIFIED = 'recently_modified';
    public const STRATEGY_RECENTLY_PUB     = 'recently_published';
    public const STRATEGY_RANDOM            = 'random';

    /**
     * Resolve representative target URLs based on requested content types, strategy, and sample limit.
     *
     * @param list<string> $targetTypes Slugs of post types to include (e.g. ['home', 'page', 'post', 'product'])
     * @param string $strategy One of ContentSampler::STRATEGY_*
     * @param int $sampleLimit Maximum target URLs to return (default: 20)
     * @param string $param Query param to append (e.g. 'tdcc_audit=1')
     * @return list<array{key: string, label: string, url: string, post_id: int|null}>
     */
    public static function sample(
        array $targetTypes = ['home', 'page', 'post'],
        string $strategy = self::STRATEGY_REPRESENTATIVE,
        int $sampleLimit = 20,
        string $param = 'tdcc_audit=1'
    ): array {
        // Normalize if called with an options array: sample(['targets' => [...], 'strategy' => ...])
        if (isset($targetTypes['targets']) || isset($targetTypes['strategy']) || isset($targetTypes['sample_size']) || isset($targetTypes['sample_limit'])) {
            $strategy    = (string) ($targetTypes['strategy'] ?? $strategy);
            $sampleLimit = (int) ($targetTypes['sample_size'] ?? $targetTypes['sample_limit'] ?? $sampleLimit);
            $param       = (string) ($targetTypes['param'] ?? $param);
            $targetTypes = (array) ($targetTypes['targets'] ?? ['home', 'page', 'post']);
        }

        // Ensure all target types are flat strings
        $targetTypes = array_values(array_filter(array_map('strval', $targetTypes), static fn($t) => $t !== ''));

        $results = [];
        $seenUrls = [];

        // 1. Home Page is ALWAYS priority #1
        $homeUrl = home_url('/?' . $param);
        $results[] = [
            'key'     => 'home',
            'label'   => __('Front Page / Home', 'tuedion-cookie'),
            'url'     => $homeUrl,
            'post_id' => (int) get_option('page_on_front') ?: null,
        ];
        $seenUrls[home_url('/')] = true;

        if ($sampleLimit <= 1) {
            return $results;
        }

        $remainingSlots = $sampleLimit - 1;
        $frontPageId = (int) get_option('page_on_front');

        // Orderby / Order configuration
        $orderby = match ($strategy) {
            self::STRATEGY_RECENTLY_MODIFIED => 'modified',
            self::STRATEGY_RANDOM            => 'rand',
            default                          => 'date',
        };
        $order = ($strategy === self::STRATEGY_RANDOM) ? '' : 'DESC';

        // 2. Iterate requested types
        $nonHomeTypes = array_values(array_filter($targetTypes, static fn($t) => is_string($t) && $t !== 'home' && $t !== ''));
        if (empty($nonHomeTypes)) {
            return $results;
        }

        // Calculate per-type allocation
        $perTypeSlots = max(1, (int) ceil($remainingSlots / count($nonHomeTypes)));

        foreach ($nonHomeTypes as $typeSlug) {
            if (!is_string($typeSlug) || empty($typeSlug)) {
                continue;
            }

            if (count($results) >= $sampleLimit) {
                break;
            }

            // A. Special WooCommerce Product & Shop
            if ($typeSlug === 'product' && class_exists('WooCommerce')) {
                $shopId = (int) get_option('woocommerce_shop_page_id');
                if ($shopId > 0) {
                    $shopUrl = (string) get_permalink($shopId);
                    if ($shopUrl !== '' && !isset($seenUrls[$shopUrl])) {
                        $seenUrls[$shopUrl] = true;
                        $sep = str_contains($shopUrl, '?') ? '&' : '?';
                        $results[] = [
                            'key'     => 'product_shop',
                            'label'   => __('WooCommerce Shop', 'tuedion-cookie'),
                            'url'     => $shopUrl . $sep . $param,
                            'post_id' => $shopId,
                        ];
                    }
                }
            }

            // Query posts for this type
            $fetchSlots = $perTypeSlots;
            if ($typeSlug === 'page' && $frontPageId > 0) {
                $fetchSlots++;
            }

            $queryArgs = [
                'post_type'      => $typeSlug,
                'post_status'    => 'publish',
                'posts_per_page' => $fetchSlots,
                'orderby'        => $orderby,
                'order'          => $order,
                'no_found_rows'  => true,
            ];

            $posts = get_posts($queryArgs);
            $cptObj = get_post_type_object($typeSlug);
            $typeLabel = $cptObj ? ($cptObj->labels->singular_name ?? $cptObj->label) : ucfirst($typeSlug);

            foreach ($posts as $p) {
                if ($typeSlug === 'page' && $frontPageId > 0 && (int) $p->ID === $frontPageId) {
                    continue;
                }
                if (count($results) >= $sampleLimit) {
                    break;
                }

                $permalink = (string) get_permalink($p->ID);
                if ($permalink === '' || isset($seenUrls[$permalink])) {
                    continue;
                }

                $seenUrls[$permalink] = true;
                $sep = str_contains($permalink, '?') ? '&' : '?';

                $results[] = [
                    'key'     => $typeSlug,
                    'label'   => sprintf('%s: %s', $typeLabel, get_the_title($p)),
                    'url'     => $permalink . $sep . $param,
                    'post_id' => $p->ID,
                ];
            }
        }

        return (array) apply_filters('tuedion_cookie_scanner_quick_sample', $results, $targetTypes, $strategy);
    }
}

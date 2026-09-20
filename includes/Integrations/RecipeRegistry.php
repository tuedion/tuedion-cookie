<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations;

use Tuedion\CookieConsent\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adapter facade providing backward-compatible access to the unified ServiceRegistry.
 *
 * NOTE: This registry does NOT initiate any external HTTP requests, remote API calls, or load
 * third-party scripts. It acts strictly as an offline dictionary of known script handles,
 * domain signatures, and iframe embed patterns to detect and BLOCK them until explicit consent is granted.
 */
final class RecipeRegistry
{
    /**
     * Retrieve all registered recipes formatted for backward compatibility.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAll(): array
    {
        $services = ServiceRegistry::getAll();
        $recipes = [];

        foreach ($services as $id => $service) {
            $data = $service->toArray();
            $data['category'] = self::resolveServiceCategory($id, $service->category);
            $recipes[$id] = $data;
        }

        return $recipes;
    }

    /**
     * Retrieve a specific recipe by service ID or alias.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        $service = ServiceRegistry::get($id);
        if ($service === null) {
            return null;
        }

        $data = $service->toArray();
        $data['category'] = self::resolveServiceCategory($service->id, $service->category);

        return $data;
    }

    /**
     * Check if a recipe exists by ID or alias.
     *
     * @param string $id
     * @return bool
     */
    public static function has(string $id): bool
    {
        return ServiceRegistry::get($id) !== null;
    }

    /**
     * Resolve effective category for a service ID, respecting user-customized category assignments.
     *
     * @param string $serviceId
     * @param string $defaultCategory
     * @return string
     */
    public static function resolveServiceCategory(string $serviceId, string $defaultCategory): string
    {
        $settings = Repository::getSettings();
        $configuredServices = $settings['services'] ?? [];

        if (is_array($configuredServices)) {
            foreach ($configuredServices as $configured) {
                if (is_array($configured) && isset($configured['id']) && $configured['id'] === $serviceId) {
                    if (!empty($configured['category']) && is_string($configured['category'])) {
                        return sanitize_key($configured['category']);
                    }
                }
            }
        }

        return $defaultCategory;
    }

    /**
     * Identify a recipe matching an enqueued script handle or src URL.
     *
     * @param string $handle
     * @param string $src
     * @return array<string, mixed>|null
     */
    public static function findRecipeForScript(string $handle, string $src = ''): ?array
    {
        $match = ServiceMatcher::matchScript($handle, $src);
        if ($match === null) {
            return null;
        }

        $service = $match['service'];
        $recipe = $service->toArray();
        $recipe['category'] = self::resolveServiceCategory($service->id, $service->category);

        return $recipe;
    }

    /**
     * Identify a recipe matching an iframe src embed.
     *
     * @param string $src
     * @return array<string, mixed>|null
     */
    public static function findRecipeForIframe(string $src): ?array
    {
        $match = ServiceMatcher::matchIframe($src);
        if ($match === null) {
            return null;
        }

        $service = $match['service'];
        $recipe = $service->toArray();
        $recipe['category'] = self::resolveServiceCategory($service->id, $service->category);

        return $recipe;
    }

    /**
     * Collect all autoClear cookie patterns registered for a given category.
     *
     * @param string $categoryId
     * @return list<array{name: string}>
     */
    public static function getAutoClearForCategory(string $categoryId): array
    {
        $recipes = self::getAll();
        $patterns = [];

        foreach ($recipes as $id => $recipe) {
            $effectiveCategory = (string) ($recipe['category'] ?? '');
            if ($effectiveCategory !== $categoryId) {
                continue;
            }

            if (!empty($recipe['auto_clear']) && is_array($recipe['auto_clear'])) {
                foreach ($recipe['auto_clear'] as $cookiePattern) {
                    $patterns[] = [
                        'name' => (string) $cookiePattern,
                    ];
                }
            }
        }

        return $patterns;
    }
}

<?php

declare(strict_types=1);

namespace Tuedion\CookieConsent\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom Recipe API and Manager.
 * Enables developers and third-party extensions to programmatically register
 * custom declarative service recipes without modifying core files.
 */
final class CustomRecipeManager
{
    /**
     * In-memory registry of dynamically registered custom recipes.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $customRecipes = [];

    public static function register(): void
    {
        add_filter('tuedion_cookie_recipes', [self::class, 'injectCustomRecipes'], 15);
    }

    /**
     * Programmatic API to register a new service recipe.
     *
     * @param string $id Unique recipe slug (e.g., 'custom-analytics')
     * @param array<string, mixed> $recipe Recipe configuration array
     * @return bool True if recipe was successfully registered, false on validation error.
     */
    public static function registerRecipe(string $id, array $recipe): bool
    {
        $id = sanitize_key($id);
        if ($id === '') {
            return false;
        }

        // Validate required fields
        if (empty($recipe['name']) || empty($recipe['category'])) {
            return false;
        }

        $type = sanitize_key((string) ($recipe['type'] ?? 'script'));
        if (!in_array($type, ['script', 'iframe'], true)) {
            $type = 'script';
        }

        $sanitized = [
            'name'        => sanitize_text_field((string) $recipe['name']),
            'category'    => sanitize_key((string) $recipe['category']),
            'type'        => $type,
            'handles'     => array_values(array_map('sanitize_key', (array) ($recipe['handles'] ?? []))),
            'domains'     => array_values(array_map('sanitize_text_field', (array) ($recipe['domains'] ?? []))),
            'patterns'    => array_values(array_map('sanitize_text_field', (array) ($recipe['patterns'] ?? []))),
            'auto_clear'  => array_values(array_map('sanitize_text_field', (array) ($recipe['auto_clear'] ?? []))),
            'description' => sanitize_text_field((string) ($recipe['description'] ?? '')),
            'source'      => 'custom_api',
        ];

        self::$customRecipes[$id] = $sanitized;
        return true;
    }

    /**
     * Retrieve all dynamically registered custom recipes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getCustomRecipes(): array
    {
        return self::$customRecipes;
    }

    /**
     * Inject custom recipes into the core RecipeRegistry.
     *
     * @param array<string, array<string, mixed>> $recipes
     * @return array<string, array<string, mixed>>
     */
    public static function injectCustomRecipes(array $recipes): array
    {
        foreach (self::$customRecipes as $id => $data) {
            $recipes[$id] = $data;
        }

        return $recipes;
    }
}

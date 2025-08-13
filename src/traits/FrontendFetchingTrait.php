<?php
/**
 * Search w/Elastic plugin for Craft CMS 4.x
 *
 * Provides high-performance search across all content types with real-time
 * indexing, advanced querying, and production reliability.
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\traits;

use pennebaker\searchwithelastic\models\SettingsModel;
use pennebaker\searchwithelastic\queries\IndexableAssetQuery;
use pennebaker\searchwithelastic\queries\IndexableCategoryQuery;
use pennebaker\searchwithelastic\queries\IndexableDigitalProductQuery;
use pennebaker\searchwithelastic\queries\IndexableEntryQuery;
use pennebaker\searchwithelastic\queries\IndexableProductQuery;
use pennebaker\searchwithelastic\SearchWithElastic;

/**
 * Trait for frontend fetching functionality
 *
 * Reduces ~150 lines of duplication across query classes by centralizing
 * frontend fetching logic and configuration handling.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
trait FrontendFetchingTrait
{
    /**
     * @var bool Whether frontend fetching is enabled for this query
     */
    protected bool $frontendFetch = false;

    /**
     * @var bool Whether multi-site frontend fetching is enabled
     */
    protected bool $multiSiteFrontendFetch = false;

    /**
     * Enable or disable frontend fetching for this query
     *
     * @param bool $value Whether to enable frontend fetching
     * @return FrontendFetchingTrait|IndexableAssetQuery|IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery For method chaining
     */
    public function frontendFetch(bool $value = true): self
    {
        $this->frontendFetch = $value;
        return $this;
    }

    /**
     * Enable or disable multi-site frontend fetching
     *
     * @param bool $value Whether to enable multi-site frontend fetching
     * @return FrontendFetchingTrait|IndexableAssetQuery|IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery For method chaining
     */
    public function multiSiteFrontendFetch(bool $value = true): self
    {
        $this->multiSiteFrontendFetch = $value;
        return $this;
    }

    /**
     * Check if frontend fetching is enabled
     *
     * Considers both global plugin settings and instance-level configuration.
     *
     * @return bool True if frontend fetching should be enabled
     */
    public function isFrontendFetchEnabled(): bool
    {
        $settings = $this->getPluginSettings();

        if (!$settings || !$settings->enableFrontendFetching) {
            return false;
        }

        return $this->frontendFetch;
    }

    /**
     * Check if the given types should be excluded from frontend fetching
     *
     * @param array $types Array of type handles to check
     * @return bool True if any of the types should be excluded
     */
    public function shouldExcludeFromFrontendFetch(array $types): bool
    {
        $excludedTypes = $this->getExcludedFrontendFetchingTypes();

        if (empty($excludedTypes)) {
            return false;
        }

        return !empty(array_intersect($types, $excludedTypes));
    }

    /**
     * Get the list of types excluded from frontend fetching
     *
     * @return array Array of excluded type handles
     */
    public function getExcludedFrontendFetchingTypes(): array
    {
        $settings = $this->getPluginSettings();

        if (!$settings) {
            return [];
        }

        return $settings->excludedFrontendFetchingEntryTypes ?? [];
    }

    /**
     * Get plugin settings
     *
     * This method should be implemented by classes using this trait,
     * or they should override this to provide settings access.
     *
     * @return SettingsModel|null The plugin settings
     */
    protected function getPluginSettings(): ?SettingsModel
    {
        return SearchWithElastic::getInstance()?->getSettings();
    }
}

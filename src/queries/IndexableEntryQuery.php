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

namespace pennebaker\searchwithelastic\queries;

use craft\elements\Entry;
use pennebaker\searchwithelastic\traits\FrontendFetchingTrait;
use pennebaker\searchwithelastic\traits\StatusFilteringTrait;

/**
 * Query builder for indexable entries
 *
 * Provides specialized filtering methods for querying entries that should be
 * indexed in Elasticsearch, including filtering by entry types, sections,
 * statuses, and frontend fetching configuration.
 *
 * @template TElement of Entry
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableEntryQuery extends IndexableElementQuery
{
    use FrontendFetchingTrait;
    use StatusFilteringTrait;

    /**
     * Get the element type class for this query
     *
     * @return class-string<TElement> The fully qualified element class name
     */
    public static function elementType(): string
    {
        return Entry::class;
    }

    /**
     * Apply default filtering based on plugin settings
     *
     * Applies status, excluded entry types, and URL filtering based on
     * the plugin configuration.
     *
     * @return self self reference
     */
    protected function applyDefaultFilters(): self
    {
        $settings = $this->getPlugin()->getSettings();

        // Apply default status filtering
        $this->statuses($settings->indexableEntryStatuses);

        // Apply excluded entry types
        $this->excluded($settings->excludedEntryTypes);

        // Apply URL filtering based on settings
        $this->includeElementsWithoutUrls($settings->indexElementsWithoutUrls);

        return $this;
    }

    /**
     * Narrows the query results based on excluded entry type handles
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `['news', 'blog']` | not with entry types `news` or `blog`
     *
     * @param array $handles Entry type handles to exclude
     * @return self self reference
     */
    public function excluded(array $handles): self
    {
        $this->excludedHandles = $handles;
        $this->applyexcludeFilter('type');
        return $this;
    }

    /**
     * Narrows the query results based on the entries' entry types
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'news'` | with an entry type handle of `news`
     * | `['news', 'blog']` | with entry type handles of `news` or `blog`
     * | `['not', 'news']` | not with an entry type handle of `news`
     *
     * @param mixed $entryTypes Entry type handles or IDs
     * @return self self reference
     */
    public function entryTypes(mixed $entryTypes): self
    {
        $this->type($entryTypes);
        return $this;
    }

    /**
     * Narrows the query results based on the entries' sections
     *
     * Possible values include:
     *
     * | Value | Fetches entries…
     * | - | -
     * | `'news'` | in a section with a handle of `news`
     * | `['news', 'blog']` | in sections with handles of `news` or `blog`
     * | `['not', 'news']` | not in a section with a handle of `news`
     *
     * @param mixed $sections Section handles or IDs
     * @return self self reference
     */
    public function sections(mixed $sections): self
    {
        $this->section($sections);
        return $this;
    }

    /**
     * Get the setting key for excluded frontend fetching items
     *
     * @return string The settings key for excluded entry types
     */
    protected function getExcludedFrontendFetchingSettingKey(): string
    {
        return 'excludedFrontendFetchingEntryTypes';
    }

    /**
     * Get the element query method to apply exclusions
     *
     * @return string The method name for entry type filtering
     */
    protected function getFrontendFetchingFilterMethod(): string
    {
        return 'type';
    }

    /**
     * Parse and apply entry type filtering with support for legacy and section:type formats
     *
     * Supports both legacy format ['entryType'] and new format ['section:entryType'].
     * The section part is currently logged but not applied - future enhancement opportunity.
     *
     * @param array $entryTypes Array of entry types in format ['section:entryType'] or legacy ['entryType']
     * @return self self reference
     */
    public function parsedEntryTypes(array $entryTypes): self
    {
        $sectionEntryTypePairs = [];
        $legacyEntryTypes = [];

        foreach ($entryTypes as $entryType) {
            if (str_contains($entryType, ':')) {
                [$section, $type] = explode(':', $entryType, 2);
                $sectionEntryTypePairs[] = ['section' => $section, 'type' => $type];
            } else {
                $legacyEntryTypes[] = $entryType;
            }
        }

        // Apply section:entryType filtering
        if (!empty($sectionEntryTypePairs)) {
            // For now, we'll apply the type filter and log the section info
            // In the future, this could be enhanced to support proper section:type filtering
            $types = array_column($sectionEntryTypePairs, 'type');
            $this->type($types);
        }

        // Apply legacy entry type filtering
        if (!empty($legacyEntryTypes)) {
            $this->type($legacyEntryTypes);
        }

        return $this;
    }

}

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

use craft\elements\Category;
use pennebaker\searchwithelastic\traits\FrontendFetchingTrait;
use pennebaker\searchwithelastic\traits\StatusFilteringTrait;

/**
 * Query builder for indexable categories
 *
 * Provides specialized filtering methods for querying categories that should be
 * indexed in Elasticsearch, including filtering by category groups, hierarchy
 * levels, and frontend fetching configuration.
 *
 * @template TElement of Category
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableCategoryQuery extends IndexableElementQuery
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
        return Category::class;
    }

    /**
     * Apply default filtering based on plugin settings
     *
     * Applies status, excluded category groups, and URL filtering based on
     * the plugin configuration.
     *
     * @return self self reference
     */
    protected function applyDefaultFilters(): self
    {
        $settings = $this->getPlugin()->getSettings();

        // Apply default status filtering
        $this->statuses($settings->indexableCategoryStatuses);

        // Apply excluded category groups
        $this->excluded($settings->excludedCategoryGroups);

        // Apply URL filtering based on settings
        $this->includeElementsWithoutUrls($settings->indexElementsWithoutUrls);

        return $this;
    }

    /**
     * Narrows the query results based on excluded category group handles
     *
     * Possible values include:
     *
     * | Value | Fetches categories…
     * | - | -
     * | `['blog', 'news']` | not from groups with handles `blog` or `news`
     *
     * @param array $handles Category group handles to exclude
     * @return self self reference
     */
    public function excluded(array $handles): self
    {
        $this->excludedHandles = $handles;
        $this->applyexcludeFilter('group');
        return $this;
    }

    /**
     * Narrows the query results based on the categories' groups
     *
     * Possible values include:
     *
     * | Value | Fetches categories…
     * | - | -
     * | `'blog'` | in a group with a handle of `blog`
     * | `['blog', 'news']` | in groups with handles of `blog` or `news`
     * | `['not', 'blog']` | not in a group with a handle of `blog`
     *
     * @param mixed $groups Category group handles or IDs
     * @return self self reference
     */
    public function groups(mixed $groups): self
    {
        $this->group($groups);
        return $this;
    }

    /**
     * Get the setting key for excluded frontend fetching items
     *
     * @return string The settings key for excluded category groups
     */
    protected function getExcludedFrontendFetchingSettingKey(): string
    {
        return 'excludedFrontendFetchingCategoryGroups';
    }

    /**
     * Get the element query method to apply exclusions
     *
     * @return string The method name for group filtering
     */
    protected function getFrontendFetchingFilterMethod(): string
    {
        return 'group';
    }


    /**
     * Narrows the query results based on the categories' hierarchy level
     *
     * @param int $level The hierarchy level (1 for top-level, 2 for second level, etc.)
     * @return self self reference
     */
    public function atLevel(int $level): self
    {
        $this->level($level);
        return $this;
    }

    /**
     * Narrows the query results to only top-level categories (level 1)
     *
     * @return self self reference
     */
    public function topLevel(): self
    {
        return $this->atLevel(1);
    }

    /**
     * Narrows the query results to categories that are descendants of a specific category
     *
     * @param int|Category $category The parent category instance or ID
     * @return self self reference
     */
    public function descendantOf(int|Category $category): self
    {
        $this->elementQuery->descendantOf($category);
        return $this;
    }

    /**
     * Narrows the query results to categories that are ancestors of a specific category
     *
     * @param int|Category $category The child category instance or ID
     * @return self self reference
     */
    public function ancestorOf(int|Category $category): self
    {
        $this->elementQuery->ancestorOf($category);
        return $this;
    }

    /**
     * Narrows the query results to categories that are siblings of a specific category
     *
     * @param int|Category $category The sibling category instance or ID
     * @return self self reference
     */
    public function siblingOf(int|Category $category): self
    {
        $this->elementQuery->siblingOf($category);
        return $this;
    }

    /**
     * Narrows the query results based on the categories' parent
     *
     * @param int|Category|null $parent The parent category instance, ID, or null for root level
     * @return self self reference
     */
    public function parent(int|Category|null $parent): self
    {
        $this->elementQuery->parent($parent);
        return $this;
    }

    /**
     * Narrows the query results to only categories that have child categories
     *
     * @return self self reference
     */
    public function hasChildren(): self
    {
        $this->hasDescendants(true);
        return $this;
    }

    /**
     * Narrows the query results to only leaf categories (categories with no children)
     *
     * @return self self reference
     */
    public function leafCategories(): self
    {
        $this->hasDescendants(false);
        return $this;
    }
}

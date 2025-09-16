<?php
/**
 * Search w/Elastic plugin for Craft CMS 5.x
 *
 * Provides high-performance search across all content types with real-time
 * indexing, advanced querying, and production reliability.
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\queries;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use pennebaker\searchwithelastic\models\IndexableElementModel;
use pennebaker\searchwithelastic\SearchWithElastic;

/**
 * Abstract base class for indexable element queries
 *
 * Provides common functionality for querying elements that should be indexed
 * in Elasticsearch, including filtering by status, site, and exclusion rules.
 * All concrete indexable query classes extend this base class.
 *
 * Uses composition over inheritance to wrap Craft's ElementQuery while providing
 * specialized indexing-focused functionality and maintaining clean separation of concerns.
 *
 * @template TElement of ElementInterface
 * @author Pennebaker
 * @since 4.0.0
 */
abstract class IndexableElementQuery
{
    /**
     * @var string[] List of excluded handles (entry types, volumes, etc.)
     */
    protected array $excludedHandles = [];

    /**
     * @var string[] List of indexable statuses
     */
    protected array $indexableStatuses = [];

    /**
     * @var bool Whether to include elements without URLs
     */
    protected bool $includeElementsWithoutUrls = false;

    /**
     * @var ElementQuery The underlying element query
     */
    protected ElementQuery $elementQuery;

    /**
     * Create a new indexable element query with default filters applied
     *
     * @return static The new query instance
     */
    public static function find(): static
    {
        $elementType = static::elementType();
        $query = new static();
        $query->elementQuery = $elementType::find();
        
        // Always exclude drafts and revisions from indexing
        $query->elementQuery->drafts(false);
        $query->elementQuery->revisions(false);
        
        $query->applyDefaultFilters();
        return $query;
    }

    /**
     * Get the element type class for this query
     *
     * @return class-string<TElement> The fully qualified element class name
     */
    abstract public static function elementType(): string;

    /**
     * Apply default filters based on plugin settings
     *
     * Subclasses must implement this method to apply their specific
     * default filtering logic based on plugin configuration.
     *
     * @return self self reference for method chaining
     */
    abstract protected function applyDefaultFilters(): self;

    /**
     * Narrows the query results based on excluded handles
     *
     * Subclasses should override this method to implement their specific
     * exclusion logic (e.g., entry types, asset volumes, category groups).
     *
     * Possible values include:
     *
     * | Value | Fetches elements…
     * | - | -
     * | `['foo', 'bar']` | not with handles `foo` or `bar`
     *
     * @param string[] $handles The handles to exclude
     * @return self self reference for method chaining
     */
    public function excluded(array $handles): self
    {
        $this->excludedHandles = $handles;
        return $this;
    }

    /**
     * Narrows the query results based on element statuses
     *
     * Possible values include:
     *
     * | Value | Fetches elements…
     * | - | -
     * | `['live']` | that are live
     * | `['pending']` | that are pending (scheduled to be published)
     * | `['live', 'pending']` | that are live or pending
     *
     * @param string[] $statuses Element statuses to include (e.g., 'live', 'pending', 'enabled')
     * @return self self reference for method chaining
     */
    public function statuses(array $statuses): self
    {
        $this->indexableStatuses = $statuses;
        $this->elementQuery->status(array_filter($statuses));
        return $this;
    }

    /**
     * Sets whether to include elements that don't have frontend URLs
     *
     * When false, only elements with frontend URLs will be included.
     * When true, elements without URLs will be indexed using their metadata.
     *
     * @param bool $include Whether to include elements without URLs
     * @return self self reference for method chaining
     */
    public function includeElementsWithoutUrls(bool $include = true): self
    {
        $this->includeElementsWithoutUrls = $include;

        if (!$include) {
            $this->elementQuery->uri(['not', '']);
        }

        return $this;
    }

    /**
     * Narrows the query results based on the elements' site
     *
     * Possible values include:
     *
     * | Value | Fetches elements…
     * | - | -
     * | `1` | from the site with an ID of 1
     * | `'foo'` | from the site with a handle of `foo`
     * | `['foo', 'bar']` | from a site with a handle of `foo` or `bar`
     * | `['not', 'foo', 'bar']` | not in a site with a handle of `foo` or `bar`
     *
     * @param mixed $siteId The site ID(s) to filter by
     * @return self self reference for method chaining
     */
    public function siteId(mixed $siteId): self
    {
        $this->elementQuery->siteId($siteId);
        return $this;
    }

    /**
     * Get IndexableElementModel instances for all matching elements
     *
     * Returns model instances that can be used for indexing operations.
     *
     * @return IndexableElementModel[] Array of indexable element models
     */
    public function models(): array
    {
        $elements = $this->elementQuery
            ->select(['elements.id as elementId', 'elements_sites.siteId'])
            ->asArray()
            ->all();

        if (!is_array($elements)) {
            return [];
        }

        return array_map(
            static function (array $element): IndexableElementModel {
                $model = new IndexableElementModel();
                $model->elementId = (int)$element['elementId'];
                $model->siteId = (int)$element['siteId'];
                $model->type = static::elementType();
                return $model;
            },
            $elements
        );
    }

    /**
     * Get the first IndexableElementModel instance from the query results
     *
     * @return IndexableElementModel|null The first model or null if no results
     */
    public function model(): ?IndexableElementModel
    {
        $models = $this->models();
        return $models[0] ?? null;
    }

    /**
     * Get the count of indexable elements for a specific site
     *
     * @param int $siteId The site ID to count elements for
     * @return int The number of indexable elements
     */
    public function countForSite(int $siteId): int
    {
        // Clone the current query to avoid modifying state
        $query = clone $this;
        return (int)$query->siteId($siteId)->elementQuery->count();
    }

    /**
     * Get IndexableElementModel instances for multiple sites
     *
     * Creates separate query instances for each site to avoid state conflicts.
     *
     * @param int[]|null $siteIds Site IDs to query (null for all sites)
     * @return IndexableElementModel[] Array of models from all specified sites
     */
    public function modelsForSites(?array $siteIds = null): array
    {
        if ($siteIds === null) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        }

        $allModels = [];

        foreach ($siteIds as $siteId) {
            // Create a new query instance for each site to avoid state conflicts
            $siteQuery = static::find()->siteId($siteId);

            // Copy current state to new query
            $siteQuery->excludedHandles = $this->excludedHandles;
            $siteQuery->indexableStatuses = $this->indexableStatuses;
            $siteQuery->includeElementsWithoutUrls = $this->includeElementsWithoutUrls;

            // Apply the same exclusion logic as current instance
            if (!empty($this->excludedHandles)) {
                $siteQuery->excluded($this->excludedHandles);
            }

            if (!empty($this->indexableStatuses)) {
                $siteQuery->statuses($this->indexableStatuses);
            }

            $siteQuery->includeElementsWithoutUrls($this->includeElementsWithoutUrls);

            $siteModels = $siteQuery->models();

            // Use array_push with spread operator for better performance
            array_push($allModels, ...$siteModels);
        }

        return $allModels;
    }

    /**
     * Get the SearchWithElastic plugin instance
     *
     * @return SearchWithElastic The plugin instance
     */
    protected function getPlugin(): SearchWithElastic
    {
        return SearchWithElastic::getInstance();
    }

    /**
     * Apply exclusion filtering to the underlying element query
     *
     * Uses the excluded handles to filter out unwanted elements by the specified attribute.
     *
     * @param string $attribute The attribute to filter on (e.g., 'type', 'volume', 'group')
     * @return self self reference for method chaining
     */
    protected function applyExcludeFilter(string $attribute): self
    {
        if (!empty($this->excludedHandles)) {
            $this->elementQuery->$attribute(array_merge(['not'], $this->excludedHandles));
        }

        return $this;
    }

    /**
     * Get the underlying ElementQuery instance
     *
     * Provides access to the wrapped query for advanced operations.
     * Use with caution as modifications may affect indexable query behavior.
     *
     * @return ElementQuery The underlying element query
     */
    public function getElementQuery(): ElementQuery
    {
        return $this->elementQuery;
    }

    /**
     * Clone the current query with all its state
     *
     * @return self A new instance with the same configuration
     */
    public function clone(): self
    {
        $cloned = static::find();
        $cloned->excludedHandles = $this->excludedHandles;
        $cloned->indexableStatuses = $this->indexableStatuses;
        $cloned->includeElementsWithoutUrls = $this->includeElementsWithoutUrls;

        // Clone the underlying ElementQuery
        $cloned->elementQuery = clone $this->elementQuery;

        return $cloned;
    }

    /**
     * Magic method to forward method calls to the underlying ElementQuery
     *
     * Allows using ElementQuery methods directly on the indexable query wrapper.
     * Returns the wrapper instance for chainable methods.
     *
     * @param string $name The method name
     * @param array $arguments The method arguments
     * @return mixed The method result or this instance for chaining
     */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->elementQuery->$name(...$arguments);

        // If the result is the ElementQuery itself, return this wrapper instead
        if ($result === $this->elementQuery) {
            return $this;
        }

        return $result;
    }

    /**
     * Magic method to forward property access to the underlying ElementQuery
     *
     * @param string $name The property name
     * @return mixed The property value
     */
    public function __get(string $name): mixed
    {
        return $this->elementQuery->$name;
    }

    /**
     * Magic method to forward property setting to the underlying ElementQuery
     *
     * @param string $name The property name
     * @param mixed $value The property value
     */
    public function __set(string $name, mixed $value): void
    {
        $this->elementQuery->$name = $value;
    }

    /**
     * Magic method to check if property exists on the underlying ElementQuery
     *
     * @param string $name The property name
     * @return bool Whether the property exists
     */
    public function __isset(string $name): bool
    {
        return isset($this->elementQuery->$name);
    }

    /**
     * Support for cloning the query
     */
    public function __clone(): void
    {
        $this->elementQuery = clone $this->elementQuery;
    }
}

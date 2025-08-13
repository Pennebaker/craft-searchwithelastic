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

namespace pennebaker\searchwithelastic\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use InvalidArgumentException;
use pennebaker\searchwithelastic\events\search\QueryEvent;
use pennebaker\searchwithelastic\queries\IndexableAssetQuery;
use pennebaker\searchwithelastic\queries\IndexableCategoryQuery;
use pennebaker\searchwithelastic\queries\IndexableDigitalProductQuery;
use pennebaker\searchwithelastic\queries\IndexableElementQuery;
use pennebaker\searchwithelastic\queries\IndexableEntryQuery;
use pennebaker\searchwithelastic\queries\IndexableProductQuery;

/**
 * The Query service provides APIs for managing indexable element queries.
 *
 * An instance of the service is available via [[\pennebaker\searchwithelastic\SearchWithElastic::getInstance()|`SearchWithElastic::getInstance()->queries`]].
 *
 * @author Pennebaker
 * @since 4.0.0
 *
 * @property-read string[] $supportedElementTypes
 */
class QueryService extends Component
{
    // Event constants following Craft patterns
    public const EVENT_BEFORE_BUILD_QUERY = 'beforeBuildQuery';
    public const EVENT_AFTER_BUILD_QUERY = 'afterBuildQuery';

    /**
     * Map of element types to their corresponding query classes
     * @var array<class-string, class-string<IndexableElementQuery>>
     */
    private array $queryMap = [
        Asset::class => IndexableAssetQuery::class,
        Category::class => IndexableCategoryQuery::class,
        Entry::class => IndexableEntryQuery::class,
    ];

    /**
     * Initialize the service and build the query map
     */
    public function init(): void
    {
        parent::init();
        $this->buildQueryMap();
    }

    /**
     * Build the query map including optional commerce classes
     */
    private function buildQueryMap(): void
    {
        // Add commerce classes if available
        if (class_exists(Product::class)) {
            $this->queryMap[Product::class] = IndexableProductQuery::class;
        }

        if (class_exists(DigitalProduct::class)) {
            $this->queryMap[DigitalProduct::class] = IndexableDigitalProductQuery::class;
        }
    }

    /**
     * Generic query builder factory for indexable elements filtered by site.
     *
     * @param class-string $elementType The element type class name
     * @param int $siteId The site ID to filter by
     * @return IndexableElementQuery The configured query
     * @throws InvalidArgumentException If element type is not supported
     */
    public function getIndexableElementQuery(string $elementType, int $siteId): IndexableElementQuery
    {
        $queryClass = $this->getQueryClass($elementType);

        /** @var IndexableElementQuery $query */
        $query = $queryClass::find()->siteId($siteId);

        // Fire query events
        $this->triggerQueryEvents($siteId, $elementType, $query);

        return $query;
    }

    /**
     * Creates a query builder for indexable entries filtered by site.
     *
     * @param int $siteId The site ID to filter by
     * @return IndexableEntryQuery The configured entry query
     */
    public function getIndexableEntryQuery(int $siteId): IndexableEntryQuery
    {
        /** @var IndexableEntryQuery */
        return $this->getIndexableElementQuery(Entry::class, $siteId);
    }

    /**
     * Creates a query builder for indexable assets filtered by site.
     *
     * @param int $siteId The site ID to filter by
     * @return IndexableAssetQuery The configured asset query
     */
    public function getIndexableAssetQuery(int $siteId): IndexableAssetQuery
    {
        /** @var IndexableAssetQuery */
        return $this->getIndexableElementQuery(Asset::class, $siteId);
    }

    /**
     * Creates a query builder for indexable categories filtered by site.
     *
     * @param int $siteId The site ID to filter by
     * @return IndexableCategoryQuery The configured category query
     */
    public function getIndexableCategoryQuery(int $siteId): IndexableCategoryQuery
    {
        /** @var IndexableCategoryQuery */
        return $this->getIndexableElementQuery(Category::class, $siteId);
    }

    /**
     * Creates a query builder for indexable products filtered by site.
     *
     * @param int $siteId The site ID to filter by
     * @return IndexableProductQuery The configured product query
     * @throws InvalidArgumentException If Commerce plugin is not installed
     */
    public function getIndexableProductQuery(int $siteId): IndexableProductQuery
    {
        if (!class_exists(Product::class)) {
            throw new InvalidArgumentException('Commerce plugin is not installed');
        }

        /** @var IndexableProductQuery */
        return $this->getIndexableElementQuery(Product::class, $siteId);
    }

    /**
     * Creates a query builder for indexable digital products filtered by site.
     *
     * @param int $siteId The site ID to filter by
     * @return IndexableDigitalProductQuery The configured digital product query
     * @throws InvalidArgumentException If Digital Products plugin is not installed
     */
    public function getIndexableDigitalProductQuery(int $siteId): IndexableDigitalProductQuery
    {
        if (!class_exists(DigitalProduct::class)) {
            throw new InvalidArgumentException('Digital Products plugin is not installed');
        }

        /** @var IndexableDigitalProductQuery */
        return $this->getIndexableElementQuery(DigitalProduct::class, $siteId);
    }

    /**
     * Get the query class for a given element type
     *
     * @param class-string $elementType The element type class name
     * @return class-string<IndexableElementQuery> The query class name
     * @throws InvalidArgumentException If element type is not supported
     */
    public function getQueryClass(string $elementType): string
    {
        if (!isset($this->queryMap[$elementType])) {
            throw new InvalidArgumentException("Unsupported element type: $elementType");
        }

        return $this->queryMap[$elementType];
    }

    /**
     * Generic method to create query for any supported element type
     *
     * @param class-string $elementType The element type class name
     * @param int $siteId The site ID to filter by
     * @return IndexableElementQuery The configured query instance
     * @throws InvalidArgumentException If element type is not supported
     */
    public function getElementQuery(string $elementType, int $siteId): IndexableElementQuery
    {
        return $this->getIndexableElementQuery($elementType, $siteId);
    }

    /**
     * Get all supported element types
     *
     * @return array<class-string> Array of supported element type class names
     */
    public function getSupportedElementTypes(): array
    {
        return array_keys($this->queryMap);
    }

    /**
     * Check if an element type is supported
     *
     * @param string $elementType The element type class name
     * @return bool True if the element type is supported
     */
    public function isElementTypeSupported(string $elementType): bool
    {
        return isset($this->queryMap[$elementType]);
    }

    /**
     * Create queries for multiple element types
     *
     * @param array<class-string> $elementTypes Array of element type class names
     * @param int $siteId The site ID to filter by
     * @return array<class-string, IndexableElementQuery> Array of queries keyed by element type
     */
    public function createQueryForElements(array $elementTypes, int $siteId): array
    {
        $queries = [];

        foreach ($elementTypes as $elementType) {
            if ($this->isElementTypeSupported($elementType)) {
                $queries[$elementType] = $this->getElementQuery($elementType, $siteId);
            }
        }

        return $queries;
    }

    /**
     * Create query from configuration array
     *
     * @param array{elementType: class-string, siteId: int, ...} $config Configuration array
     * @return IndexableElementQuery The configured query instance
     * @throws InvalidArgumentException If configuration is invalid
     */
    public function createQueryFromConfig(array $config): IndexableElementQuery
    {
        if (!$this->validateQueryConfig($config)) {
            throw new InvalidArgumentException('Invalid query configuration provided');
        }

        $query = $this->getElementQuery($config['elementType'], $config['siteId']);

        // Apply additional configuration parameters safely
        $this->applyQueryConfig($query, $config);

        return $query;
    }

    /**
     * Apply configuration parameters to a query
     *
     * @param IndexableElementQuery $query The query to configure
     * @param array $config Configuration parameters
     */
    private function applyQueryConfig(IndexableElementQuery $query, array $config): void
    {
        $excludedKeys = ['elementType', 'siteId'];

        foreach ($config as $key => $value) {
            if (in_array($key, $excludedKeys, true)) {
                continue;
            }

            // Use reflection to safely check if method exists and is callable
            if (is_callable([$query, $key])) {
                try {
                    $query->$key($value);
                } catch (\Throwable $e) {
                    // Log error or handle gracefully - don't let one bad param break everything
                    Craft::error("Failed to apply query config '$key': " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Validate query configuration
     *
     * @param array $config Configuration array to validate
     * @return bool True if configuration is valid
     */
    public function validateQueryConfig(array $config): bool
    {
        // Check required fields
        if (!isset($config['elementType'], $config['siteId'])) {
            return false;
        }

        // Validate element type
        if (!is_string($config['elementType']) || !$this->isElementTypeSupported($config['elementType'])) {
            return false;
        }

        // Validate site ID
        if (!is_int($config['siteId']) || $config['siteId'] < 1) {
            return false;
        }

        return true;
    }

    /**
     * Triggers query events for a given query builder
     *
     * @param int $siteId The site ID
     * @param class-string $elementType The element type class name
     * @param IndexableElementQuery $query The query builder instance
     */
    protected function triggerQueryEvents(int $siteId, string $elementType, IndexableElementQuery $query): void
    {
        // Fire a 'beforeBuildQuery' event
        $event = new QueryEvent([
            'siteId' => $siteId,
            'elementType' => $elementType,
            'query' => $query,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_BUILD_QUERY)) {
            $this->trigger(self::EVENT_BEFORE_BUILD_QUERY, $event);
        }

        // Fire an 'afterBuildQuery' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_BUILD_QUERY)) {
            $event->query = $query; // Ensure we have the latest query state
            $this->trigger(self::EVENT_AFTER_BUILD_QUERY, $event);
        }
    }
}

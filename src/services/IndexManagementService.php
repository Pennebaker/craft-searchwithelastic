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

namespace pennebaker\searchwithelastic\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\ArrayHelper;
use pennebaker\searchwithelastic\events\connection\ErrorEvent;
use pennebaker\searchwithelastic\events\indexing\IndexManagementEvent;
use pennebaker\searchwithelastic\helpers\ElasticsearchHelper;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\elasticsearch\Exception;

/**
 * The Index Management service provides APIs for managing Elasticsearch indexes.
 *
 * An instance of the service is available via [[\pennebaker\searchwithelastic\SearchWithElastic::getInstance()|`SearchWithElastic::getInstance()->indexManagement`]].
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexManagementService extends Component
{
    // Event constants following Craft patterns
    public const EVENT_BEFORE_CREATE_INDEX = 'beforeCreateIndex';
    public const EVENT_AFTER_CREATE_INDEX = 'afterCreateIndex';
    public const EVENT_BEFORE_DELETE_INDEX = 'beforeDeleteIndex';
    public const EVENT_AFTER_DELETE_INDEX = 'afterDeleteIndex';
    public const EVENT_BEFORE_RECREATE_INDEX = 'beforeRecreateIndex';
    public const EVENT_AFTER_RECREATE_INDEX = 'afterRecreateIndex';

    /**
     * Generates the Elasticsearch index name for a specific site and element type.
     *
     * @param int $siteId The site ID
     * @param class-string<ElementInterface>|null $elementType The element type class name (optional)
     * @return string The constructed index name
     * @since 4.0.0
     */
    public function getIndexName(int $siteId, ?string $elementType = null): string
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Start with the configured prefix (e.g., 'craft-')
        $indexName = $settings->indexPrefix;

        // Determine the index suffix
        if ($elementType && !empty($settings->elementTypeIndexNames[$elementType])) {
            // Use element-specific override if configured
            $indexName .= $settings->elementTypeIndexNames[$elementType];
        } else {
            // Use fallback name
            $indexName .= $settings->fallbackIndexName;
        }

        // Add site ID
        $indexName .= '_' . $siteId;

        return $indexName;
    }

    /**
     * Retrieves all possible index names for a site, including fallback and element-specific indexes.
     *
     * @param int $siteId The site ID
     * @return string[] Array of index names for the site
     * @since 4.0.0
     */
    public function getAllIndexNames(int $siteId): array
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        $indexNames = [];

        // Always include the fallback index
        $indexNames[] = $this->getIndexName($siteId);

        // Add any element-specific indexes that are configured
        foreach ($settings->elementTypeIndexNames as $elementType => $override) {
            if (!empty($override)) {
                $indexName = $this->getIndexName($siteId, $elementType);
                if (!in_array($indexName, $indexNames, true)) {
                    $indexNames[] = $indexName;
                }
            }
        }

        return array_unique($indexNames);
    }

    /**
     * Creates an Elasticsearch index for the specified site with appropriate configuration.
     *
     * @param int $siteId The site ID
     * @throws Exception When index creation fails
     * @throws \Exception When other errors occur during index creation
     */
    public function createSiteIndex(int $siteId): void
    {
        $connection = SearchWithElastic::getConnection();
        $indexName = $this->getIndexName($siteId);
        $indexExists = $connection->createCommand()->indexExists($indexName);

        if ($indexExists) {
            return; // Index already exists
        }

        $indexConfig = $this->getIndexConfiguration($siteId);

        // Fire a 'beforeCreateIndex' event
        $event = new IndexManagementEvent([
            'siteId' => $siteId,
            'indexName' => $indexName,
            'indexConfig' => $indexConfig,
            'operation' => 'create',
            'indexExisted' => $indexExists,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_CREATE_INDEX)) {
            $this->trigger(self::EVENT_BEFORE_CREATE_INDEX, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return;
        }

        try {
            // Use potentially modified config from event
            $connection->createCommand()->createIndex($indexName, $event->indexConfig);

            \Craft::info("Created Elasticsearch index: $indexName", __METHOD__);

            // Fire an 'afterCreateIndex' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_CREATE_INDEX)) {
                $this->trigger(self::EVENT_AFTER_CREATE_INDEX, $event);
            }
        } catch (Exception $e) {
            \Craft::error("Failed to create index for site $siteId: " . $e->getMessage(), __METHOD__);
            $this->triggerErrorEvent($e);
            throw $e;
        } catch (\Exception $e) {
            \Craft::error("Failed to create index for site $siteId: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    /**
     * Removes the Elasticsearch index for the specified site.
     *
     * @param int $siteId The site ID
     * @throws \Exception When index removal fails
     */
    public function removeSiteIndex(int $siteId): void
    {
        $connection = SearchWithElastic::getConnection();
        $indexName = $this->getIndexName($siteId);
        $indexExists = $connection->createCommand()->indexExists($indexName);

        if (!$indexExists) {
            return; // Index doesn't exist
        }

        // Fire a 'beforeDeleteIndex' event
        $event = new IndexManagementEvent([
            'siteId' => $siteId,
            'indexName' => $indexName,
            'indexConfig' => [], // No config needed for deletion
            'operation' => 'delete',
            'indexExisted' => $indexExists,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_INDEX)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_INDEX, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return;
        }

        try {
            $connection->createCommand()->deleteIndex($indexName);

            \Craft::info("Removed Elasticsearch index: $indexName", __METHOD__);

            // Fire an 'afterDeleteIndex' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_INDEX)) {
                $this->trigger(self::EVENT_AFTER_DELETE_INDEX, $event);
            }
        } catch (\Exception $e) {
            \Craft::error("Failed to remove index for site $siteId: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    /**
     * Recreates the Elasticsearch index for the specified site by removing and recreating it.
     *
     * @param int $siteId The site ID
     * @throws \Exception When index recreation fails
     */
    public function recreateSiteIndex(int $siteId): void
    {
        $connection = SearchWithElastic::getConnection();
        $indexName = $this->getIndexName($siteId);
        $indexExists = $connection->createCommand()->indexExists($indexName);
        $indexConfig = $this->getIndexConfiguration($siteId);

        // Fire a 'beforeRecreateIndex' event
        $event = new IndexManagementEvent([
            'siteId' => $siteId,
            'indexName' => $indexName,
            'indexConfig' => $indexConfig,
            'operation' => 'recreate',
            'indexExisted' => $indexExists,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_RECREATE_INDEX)) {
            $this->trigger(self::EVENT_BEFORE_RECREATE_INDEX, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return;
        }

        try {
            $this->removeSiteIndex($siteId);
            $this->createSiteIndex($siteId);

            // Fire an 'afterRecreateIndex' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_RECREATE_INDEX)) {
                $this->trigger(self::EVENT_AFTER_RECREATE_INDEX, $event);
            }
        } catch (\Exception $e) {
            \Craft::error("Failed to recreate index for site $siteId: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    /**
     * Recreates Elasticsearch indexes for all sites in the Craft installation.
     * @throws \Exception
     */
    public function recreateIndexesForAllSites(): void
    {
        $sites = \Craft::$app->sites->getAllSites();

        foreach ($sites as $site) {
            $this->recreateSiteIndex($site->id);
        }
    }

    /**
     * Generates the Elasticsearch index configuration including mappings and settings for a site.
     *
     * @param int $siteId The site ID
     * @return array The index configuration array
     */
    protected function getIndexConfiguration(int $siteId): array
    {
        $site = \Craft::$app->sites->getSiteById($siteId);
        $language = $site->language ?? 'en';

        // Map Craft language codes to Elasticsearch analyzers
        $analyzerMap = [
            'en' => 'english',
            'fr' => 'french',
            'de' => 'german',
            'es' => 'spanish',
            'it' => 'italian',
            'pt' => 'portuguese',
            'ru' => 'russian',
            'ar' => 'arabic',
            'zh' => 'cjk',
            'ja' => 'cjk',
            'ko' => 'cjk',
        ];

        $analyzer = $analyzerMap[substr($language, 0, 2)] ?? 'standard';

        // Build properties dynamically based on what element types are actually indexed
        $properties = $this->buildDynamicMappingProperties($analyzer);

        $config = [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'analysis' => [
                    'analyzer' => [
                        'default' => [
                            'type' => $analyzer
                        ]
                    ]
                ]
            ],
            'mappings' => [
                'properties' => $properties
            ]
        ];

        // Add extra fields mappings from plugin settings
        $this->addExtraFieldsMappings($config, $analyzer);

        return $config;
    }

    /**
     * Adds custom field mappings from plugin settings to the index configuration.
     *
     * @param array $config The index configuration array (passed by reference)
     * @param string $analyzer The analyzer to use for text fields
     */
    protected function addExtraFieldsMappings(array &$config, string $analyzer): void
    {
        $extraFields = SearchWithElastic::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldMapping = ArrayHelper::getValue($fieldParams, 'mapping');
                if ($fieldMapping) {
                    if (is_callable($fieldMapping)) {
                        // Note: We can't execute callable mappings here since we don't have an element context
                        // Use default keyword mapping for callable field mappings
                        $config['mappings']['properties'][$fieldName] = ['type' => 'keyword'];
                    } else {
                        $config['mappings']['properties'][$fieldName] = $fieldMapping;
                    }
                } else {
                    // Default mapping for fields without explicit mapping
                    $config['mappings']['properties'][$fieldName] = ['type' => 'keyword'];
                }
            }
        }
    }

    /**
     * Triggers appropriate error events for specific Elasticsearch exceptions.
     *
     * @param Exception $e The Elasticsearch exception
     */
    protected function triggerErrorEvent(Exception $e): void
    {
        if (
            isset($e->errorInfo['responseBody']['error']['reason'])
            && $e->errorInfo['responseBody']['error']['reason'] === 'No processor type exists with name [attachment]'
        ) {
            SearchWithElastic::getInstance()->trigger(
                SearchWithElastic::EVENT_ERROR_NO_ATTACHMENT_PROCESSOR,
                new ErrorEvent($e)
            );
        }
    }

    /**
     * Build dynamic mapping properties based on what element types are configured for indexing
     *
     * @param string $analyzer The analyzer to use for text fields
     * @return array The mapping properties array
     * @since 4.0.0
     */
    private function buildDynamicMappingProperties(string $analyzer): array
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Start with base mappings that apply to all element types
        $properties = [
            'elementId' => ElasticsearchHelper::INTEGER_FIELD_MAPPING,
            'siteId' => ElasticsearchHelper::INTEGER_FIELD_MAPPING,
            'elementType' => ['type' => 'keyword'],
            'title' => [
                'type' => 'text',
                'analyzer' => $analyzer,
                'fields' => [
                    'keyword' => ['type' => 'keyword']
                ]
            ],
            'slug' => ['type' => 'keyword'],
            'status' => ['type' => 'keyword'],
            'dateCreated' => ['type' => 'date'],
            'dateUpdated' => ['type' => 'date'],
            'enabled' => ['type' => 'boolean'],
            'archived' => ['type' => 'boolean'],
        ];

        // Only add URL field if we're indexing elements with URLs
        if ($settings->indexElementsWithoutUrls || $this->hasElementsWithUrls()) {
            $properties['url'] = ['type' => 'keyword'];
        }

        // Add searchable content field if enabled (default field name: 'content')
        if ($settings->useSearchableFields) {
            $searchableFieldName = $settings->searchableContentFieldName ?: 'content';
            $properties[$searchableFieldName] = [
                'type' => 'text',
                'analyzer' => $analyzer
            ];
        }

        // Add frontend content field if enabled (default field name: 'content_fetch')
        if ($settings->enableFrontendFetching) {
            $frontendFieldName = $settings->frontendContentFieldName ?: 'content_fetch';
            $properties[$frontendFieldName] = [
                'type' => 'text',
                'analyzer' => $analyzer
            ];

            // Keep summary field for backward compatibility
            $properties['summary'] = [
                'type' => 'text',
                'analyzer' => $analyzer
            ];
        }

        // Only add searchScore if it's used in extraFields
        if ($this->isFieldUsedInExtraFields('searchScore')) {
            $properties['searchScore'] = ElasticsearchHelper::FLOAT_FIELD_MAPPING;
        }

        // Add Entry-specific fields only if entries are being indexed
        if ($this->isElementTypeIndexed(\craft\elements\Entry::class)) {
            $properties['postDate'] = ['type' => 'date'];
            $properties['expiryDate'] = ['type' => 'date'];

            // Add native order field for structure sections
            if ($this->hasStructureSections()) {
                $properties['order'] = ElasticsearchHelper::INTEGER_FIELD_MAPPING;
            }
        }

        // Add Commerce Product fields only if Commerce is installed and products are indexed
        if (class_exists(\craft\commerce\elements\Product::class) && $this->isElementTypeIndexed(\craft\commerce\elements\Product::class)) {
            $properties['price'] = ElasticsearchHelper::FLOAT_FIELD_MAPPING;
            $properties['salePrice'] = ElasticsearchHelper::FLOAT_FIELD_MAPPING;
            $properties['weight'] = ElasticsearchHelper::FLOAT_FIELD_MAPPING;
            $properties['sku'] = ['type' => 'keyword'];
            $properties['stock'] = ElasticsearchHelper::INTEGER_FIELD_MAPPING;
        }

        // Add Digital Product fields only if Digital Products is installed and indexed
        // Note: Digital products may share some fields with regular products
        if (class_exists(\craft\digitalproducts\elements\Product::class) && $this->isElementTypeIndexed(\craft\digitalproducts\elements\Product::class)) {
            if (!isset($properties['price'])) {
                $properties['price'] = ElasticsearchHelper::FLOAT_FIELD_MAPPING;
                $properties['salePrice'] = ElasticsearchHelper::FLOAT_FIELD_MAPPING;
                $properties['sku'] = ['type' => 'keyword'];
            }
        }

        // Add Asset fields only if assets are being indexed
        if ($this->isElementTypeIndexed(\craft\elements\Asset::class)) {
            $properties['filename'] = ['type' => 'keyword'];
            $properties['kind'] = ['type' => 'keyword'];
            $properties['size'] = ElasticsearchHelper::LONG_FIELD_MAPPING;
            $properties['width'] = ElasticsearchHelper::INTEGER_FIELD_MAPPING;
            $properties['height'] = ElasticsearchHelper::INTEGER_FIELD_MAPPING;
        }

        // Add Category fields only if categories are being indexed
        if ($this->isElementTypeIndexed(\craft\elements\Category::class)) {
            $properties['level'] = ElasticsearchHelper::INTEGER_FIELD_MAPPING;
            $properties['lft'] = ElasticsearchHelper::INTEGER_FIELD_MAPPING;
            $properties['rgt'] = ElasticsearchHelper::INTEGER_FIELD_MAPPING;
        }

        return $properties;
    }

    /**
     * Check if a specific element type is configured for indexing
     *
     * @param string $elementClass The element class to check
     * @return bool True if the element type should be indexed
     * @since 4.0.0
     */
    private function isElementTypeIndexed(string $elementClass): bool
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Check based on element type and settings
        switch ($elementClass) {
            case \craft\elements\Entry::class:
                // Entries are indexed unless all entry types are excluded
                $allEntryTypes = \Craft::$app->entries->getAllEntryTypes();
                if (empty($allEntryTypes)) {
                    return false;
                }
                $allHandles = array_map(fn($type) => $type->handle, $allEntryTypes);
                $excluded = $settings->excludedEntryTypes ?? [];
                return count(array_diff($allHandles, $excluded)) > 0;

            case \craft\elements\Asset::class:
                // Assets are indexed if any asset kinds are configured
                return !empty($settings->assetKinds);

            case \craft\elements\Category::class:
                // Categories are indexed unless all groups are excluded
                $allGroups = \Craft::$app->getCategories()->getAllGroups();
                if (empty($allGroups)) {
                    return false;
                }
                $allHandles = array_map(fn($group) => $group->handle, $allGroups);
                $excluded = $settings->excludedCategoryGroups ?? [];
                return count(array_diff($allHandles, $excluded)) > 0;

            case \craft\commerce\elements\Product::class:
                // Products are indexed if Commerce is installed and not all types are excluded
                if (!class_exists(\craft\commerce\elements\Product::class)) {
                    return false;
                }
                if (!\Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
                    return false;
                }
                $allTypes = \craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes();
                if (empty($allTypes)) {
                    return false;
                }
                $allHandles = array_map(fn($type) => $type->handle, $allTypes);
                $excluded = $settings->excludedProductTypes ?? [];
                return count(array_diff($allHandles, $excluded)) > 0;

            case \craft\digitalproducts\elements\Product::class:
                // Digital products are indexed if plugin is installed and not all types are excluded
                if (!class_exists(\craft\digitalproducts\elements\Product::class)) {
                    return false;
                }
                if (!\Craft::$app->getPlugins()->isPluginInstalled('digital-products')) {
                    return false;
                }
                $allTypes = \craft\digitalproducts\Plugin::getInstance()->getProductTypes()->getAllProductTypes();
                if (empty($allTypes)) {
                    return false;
                }
                $allHandles = array_map(fn($type) => $type->handle, $allTypes);
                $excluded = $settings->excludedDigitalProductTypes ?? [];
                return count(array_diff($allHandles, $excluded)) > 0;

            default:
                return false;
        }
    }

    /**
     * Check if any indexed element types have URLs
     *
     * @return bool True if at least one indexed element type typically has URLs
     * @since 4.0.0
     */
    private function hasElementsWithUrls(): bool
    {
        // Entries and Categories typically have URLs
        // Assets might have URLs depending on configuration
        // Products typically have URLs
        return $this->isElementTypeIndexed(\craft\elements\Entry::class) ||
               $this->isElementTypeIndexed(\craft\elements\Category::class) ||
               (class_exists(\craft\commerce\elements\Product::class) && $this->isElementTypeIndexed(\craft\commerce\elements\Product::class)) ||
               (class_exists(\craft\digitalproducts\elements\Product::class) && $this->isElementTypeIndexed(\craft\digitalproducts\elements\Product::class));
    }

    /**
     * Check if a field is used in the extraFields configuration
     *
     * @param string $fieldName The field name to check
     * @return bool True if the field is referenced in extraFields
     * @since 4.0.0
     */
    private function isFieldUsedInExtraFields(string $fieldName): bool
    {
        $extraFields = SearchWithElastic::getInstance()->getSettings()->extraFields ?? [];
        return isset($extraFields[$fieldName]);
    }

    /**
     * Check if there are any structure sections configured for indexing
     *
     * @return bool True if at least one structure section is configured for indexing
     * @since 4.0.0
     */
    private function hasStructureSections(): bool
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        $sections = \Craft::$app->entries->getAllSections();

        foreach ($sections as $section) {
            // Check if this is a structure section
            if ($section->type === 'structure') {
                // Check if any of its entry types are not excluded
                $entryTypes = $section->getEntryTypes();
                foreach ($entryTypes as $entryType) {
                    if (!in_array($entryType->handle, $settings->excludedEntryTypes ?? [], true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

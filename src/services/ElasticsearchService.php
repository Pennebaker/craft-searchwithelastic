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
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\helpers\Db;
use DateTime;
use Exception;
use pennebaker\searchwithelastic\events\connection\ConnectionTestEvent;
use pennebaker\searchwithelastic\events\search\SearchEvent;
use pennebaker\searchwithelastic\SearchWithElastic;

/**
 * The Elasticsearch service provides APIs for managing Elasticsearch operations.
 *
 * An instance of the service is available via [[\pennebaker\searchwithelastic\SearchWithElastic::getInstance()|`SearchWithElastic::getInstance()->service`]].
 *
 * @author Pennebaker
 * @since 4.0.0
 *
 * @property-read array $allIndexStats
 */
class ElasticsearchService extends Component
{
    // Event constants following Craft patterns
    public const EVENT_BEFORE_SEARCH = 'beforeSearch';
    public const EVENT_AFTER_SEARCH = 'afterSearch';
    public const EVENT_BEFORE_CONNECTION_TEST = 'beforeConnectionTest';
    public const EVENT_AFTER_CONNECTION_TEST = 'afterConnectionTest';

    /**
     * Test the connection to the Elasticsearch server
     *
     * @return bool `true` if the connection succeeds, `false` otherwise.
     * @since 4.0.0
     */
    public function testConnection(): bool
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Fire a 'beforeConnectionTest' event
        $event = new ConnectionTestEvent([
            'endpoint' => $settings->elasticsearchEndpoint,
            'config' => [
                'isAuthEnabled' => $settings->isAuthEnabled,
                'username' => $settings->username ? '***' : null, // Don't expose password
            ],
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_CONNECTION_TEST)) {
            $this->trigger(self::EVENT_BEFORE_CONNECTION_TEST, $event);
        }

        // If event handler wants to skip default test and provides result
        if ($event->skipDefaultTest && $event->result !== null) {
            return $event->result;
        }

        try {
            $connection = SearchWithElastic::getConnection();
            if (count($connection->nodes) < 1) {
                $event->result = false;
                $event->errorMessage = 'No Elasticsearch nodes configured';
            } else {
                $connection->open();
                $connection->activeNode = array_keys($connection->nodes)[0];
                $connection->getNodeInfo();
                $event->result = true;
            }
        } catch (\yii\elasticsearch\Exception|\yii\base\Exception $e) {
            $event->result = false;
            $event->errorMessage = $e->getMessage();
        } finally {
            if (isset($connection)) {
                $connection->close();
            }
        }

        // Fire an 'afterConnectionTest' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_CONNECTION_TEST)) {
            $this->trigger(self::EVENT_AFTER_CONNECTION_TEST, $event);
        }

        return $event->result ?? false;
    }

    /**
     * Get element index record from the database
     *
     * @param Element $element The element to get the index record for
     * @return object|null The index record or null if not found
     * @since 4.0.0
     */
    public function getElementIndex(Element $element): ?object
    {
        return SearchWithElastic::getInstance()->records->getElementRecord($element);
    }

    /**
     * Get element index status
     *
     * @param Element $element The element to check
     * @return string One of: 'indexed', 'partial', 'not_indexed', 'outdated'
     * @throws Exception
     * @since 4.0.0
     */
    public function getElementIndexStatus(Element $element): string
    {
        $record = SearchWithElastic::getInstance()->records->getElementRecord($element);
        if (!$record) {
            return 'not_indexed';
        }

        // Check if element has been updated since last index
        $elementUpdated = $element->dateUpdated ?? $element->dateCreated;
        $indexUpdated = $record->dateUpdated ? new DateTime($record->dateUpdated) : null;

        if (!$indexUpdated) {
            return 'not_indexed';
        }

        if ($elementUpdated > $indexUpdated) {
            return 'outdated';
        }

        // Check if this should be a partial index (missing frontend content when it should be there)
        if ($this->isPartialIndex($element, $record)) {
            return 'partial';
        }

        return 'indexed';
    }

    /**
     * Check if the indexed element is missing expected frontend content
     *
     * @param Element $element The element to check
     * @param object $record The index record
     * @return bool True if the index is partial (missing frontend content)
     * @since 4.0.0
     */
    protected function isPartialIndex(Element $element, object $record): bool
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Only check if frontend fetching is enabled
        if (!$settings->enableFrontendFetching) {
            return false;
        }

        // Only check if element has a URL
        if (!$element->getUrl()) {
            return false;
        }

        // Check if this element type should have frontend content
        $shouldHaveFrontendContent = false;

        switch (get_class($element)) {
            case Entry::class:
                $shouldHaveFrontendContent = !in_array($element->type->handle, $settings->excludedFrontendFetchingEntryTypes, true);
                break;
            case Asset::class:
                // Binary assets (PDFs, images, videos, audio) can't have text content extracted from URLs
                $binaryKinds = ['pdf', 'image', 'video', 'audio'];
                if (!in_array($element->kind, $binaryKinds, true)) {
                    $shouldHaveFrontendContent = !in_array($element->volume->handle, $settings->excludedFrontendFetchingAssetVolumes, true) &&
                        in_array($element->kind, $settings->frontendFetchingAssetKinds, true);
                }
                break;
            case Category::class:
                $shouldHaveFrontendContent = !in_array($element->group->handle, $settings->excludedFrontendFetchingCategoryGroups, true);
                break;
        }

        if (!$shouldHaveFrontendContent) {
            return false;
        }

        // Get the actual document from Elasticsearch to check content
        try {
            $connection = SearchWithElastic::getConnection();
            $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($element->siteId, get_class($element));
            $documentId = $element->id . '_' . $element->siteId;

            $response = $connection->createCommand()->get($indexName, '_doc', $documentId);

            if ($response && isset($response['found']) && $response['found']) {
                $source = $response['_source'];
                // Check if content field is missing or empty
                return empty($source['content']);
            }
        } catch (Exception) {
            // If we can't check, assume it's not partial
            return false;
        }

        return false;
    }

    /**
     * Get element index revision number for a specific update date
     *
     * @param Element $element The element to check
     * @param string $updatedDate The update date to match against
     * @return int|null The revision number or null if not found
     */
    public function getElementIndexRevision(Element $element, string $updatedDate): ?int
    {
        $revisions = [];

        if ($element->hasRevisions()) {
            $revisionQuery = $element::find()
                ->revisionOf($element)
                ->siteId($element->siteId)
                ->orderBy(['dateCreated' => SORT_DESC]);
            $revisions = $revisionQuery->all();
        }

        if ($revisions) {
            foreach ($revisions as $revision) {
                $revisionUpdateDate = $revision->dateUpdated;
                if ($revisionUpdateDate) {
                    // Convert both dates to same format for comparison
                    $revisionDateFormatted = $revisionUpdateDate->format('c');

                    // Also try Y-m-d H:i:s format as fallback
                    $revisionDateDb = Db::prepareDateForDb($revisionUpdateDate);

                    if ($revisionDateFormatted === $updatedDate || $revisionDateDb === $updatedDate) {
                        return $revision->revisionNum;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Search in Elasticsearch with basic matching
     *
     * @param string $query The search query
     * @param int|null $siteId The site ID to search, or null for current site
     * @return array<string, mixed> The search results
     * @throws SiteNotFoundException
     * @since 4.0.0
     */
    public function search(string $query, ?int $siteId = null): array
    {
        return $this->advancedSearch($query, [
            'siteId' => $siteId,
            'fuzzy' => false,
            'fields' => ['title', 'content']
        ]);
    }

    /**
     * Advanced search in Elasticsearch with fuzzy matching and field selection
     *
     * @param string $query The search query
     * @param array<string, mixed> $options Search options including fuzzy, fields, siteId, size
     * @return array<string, mixed> The search results
     * @throws SiteNotFoundException
     * @since 4.0.0
     */
    public function advancedSearch(string $query, array $options = []): array
    {
        // Parse options with defaults
        $siteId = $options['siteId'] ?? null;
        $fuzzy = $options['fuzzy'] ?? true;
        $fields = $options['fields'] ?? ['title', 'content'];
        $size = $options['size'] ?? 50;

        // Use current site if no site ID provided
        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        // Fire a 'beforeSearch' event
        $event = new SearchEvent([
            'query' => $query,
            'params' => $options,
            'siteId' => $siteId,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
            $this->trigger(self::EVENT_BEFORE_SEARCH, $event);
        }

        // If event handler wants to skip default search
        if ($event->skipDefaultSearch) {
            return [];
        }

        try {
            $connection = SearchWithElastic::getConnection();
            $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($siteId);

            // Check if index exists
            if (!$connection->createCommand()->indexExists($indexName)) {
                Craft::warning("Search index '$indexName' does not exist for site $siteId", __METHOD__);
                return [];
            }

            // Use potentially modified query and params from event
            $searchQuery = is_string($event->query) ? $event->query : $query;
            $searchParams = $event->params;

            // Update options based on event modifications
            $fuzzy = $searchParams['fuzzy'] ?? $fuzzy;
            $fields = $searchParams['fields'] ?? $fields;
            $size = $searchParams['size'] ?? $size;

            // Build query based on search type and query content
            $queryBuilder = new ElasticsearchQueryBuilder();
            $queryResult = $queryBuilder->buildSearchQuery($searchQuery, $fields, $fuzzy);

            // Get highlight settings from plugin configuration
            $settings = SearchWithElastic::getInstance()->getSettings();
            
            // Check if we got template parameters or a direct query
            if (isset($queryResult['template_id']) && isset($queryResult['params'])) {
                // Use template-based search for security
                $templateService = SearchWithElastic::getInstance()->searchTemplates;
                
                // Ensure templates are initialized
                $templateService->initializeTemplates();
                
                // Prepare search options
                $searchOptions = [
                    'size' => $size
                ];
                
                // Add highlighting if configured
                if (!empty($settings->highlight['pre_tags']) || !empty($settings->highlight['post_tags'])) {
                    $searchOptions['highlight'] = [
                        'pre_tags' => [$settings->highlight['pre_tags']],
                        'post_tags' => [$settings->highlight['post_tags']],
                        'fields' => []
                    ];
                    
                    foreach ($fields as $field) {
                        $searchOptions['highlight']['fields'][$field] = (object)[];
                    }
                }
                
                Craft::info("Searching index '$indexName' with template '" . $queryResult['template_id'] . "' and query: " . $query . " (fuzzy: " . ($fuzzy ? 'yes' : 'no') . ")", __METHOD__);
                
                $response = $templateService->executeTemplateSearch(
                    $queryResult['template_id'],
                    $queryResult['params'],
                    $indexName,
                    $searchOptions
                );
            } else {
                // Fallback to direct query (for match_all or backward compatibility)
                // For Elasticsearch 7+, size and highlight are top-level parameters
                $searchParams = [
                    'index' => $indexName,
                    'size' => $size,
                    'body' => [
                        'query' => $queryResult
                    ]
                ];
                
                // Add highlighting if configured - as a top-level parameter
                if (!empty($settings->highlight['pre_tags']) || !empty($settings->highlight['post_tags'])) {
                    $highlightFields = [];
                    foreach ($fields as $field) {
                        $highlightFields[$field] = new \stdClass();
                    }
                    
                    $searchParams['highlight'] = [
                        'pre_tags' => [$settings->highlight['pre_tags']],
                        'post_tags' => [$settings->highlight['post_tags']],
                        'fields' => $highlightFields
                    ];
                }
                
                Craft::info("Searching index '$indexName' with direct query: " . $query . " (fuzzy: " . ($fuzzy ? 'yes' : 'no') . ")", __METHOD__);
                Craft::info("Search params: " . json_encode($searchParams, JSON_THROW_ON_ERROR), __METHOD__);
                
                // Use direct service to bypass Yii2 Elasticsearch library issues
                $response = ElasticsearchDirectService::search($searchParams);
            }
            $hits = $response['hits']['hits'] ?? [];

            Craft::info("Search returned " . count($hits) . " results", __METHOD__);

            // Apply result formatter callback if configured
            if ($settings->resultFormatterCallback && is_callable($settings->resultFormatterCallback)) {
                $validator = SearchWithElastic::getInstance()->callbackValidator;
                foreach ($hits as &$hit) {
                    try {
                        $originalHit = $hit;
                        // Validate and execute callback safely
                        $hit = $validator->safeExecute(
                            $settings->resultFormatterCallback,
                            [$hit, $hit],
                            'resultFormatterCallback',
                            $originalHit
                        );

                        // Ensure the callback returned a valid result
                        if (!is_array($hit)) {
                            Craft::warning("resultFormatterCallback returned invalid result type, using original", __METHOD__);
                            $hit = $originalHit;
                        }
                    } catch (Exception $e) {
                        Craft::warning("resultFormatterCallback failed: " . $e->getMessage(), __METHOD__);
                        // Continue with unformatted result
                        $hit = $originalHit;
                    }
                }
                unset($hit); // Break reference
            }

            // Fire an 'afterSearch' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
                // Update event with search results
                $event->params['results'] = $hits;
                $this->trigger(self::EVENT_AFTER_SEARCH, $event);
            }

            return $hits;
        } catch (Exception $e) {
            Craft::error('Elasticsearch search error: ' . $e->getMessage(), __METHOD__);
            return [];
        }
    }


    /**
     * Get basic index information for debugging
     *
     * @param int|string $indexOrSiteId Index name (string) or site ID (integer) - required
     * @return array Index information including existence and document count
     */
    public function getIndexStats(int|string $indexOrSiteId): array
    {
        try {
            $connection = SearchWithElastic::getConnection();

            // Determine if parameter is index name or site ID
            if (is_string($indexOrSiteId)) {
                // Direct index name provided
                $indexName = $indexOrSiteId;
                $siteId = null; // Cannot determine site ID from index name
            } elseif (is_int($indexOrSiteId)) {
                // Site ID provided
                $siteId = $indexOrSiteId;
                $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($siteId);
            } else {
                throw new \InvalidArgumentException('Index name (string) or site ID (integer) is required');
            }

            // Check if our specific index exists
            $indexExists = $connection->createCommand()->indexExists($indexName);

            $result = [
                'exists' => $indexExists,
                'indexName' => $indexName,
                'documentCount' => 0
            ];

            // Only include siteId in result if it was provided/determinable
            if ($siteId !== null) {
                $result['siteId'] = $siteId;
            }

            // If index exists, try to get document count and additional stats
            if ($indexExists) {
                try {
                    $countResponse = $connection->get([$indexName, '_count']);
                    $result['documentCount'] = isset($countResponse['count']) ? (int)$countResponse['count'] : 0;

                    // Get index settings for additional info
                    try {
                        $settingsResponse = $connection->get([$indexName, '_settings']);
                        if (isset($settingsResponse[$indexName]['settings']['index'])) {
                            $indexSettings = $settingsResponse[$indexName]['settings']['index'];
                            $result['settings'] = [
                                'numberOfShards' => $indexSettings['number_of_shards'] ?? null,
                                'numberOfReplicas' => $indexSettings['number_of_replicas'] ?? null,
                                'creationDate' => isset($indexSettings['creation_date'])
                                    ? date('Y-m-d H:i:s', $indexSettings['creation_date'] / 1000)
                                    : null
                            ];
                        }
                    } catch (Exception $e) {
                        $result['settingsError'] = $e->getMessage();
                    }
                } catch (Exception $e) {
                    $result['countError'] = $e->getMessage();
                }
            }

            return $result;
        } catch (Exception $e) {
            Craft::error('Failed to get index info: ' . $e->getMessage(), __METHOD__);
            return [
                'exists' => false,
                'error' => $e->getMessage(),
                'indexName' => $indexName ?? null,
                'siteId' => $siteId ?? null
            ];
        }
    }

    /**
     * Get statistics for all Craft-related indexes
     *
     * @return array Array of index statistics for all Craft indexes
     */
    public function getAllIndexStats(): array
    {
        try {
            $connection = SearchWithElastic::getConnection();
            $allIndexes = $this->getAllCraftIndexes($connection);

            $result = [
                'totalIndexes' => count($allIndexes),
                'totalDocuments' => 0,
                'indexes' => []
            ];

            foreach ($allIndexes as $indexInfo) {
                $indexName = $indexInfo['name'];
                $detailedStats = [
                    'name' => $indexName,
                    'docCount' => $indexInfo['docCount'],
                    'size' => $indexInfo['size'],
                    'exists' => true,
                    'documentCount' => $indexInfo['docCount']
                ];

                // Add total counts
                $result['totalDocuments'] += (int)$indexInfo['docCount'];

                // Try to get additional index details
                try {
                    $settingsResponse = $connection->get([$indexName, '_settings']);
                    if (isset($settingsResponse[$indexName]['settings']['index'])) {
                        $indexSettings = $settingsResponse[$indexName]['settings']['index'];
                        $detailedStats['settings'] = [
                            'numberOfShards' => $indexSettings['number_of_shards'] ?? 'unknown',
                            'numberOfReplicas' => $indexSettings['number_of_replicas'] ?? 'unknown',
                            'creationDate' => isset($indexSettings['creation_date'])
                                ? date('Y-m-d H:i:s', $indexSettings['creation_date'] / 1000)
                                : 'unknown'
                        ];
                    }
                } catch (Exception $e) {
                    $detailedStats['settingsError'] = $e->getMessage();
                }

                // Try to get mapping information
                try {
                    $mappingResponse = $connection->get([$indexName, '_mapping']);
                    if (isset($mappingResponse[$indexName]['mappings']['properties'])) {
                        $properties = $mappingResponse[$indexName]['mappings']['properties'];
                        $detailedStats['fieldCount'] = count($properties);
                        $detailedStats['fields'] = array_keys($properties);
                    }
                } catch (Exception $e) {
                    $detailedStats['mappingError'] = $e->getMessage();
                }

                $result['indexes'][] = $detailedStats;
            }

            return $result;
        } catch (Exception $e) {
            Craft::error('Failed to get all index stats: ' . $e->getMessage(), __METHOD__);
            return [
                'error' => $e->getMessage(),
                'totalIndexes' => 0,
                'indexes' => []
            ];
        }
    }

    /**
     * Helper method to get all Craft-related indexes
     *
     * @param object $connection Elasticsearch connection
     * @return array Array of index information
     */
    protected function getAllCraftIndexes(object $connection): array
    {
        $allIndexes = [];
        try {
            $catResponse = $connection->get(['_cat', 'indices'], ['format' => 'json']);
            foreach ($catResponse as $index) {
                if (isset($index['index']) && str_starts_with($index['index'], 'craft-')) {
                    $allIndexes[] = [
                        'name' => $index['index'],
                        'docCount' => $index['docs.count'] ?? 0,
                        'size' => $index['store.size'] ?? '0b',
                        'health' => $index['health'] ?? 'unknown',
                        'status' => $index['status'] ?? 'unknown'
                    ];
                }
            }
        } catch (Exception $e) {
            Craft::warning('Failed to get Craft indexes: ' . $e->getMessage(), __METHOD__);
        }
        return $allIndexes;
    }

    /**
     * Get a sample document from the index for debugging
     *
     * @param int|null $siteId The site ID, or null for current site
     * @return array Sample document to see what fields exist
     */
    public function getSampleDocument(int $siteId = null): array
    {
        try {
            // Use current site if no site ID provided
            if ($siteId === null) {
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
            }

            $connection = SearchWithElastic::getConnection();
            $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($siteId);

            // Check if index exists
            if (!$connection->createCommand()->indexExists($indexName)) {
                return ['error' => 'Index does not exist'];
            }

            // Get one document
            $searchBody = [
                'query' => ['match_all' => (object)[]],
                'size' => 1
            ];

            $response = $connection->post([$indexName, '_search'], [], json_encode($searchBody, JSON_THROW_ON_ERROR));
            $hits = $response['hits']['hits'] ?? [];

            if (empty($hits)) {
                return ['error' => 'No documents in index'];
            }

            $source = $hits[0]['_source'] ?? [];

            return [
                'success' => true,
                'document' => $source,
                'fields' => array_keys($source)
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Check if the index is in sync with all elements
     *
     * @return bool True if all indexes are in sync and no pending elements exist
     */
    public function isIndexInSync(): bool
    {
        try {
            $connection = SearchWithElastic::getConnection();
            $sites = Craft::$app->sites->getAllSites();

            foreach ($sites as $site) {
                $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($site->id);

                // Check if index exists
                if (!$connection->createCommand()->indexExists($indexName)) {
                    return false;
                }

                // Check if there are pending elements to index
                $pendingCount = SearchWithElastic::getInstance()->records->getPendingElementsCount($site->id);
                if ($pendingCount > 0) {
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            Craft::error('Error checking index sync: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Get indexable element models based on criteria
     *
     * @param array|null $siteIds Site IDs to include, or null for all sites
     * @param array $elementTypes Element types to include
     * @param string $reindexMode Reindex mode: 'reset', 'all', 'missing', 'updated', 'missing-updated'
     * @return array Array of indexable element models
     * @throws Exception
     */
    public function getIndexableElementModels(array $siteIds = null, array $elementTypes = [], string $reindexMode = 'reset'): array
    {
        $models = [];
        if ($siteIds) {
            $sites = [];
            foreach ($siteIds as $siteId) {
                $site = Craft::$app->sites->getSiteById($siteId);
                if ($site) {
                    $sites[] = $site;
                }
            }
        } else {
            $sites = Craft::$app->sites->getAllSites();
        }

        foreach ($sites as $site) {
            $siteElementTypes = $this->getElementTypesForSite($site->id, $elementTypes);

            // Determine if we should process this site
            $shouldProcessSite = false;

            if (empty($elementTypes)) {
                // No filtering specified - process all sites
                $shouldProcessSite = true;
            } elseif (!empty($siteElementTypes)) {
                // This site has specific element types selected
                $shouldProcessSite = true;
            }

            if (!$shouldProcessSite) {
                continue;
            }

            // Helper function to check if we should process an element type
            $shouldProcessType = static function($typeName) use ($elementTypes, $siteElementTypes) {
                // If no filtering, process all types
                if (empty($elementTypes)) {
                    return true;
                }
                // If filtering, only process if this type is in the site's list
                return in_array($typeName, $siteElementTypes, true);
            };

            // Get entries if requested
            if ($shouldProcessType('entries')) {
                $entryQuery = SearchWithElastic::getInstance()->queries->getIndexableEntryQuery($site->id);
                foreach ($entryQuery->all() as $entry) {
                    if ($this->shouldIncludeElement($entry, $reindexMode)) {
                        $models[] = SearchWithElastic::getInstance()->models->createIndexableElementModel($entry, $site->id);
                    }
                }
            }

            // Get assets if requested
            if ($shouldProcessType('assets')) {
                $assetQuery = SearchWithElastic::getInstance()->queries->getIndexableAssetQuery($site->id);
                foreach ($assetQuery->all() as $asset) {
                    if ($this->shouldIncludeElement($asset, $reindexMode)) {
                        $models[] = SearchWithElastic::getInstance()->models->createIndexableElementModel($asset, $site->id);
                    }
                }
            }

            // Get categories if requested
            if ($shouldProcessType('categories')) {
                $categoryQuery = SearchWithElastic::getInstance()->queries->getIndexableCategoryQuery($site->id);
                foreach ($categoryQuery->all() as $category) {
                    if ($this->shouldIncludeElement($category, $reindexMode)) {
                        $models[] = SearchWithElastic::getInstance()->models->createIndexableElementModel($category, $site->id);
                    }
                }
            }

            // Get commerce products if available and requested
            if ($shouldProcessType('products') && Craft::$app->plugins->isPluginInstalled('commerce')) {
                $productQuery = SearchWithElastic::getInstance()->queries->getIndexableProductQuery($site->id);
                foreach ($productQuery->all() as $product) {
                    if ($this->shouldIncludeElement($product, $reindexMode)) {
                        $models[] = SearchWithElastic::getInstance()->models->createIndexableElementModel($product, $site->id);
                    }
                }
            }

            // Get digital products if available and requested
            if ($shouldProcessType('digitalProducts') && Craft::$app->plugins->isPluginInstalled('digital-products')) {
                $digitalProductQuery = SearchWithElastic::getInstance()->queries->getIndexableDigitalProductQuery($site->id);
                foreach ($digitalProductQuery->all() as $digitalProduct) {
                    if ($this->shouldIncludeElement($digitalProduct, $reindexMode)) {
                        $models[] = SearchWithElastic::getInstance()->models->createIndexableElementModel($digitalProduct, $site->id);
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Get element types for a specific site from the element types filter
     *
     * @param int $siteId The site ID
     * @param array $elementTypes Element types configuration from getElementTypes()
     * @return array Array of element types for the site, or empty array if no filtering
     */
    private function getElementTypesForSite(int $siteId, array $elementTypes): array
    {
        // If no element type filtering at all, return empty (means all types)
        if (empty($elementTypes)) {
            return [];
        }

        // Return the element types for this specific site, or empty array if site not found
        return $elementTypes[$siteId] ?? [];
    }

    /**
     * Determine if an element should be included based on the reindex mode
     *
     * @param Element $element The element to check
     * @param string $reindexMode The reindex mode
     * @return bool True if the element should be included
     * @throws Exception
     */
    private function shouldIncludeElement(Element $element, string $reindexMode): bool
    {
        switch ($reindexMode) {
            case 'all':
            case 'reset':
                // Include all elements
                return true;

            case 'missing':
                // Only include elements not currently in the index
                return $this->getElementIndexStatus($element) === 'not_indexed';

            case 'updated':
                // Only include elements that have been updated since last index
                return $this->getElementIndexStatus($element) === 'outdated';

            case 'missing-updated':
                // Include elements that are missing or outdated
                $status = $this->getElementIndexStatus($element);
                return in_array($status, ['not_indexed', 'outdated']);

            default:
                // Default to including all elements
                return true;
        }
    }

}

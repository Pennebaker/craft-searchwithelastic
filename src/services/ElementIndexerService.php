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
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use pennebaker\searchwithelastic\events\indexing\ContentExtractionEvent;
use pennebaker\searchwithelastic\events\indexing\IndexElementEvent;
use pennebaker\searchwithelastic\exceptions\IndexElementException;
use pennebaker\searchwithelastic\models\IndexingResult;
use pennebaker\searchwithelastic\SearchWithElastic;
use pennebaker\searchwithelastic\services\CallbackValidator;
use RuntimeException;
use yii\base\InvalidConfigException;

/**
 * The Element Indexer service provides APIs for indexing individual elements in Elasticsearch.
 *
 * An instance of the service is available via [[\pennebaker\searchwithelastic\SearchWithElastic::getInstance()|`SearchWithElastic::getInstance()->elementIndexer`]].
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ElementIndexerService extends Component
{
    /**
     * @since 4.0.0
     */
    public const EVENT_CONTENT_EXTRACTION = 'contentExtraction';
    
    /**
     * @since 4.0.0
     */
    public const EVENT_BEFORE_INDEX_ELEMENT = 'beforeIndexElement';
    
    /**
     * @since 4.0.0
     */
    public const EVENT_AFTER_INDEX_ELEMENT = 'afterIndexElement';
    
    /**
     * @since 4.0.0
     */
    public const EVENT_BEFORE_REMOVE_ELEMENT = 'beforeRemoveElement';
    
    /**
     * @since 4.0.0
     */
    public const EVENT_AFTER_REMOVE_ELEMENT = 'afterRemoveElement';

    /**
     * Index an element in Elasticsearch
     *
     * @param Element $element The element to index
     * @return IndexingResult The indexing result with status and details
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public function indexElement(Element $element): IndexingResult
    {
        $connection = SearchWithElastic::getConnection();
        $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($element->siteId, get_class($element));

        // Check if element should be indexed
        if (!$this->shouldIndexElement($element)) {
            $reason = $this->getSkipReason($element);
            if ($this->isElementTypeDisabled($element)) {
                return IndexingResult::disabled(
                    $reason,
                    Craft::t('search-with-elastic', 'Element type is disabled for indexing')
                );
            }
            return IndexingResult::skipped(
                $reason,
                Craft::t('search-with-elastic', 'Element was skipped: {reason}', ['reason' => $reason])
            );
        }

        // Create indexable element model
        $model = SearchWithElastic::getInstance()->models->createIndexableElementModel($element, $element->siteId);

        // Prepare document for indexing
        $result = $this->prepareElementDocument($element, $model);
        $document = $result['document'];
        $frontendFetchAttempted = $result['frontendFetchAttempted'];
        $frontendFetchSuccess = $result['frontendFetchSuccess'];
        $frontendFetchDebugInfo = $result['frontendFetchDebugInfo'] ?? [];

        // Fire a 'beforeIndexElement' event
        $event = new IndexElementEvent([
            'element' => $element,
            'documentData' => $document,
            'indexName' => $indexName,
            'isNew' => !SearchWithElastic::getInstance()->records->getElementRecord($element),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_ELEMENT)) {
            $this->trigger(self::EVENT_BEFORE_INDEX_ELEMENT, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return IndexingResult::success(
                Craft::t('search-with-elastic', 'Element indexing handled by event listener')
            );
        }

        try {
            // Use potentially modified document data from event
            $documentId = $element->id . '_' . $element->siteId;
            $response = $connection->createCommand()->insert($indexName, '_doc', $event->documentData, $documentId);

            // Update local record
            $this->updateElementRecord($element, $documentId);

            Craft::info("Indexed element $element->id in site $element->siteId", __METHOD__);

            // Fire an 'afterIndexElement' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_INDEX_ELEMENT)) {
                $this->trigger(self::EVENT_AFTER_INDEX_ELEMENT, $event);
            }

            // Determine if this is a partial index
            if ($frontendFetchAttempted && !$frontendFetchSuccess) {
                $result = IndexingResult::partial(
                    'Frontend content fetching failed',
                    Craft::t('search-with-elastic', 'Element indexed with basic fields only - frontend content fetch failed')
                );
                $result->frontendFetchAttempted = true;
                $result->frontendFetchSuccess = false;
                $this->setFrontendFetchDebugInfo($result, $frontendFetchDebugInfo);
                return $result;
            }

            $result = IndexingResult::success(
                Craft::t('search-with-elastic', 'Element successfully indexed')
            );
            $result->frontendFetchAttempted = $frontendFetchAttempted;
            $result->frontendFetchSuccess = $frontendFetchSuccess;
            $this->setFrontendFetchDebugInfo($result, $frontendFetchDebugInfo);
            return $result;

        } catch (\Exception $e) {
            Craft::error("Failed to index element $element->id: " . $e->getMessage(), __METHOD__);
            return IndexingResult::failed(
                'Indexing failed',
                Craft::t('search-with-elastic', 'Failed to index element: {error}', ['error' => $e->getMessage()]),
                $e->getMessage()
            );
        }
    }

    /**
     * Delete an element from the Elasticsearch index
     *
     * @param Element $element The element to delete
     * @return int The number of indexes the element was deleted from
     * @throws IndexElementException If deletion fails
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public function deleteElement(Element $element): int
    {
        $connection = SearchWithElastic::getConnection();

        // Fire a 'beforeRemoveElement' event
        $event = new IndexElementEvent([
            'element' => $element,
            'documentData' => [], // No document data for deletion
            'indexName' => '', // Will be set per site
            'isNew' => false,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_REMOVE_ELEMENT)) {
            $this->trigger(self::EVENT_BEFORE_REMOVE_ELEMENT, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return 0;
        }

        try {
            $deletedCount = 0;

            // Delete from all sites if element exists in multiple sites
            $sites = Craft::$app->sites->getAllSites();

            foreach ($sites as $site) {
                $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($site->id);
                $documentId = $element->id . '_' . $site->id;

                try {
                    $connection->createCommand()->delete($indexName, '_doc', $documentId);
                    $deletedCount++;
                } catch (\Exception) {
                    // Document might not exist in this index, continue with next site
                }
            }

            // Remove local record
            $this->removeElementRecord($element);

            Craft::info("Deleted element $element->id from $deletedCount indexes", __METHOD__);

            // Fire an 'afterRemoveElement' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_REMOVE_ELEMENT)) {
                $this->trigger(self::EVENT_AFTER_REMOVE_ELEMENT, $event);
            }

            return $deletedCount;
        } catch (\Exception $e) {
            Craft::error("Failed to delete element $element->id: " . $e->getMessage(), __METHOD__);
            throw new IndexElementException(
                "Failed to delete element: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get the reason why an element should be skipped from indexing
     *
     * @param Element $element The element to check
     * @return string The skip reason
     * @since 4.0.0
     */
    protected function getSkipReason(Element $element): string
    {
        // Check if it's a draft or revision first
        if (ElementHelper::isDraftOrRevision($element)) {
            return 'Element is a draft or revision';
        }

        $settings = SearchWithElastic::getInstance()->getSettings();

        // Check URL requirement
        if (!$settings->indexElementsWithoutUrls && !$element->getUrl()) {
            return 'Element has no URL and URL indexing is required';
        }

        switch (get_class($element)) {
            case Entry::class:
                if (in_array($element->type->handle, $settings->excludedEntryTypes, true)) {
                    return 'Entry type is excluded';
                }
                if (!in_array($element->status, $settings->indexableEntryStatuses, true)) {
                    return 'Entry status not indexable';
                }
                break;

            case Asset::class:
                if (in_array($element->volume->handle, $settings->excludedAssetVolumes, true)) {
                    return 'Asset volume is excluded';
                }
                if (!in_array($element->kind, $settings->assetKinds, true)) {
                    return 'Asset kind not indexable';
                }
                break;

            case Category::class:
                if (in_array($element->group->handle, $settings->excludedCategoryGroups, true)) {
                    return 'Category group is excluded';
                }
                if (!in_array($element->status, $settings->indexableCategoryStatuses, true)) {
                    return 'Category status not indexable';
                }
                break;
        }

        // Check Commerce products
        if (class_exists(Product::class) && $element instanceof Product) {
            if (in_array($element->type->handle, $settings->excludedProductTypes, true)) {
                return 'Product type is excluded';
            }
            if (!in_array($element->status, $settings->indexableProductStatuses, true)) {
                return 'Product status not indexable';
            }
        }

        // Check Digital Products
        if (class_exists(DigitalProduct::class) && $element instanceof DigitalProduct) {
            if (in_array($element->type->handle, $settings->excludedDigitalProductTypes, true)) {
                return 'Digital product type is excluded';
            }
            if (!in_array($element->status, $settings->indexableDigitalProductStatuses, true)) {
                return 'Digital product status not indexable';
            }
        }

        return 'Element cannot be indexed';
    }

    /**
     * Check if element type is disabled for indexing
     *
     * @param Element $element The element to check
     * @return bool True if the element type is disabled
     * @since 4.0.0
     */
    protected function isElementTypeDisabled(Element $element): bool
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        return match (get_class($element)) {
            Entry::class => in_array($element->type->handle, $settings->excludedEntryTypes, true),
            Asset::class => in_array($element->volume->handle, $settings->excludedAssetVolumes, true) ||
                !in_array($element->kind, $settings->assetKinds, true),
            Category::class => in_array($element->group->handle, $settings->excludedCategoryGroups, true),
            default => false,
        };

    }

    /**
     * Check if element should be indexed based on settings
     *
     * @param Element $element The element to check
     * @return bool True if the element should be indexed
     * @since 4.0.0
     */
    protected function shouldIndexElement(Element $element): bool
    {
        // Skip drafts and revisions
        if (ElementHelper::isDraftOrRevision($element)) {
            return false;
        }

        $settings = SearchWithElastic::getInstance()->getSettings();

        // Check if element has no URL and that's not allowed
        if (!$settings->indexElementsWithoutUrls && !$element->getUrl()) {
            return false;
        }

        // Check element type specific excludes
        switch (get_class($element)) {
            case Entry::class:
                return !in_array($element->type->handle, $settings->excludedEntryTypes, true) &&
                    in_array($element->status, $settings->indexableEntryStatuses, true);

            case Asset::class:
                return !in_array($element->volume->handle, $settings->excludedAssetVolumes, true) &&
                    in_array($element->kind, $settings->assetKinds, true);

            case Category::class:
                return !in_array($element->group->handle, $settings->excludedCategoryGroups, true) &&
                    in_array($element->status, $settings->indexableCategoryStatuses, true);
        }

        // Check Commerce products
        if (class_exists(Product::class) && $element instanceof Product) {
            return !in_array($element->type->handle, $settings->excludedProductTypes, true) &&
                in_array($element->status, $settings->indexableProductStatuses, true);
        }

        // Check Digital Products
        if (class_exists(DigitalProduct::class) && $element instanceof DigitalProduct) {
            return !in_array($element->type->handle, $settings->excludedDigitalProductTypes, true) &&
                in_array($element->status, $settings->indexableDigitalProductStatuses, true);
        }

        return true;
    }

    /**
     * Prepare element document for indexing
     *
     * @param Element $element The element to prepare
     * @param object $model The indexable element model
     * @return array Array with document, frontendFetchAttempted, and frontendFetchSuccess
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    protected function prepareElementDocument(Element $element, object $model): array
    {
        $document = [
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'elementType' => get_class($element),
            'title' => $element->title ?? '',
            'slug' => $element->slug ?? '',
            'status' => $element->status ?? 'enabled',
            'dateCreated' => $element->dateCreated?->format('c'),
            'dateUpdated' => $element->dateUpdated?->format('c'),
            'enabled' => $element->enabled ?? true,
            'archived' => $element->archived ?? false,
        ];

        // Add URL if available
        if ($element->uri !== null) {
            $document['url'] = $element->getUrl();
        }

        // Add element-specific fields
        $this->addElementSpecificFields($element, $document);

        // Add extra fields from plugin settings
        $this->addExtraFields($element, $document);

        // Add content if frontend fetching is enabled
        $fetchResult = $this->addElementContent($element, $document);

        return [
            'document' => $document,
            'frontendFetchAttempted' => $fetchResult['attempted'],
            'frontendFetchSuccess' => $fetchResult['success'],
            'frontendFetchDebugInfo' => $fetchResult['debugInfo'] ?? []
        ];
    }

    /**
     * Add element-specific fields to document based on element type
     *
     * @param Element $element The element to extract fields from
     * @param array $document The document array to add fields to (passed by reference)
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    protected function addElementSpecificFields(Element $element, array &$document): void
    {
        switch (get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                $document['postDate'] = $element->postDate?->format('c');
                $document['expiryDate'] = $element->expiryDate?->format('c');
                break;

            case Asset::class:
                /** @var Asset $element */
                $document['filename'] = $element->filename;
                $document['kind'] = $element->kind;
                $document['size'] = $element->size;
                $document['width'] = $element->width;
                $document['height'] = $element->height;
                break;

            case Category::class:
                /** @var Category $element */
                $document['level'] = $element->level;
                $document['lft'] = $element->lft;
                $document['rgt'] = $element->rgt;
                break;
        }

        // Add Commerce product fields
        if (class_exists(Product::class) && $element instanceof Product) {
            $defaultVariant = $element->getDefaultVariant();
            if ($defaultVariant) {
                $document['price'] = $defaultVariant->price;
                $document['salePrice'] = $defaultVariant->salePrice;
                $document['sku'] = $defaultVariant->sku;
                $document['stock'] = $defaultVariant->stock;
                $document['weight'] = $defaultVariant->weight;
            }
        }
    }

    /**
     * Add element content to document via frontend fetching or asset content extraction
     *
     * @param Element $element The element to extract content from
     * @param array $document The document array to add content to (passed by reference)
     * @return array Array with 'attempted' and 'success' keys indicating fetch status
     * @since 4.0.0
     */
    protected function addElementContent(Element $element, array &$document): array
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        if (!$settings->enableFrontendFetching) {
            return ['attempted' => false, 'success' => false];
        }

        // Check if this element type should use frontend fetching
        $shouldFetch = false;

        switch (get_class($element)) {
            case Entry::class:
                $shouldFetch = !in_array($element->type->handle, $settings->excludedFrontendFetchingEntryTypes, true);
                break;
            case Asset::class:
                $shouldFetch = !in_array($element->volume->handle, $settings->excludedFrontendFetchingAssetVolumes, true) &&
                    in_array($element->kind, $settings->frontendFetchingAssetKinds, true);
                break;
            case Category::class:
                $shouldFetch = !in_array($element->group->handle, $settings->excludedFrontendFetchingCategoryGroups, true);
                break;
        }

        // Check Commerce products
        if (class_exists(Product::class) && $element instanceof Product) {
            $shouldFetch = !in_array($element->type->handle, $settings->excludedFrontendFetchingProductTypes, true);
        }

        // Check Digital Products
        if (class_exists(DigitalProduct::class) && $element instanceof DigitalProduct) {
            $shouldFetch = !in_array($element->type->handle, $settings->excludedFrontendFetchingDigitalProductTypes, true);
        }

        if ($shouldFetch) {
            try {
                // Handle assets differently - use Craft's built-in content extraction
                if ($element instanceof Asset) {
                    // For binary files like PDFs, images, etc., we can't extract text content
                    // But they should still be considered fully indexed based on their metadata
                    $binaryKinds = ['pdf', 'image', 'video', 'audio'];
                    if (in_array($element->kind, $binaryKinds, true)) {
                        // Don't add content field, but mark as successful
                        // The asset is fully indexed with its metadata (title, filename, etc.)
                        return ['attempted' => true, 'success' => true];
                    }

                    // For text-based assets, try to get contents
                    $content = $element->getContents();
                    if ($content !== false) {
                        $contentLength = strlen($content);

                        // Limit content size to prevent memory issues (100KB max)
                        $maxContentLength = 100 * 1024;
                        if ($contentLength > $maxContentLength) {
                            $content = substr($content, 0, $maxContentLength);
                            $content .= "\n\n[Content truncated due to size limit]";
                        }

                        // Remove control characters that could cause issues
                        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
                            $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
                        }

                        $document['content'] = $content;
                        return ['attempted' => true, 'success' => true];
                    }

                    // If we can't get contents for a text asset, still mark as successful
                    // The asset metadata is indexed even without content
                    return ['attempted' => true, 'success' => true];
                }

                // For other element types, fetch content from URL
                if ($element->getUrl()) {
                    $fetchResult = $this->fetchElementContentWithDebug($element->getUrl(), $element);
                    if (!empty($fetchResult['content'])) {
                        $document['content'] = $fetchResult['content'];
                    }
                    return [
                        'attempted' => true,
                        'success' => $fetchResult['success'],
                        'debugInfo' => $fetchResult['debugInfo'] ?? []
                    ];
                }

                // Element has no URL for content fetching
                return ['attempted' => false, 'success' => false];
            } catch (\Exception $e) {
                Craft::warning("Failed to fetch content for element $element->id: " . $e->getMessage(), __METHOD__);
                return ['attempted' => true, 'success' => false];
            }
        }

        return ['attempted' => false, 'success' => false];
    }

    /**
     * Fetch element content from URL with debug information
     *
     * @param string $url The URL to fetch content from
     * @param Element $element The element being indexed
     * @return array Array containing 'content', 'success', and 'debugInfo'
     * @since 4.0.0
     */
    protected function fetchElementContentWithDebug(string $url, Element $element): array
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        $debugInfo = [
            'url' => $url,
            'statusCode' => null,
            'error' => null,
            'headers' => []
        ];

        try {
            $content = $this->fetchElementContent($url, $element, $debugInfo);
            return [
                'content' => $content,
                'success' => !empty($content),
                'debugInfo' => $settings->enableFrontendFetchDebug ? $debugInfo : []
            ];
        } catch (GuzzleException $e) {
            $debugInfo['error'] = $e->getMessage();
            return [
                'content' => '',
                'success' => false,
                'debugInfo' => $settings->enableFrontendFetchDebug ? $debugInfo : []
            ];
        }
    }

    /**
     * Fetch element content from URL for non-asset elements
     *
     * @param string $url The URL to fetch content from
     * @param Element $element The element being indexed
     * @param array|null $debugInfo Reference to array for collecting debug information
     * @return string The extracted text content
     * @throws GuzzleException If the HTTP request fails
     * @since 4.0.0
     */
    protected function fetchElementContent(string $url, Element $element, ?array &$debugInfo = null): string
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Check for custom element content callback first
        if ($settings->elementContentCallback && is_callable($settings->elementContentCallback)) {
            try {
                // Validate and execute callback safely
                $content = $this->getCallbackValidator()->safeExecute(
                    $settings->elementContentCallback,
                    [$element],
                    'elementContentCallback',
                    ''
                );
                if (!empty($content)) {
                    Craft::info("Using custom elementContentCallback for element $element->id", __METHOD__);

                    // If callback returns HTML, extract text from it
                    if (str_contains($content, '<') && str_contains($content, '>')) {
                        return $this->extractTextFromHtml($content, $element);
                    }

                    // Return content as-is if it's plain text
                    return trim($content);
                }
            } catch (\Exception $e) {
                Craft::warning("elementContentCallback failed for element $element->id: " . $e->getMessage(), __METHOD__);
                // Fall through to HTTP fetching
            }
        }

        // Validate URL before fetching to prevent SSRF attacks
        if (!$this->isUrlSafe($url)) {
            $errorMsg = "Unsafe URL detected for element $element->id: $url";
            Craft::warning($errorMsg, __METHOD__);
            if ($debugInfo !== null) {
                $debugInfo['error'] = 'Unsafe URL detected';
            }
            return '';
        }

        // Default HTTP fetching behavior with security settings
        $client = new Client([
            'timeout' => 10,
            'allow_redirects' => [
                'max' => 3,
                'strict' => true,
                'on_redirect' => function($request, $response, $uri) use ($element) {
                    // Validate redirect URLs too
                    if (!$this->isUrlSafe((string)$uri)) {
                        throw new RuntimeException("Unsafe redirect URL detected");
                    }
                }
            ],
            'verify' => true, // Verify SSL certificates
            'http_errors' => false // Don't throw on HTTP errors
        ]);

        try {
            $response = $client->get($url);
            $statusCode = $response->getStatusCode();

            // Capture debug information
            if ($debugInfo !== null) {
                $debugInfo['statusCode'] = $statusCode;
                $debugInfo['headers'] = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $debugInfo['headers'][$name] = implode(', ', $values);
                }
            }

            // Check response status
            if ($statusCode >= 400) {
                $errorMsg = "HTTP error $statusCode for element $element->id URL: $url";
                Craft::warning($errorMsg, __METHOD__);
                if ($debugInfo !== null) {
                    $debugInfo['error'] = "HTTP $statusCode error";
                }
                return '';
            }

            $content = $response->getBody()->getContents();
        } catch (\Exception $e) {
            $errorMsg = "Failed to fetch content for element $element->id: " . $e->getMessage();
            Craft::warning($errorMsg, __METHOD__);
            if ($debugInfo !== null) {
                $debugInfo['error'] = $e->getMessage();
            }
            return '';
        }

        // Get content type from response headers
        $contentType = $response->getHeaderLine('Content-Type');

        // Handle HTML content (most common case)
        if (empty($contentType) || str_contains($contentType, 'text/html')) {
            return $this->extractTextFromHtml($content, $element);
        }

        // Handle plain text files
        if (str_contains($contentType, 'text/plain') || str_contains($contentType, 'text/')) {
            return trim($content);
        }

        // For other content types, attempt HTML parsing as fallback
        return $this->extractTextFromHtml($content, $element);
    }

    /**
     * Extract text content from HTML by removing scripts, styles, and tags
     *
     * @param string $html The HTML content to process
     * @param Element|null $element The element being indexed (for context)
     * @return string The extracted text content
     * @since 4.0.0
     */
    protected function extractTextFromHtml(string $html, Element $element = null): string
    {
        $settings = SearchWithElastic::getInstance()->getSettings();

        // Use custom content extractor callback if configured
        if ($settings->contentExtractorCallback && is_callable($settings->contentExtractorCallback)) {
            try {
                // Validate and execute callback safely
                $extractedContent = $this->getCallbackValidator()->safeExecute(
                    $settings->contentExtractorCallback,
                    [$html],
                    'contentExtractorCallback',
                    ''
                );

                // Fire event for further customization
                $event = new ContentExtractionEvent();
                $event->rawContent = $html;
                $event->extractedContent = $extractedContent;
                $event->element = $element;

                $this->trigger(self::EVENT_CONTENT_EXTRACTION, $event);

                return $event->extractedContent;
            } catch (\Exception $e) {
                Craft::warning("Content extractor callback failed: " . $e->getMessage(), __METHOD__);
                // Fall through to default extraction
            }
        }

        // Fire event before default extraction (allows complete override)
        $event = new ContentExtractionEvent();
        $event->rawContent = $html;
        $event->extractedContent = '';
        $event->element = $element;
        $event->skipDefaultExtraction = false;

        $this->trigger(self::EVENT_CONTENT_EXTRACTION, $event);

        // If event handler provided content or requested to skip default extraction
        if (!empty($event->extractedContent) || $event->skipDefaultExtraction) {
            return $event->extractedContent;
        }

        // Default HTML content extraction
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        // Remove script and style elements
        $xpath = new \DOMXPath($dom);
        $scripts = $xpath->query('//script | //style');
        foreach ($scripts as $script) {
            $script->parentNode->removeChild($script);
        }

        $defaultContent = trim(strip_tags($dom->textContent));

        // Fire event after default extraction (allows post-processing)
        $event->extractedContent = $defaultContent;
        $this->trigger(self::EVENT_CONTENT_EXTRACTION, $event);

        return $event->extractedContent;
    }

    /**
     * Update element record in local database after successful indexing
     *
     * @param Element $element The indexed element
     * @param string $documentId The Elasticsearch document ID
     * @since 4.0.0
     */
    protected function updateElementRecord(Element $element, string $documentId): void
    {
        SearchWithElastic::getInstance()->records->saveElementRecord($element, $documentId);
    }

    /**
     * Add extra fields to document based on plugin settings
     *
     * @param Element $element The element to extract extra fields from
     * @param array $document The document array to add fields to (passed by reference)
     * @since 4.0.0
     */
    protected function addExtraFields(Element $element, array &$document): void
    {
        $extraFields = SearchWithElastic::getInstance()->getSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldValue = ArrayHelper::getValue($fieldParams, 'value');
                if (!empty($fieldValue) && is_callable($fieldValue)) {
                    try {
                        // Validate and execute callback safely
                        $document[$fieldName] = $this->getCallbackValidator()->safeExecute(
                            $fieldValue,
                            [$element],
                            "extraField:$fieldName",
                            null
                        );
                    } catch (\Exception $e) {
                        Craft::warning("Failed to process extra field '$fieldName': " . $e->getMessage(), __METHOD__);
                    }
                } elseif (!empty($fieldValue)) {
                    $document[$fieldName] = $fieldValue;
                }
            }
        }
    }

    /**
     * Remove element record from local database after deletion
     *
     * @param Element $element The element to remove the record for
     * @since 4.0.0
     */
    protected function removeElementRecord(Element $element): void
    {
        SearchWithElastic::getInstance()->records->deleteElementRecord($element);
    }

    /**
     * Validate URL to prevent SSRF attacks
     *
     * @param string $url
     * @return bool
     * @since 4.0.0
     */
    protected function isUrlSafe(string $url): bool
    {
        // Ensure it's a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Only allow HTTP/HTTPS
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'])) {
            return false;
        }

        // Get the hostname
        $host = strtolower($parsed['host']);

        // Block localhost and local addresses
        $blockedHosts = [
            'localhost',
            '127.0.0.1',
            '0.0.0.0',
            '::1',
            '[::1]',
            'metadata.google.internal',
            'metadata.amazon.com',
            '169.254.169.254' // AWS metadata service
        ];

        if (in_array($host, $blockedHosts)) {
            return false;
        }

        // Block private IP ranges only if it's a direct IP address
        if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Don't do DNS resolution check as it can block legitimate domains
        // that resolve to CDNs or load balancers

        // Allow all ports - many development and staging sites use non-standard ports
        // The risk is minimal as we're already blocking local/private IPs

        return true;
    }

    /**
     * Get the callback validator service
     *
     * @return CallbackValidator
     * @since 4.0.0
     */
    protected function getCallbackValidator(): CallbackValidator
    {
        return SearchWithElastic::getInstance()->callbackValidator;
    }

    /**
     * Validate that a callback is safe to execute
     *
     * @param callable $callback
     * @param string $context
     * @return bool
     * @deprecated Use getCallbackValidator()->validateCallback() instead
     * @since 4.0.0
     */
    protected function isCallbackSafe(callable $callback, string $context): bool
    {
        return $this->getCallbackValidator()->validateCallback($callback, $context, false);
    }

    /**
     * Set frontend fetch debug information on the IndexingResult
     *
     * @param IndexingResult $result The result to populate with debug info
     * @param array $debugInfo Debug information from the fetch operation
     * @since 4.0.0
     */
    protected function setFrontendFetchDebugInfo(IndexingResult $result, array $debugInfo): void
    {
        if (empty($debugInfo)) {
            return;
        }

        $result->frontendFetchUrl = $debugInfo['url'] ?? null;
        $result->frontendFetchStatusCode = $debugInfo['statusCode'] ?? null;
        $result->frontendFetchError = $debugInfo['error'] ?? null;
        $result->frontendFetchHeaders = $debugInfo['headers'] ?? [];
    }
}

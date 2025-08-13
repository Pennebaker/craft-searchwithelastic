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

namespace pennebaker\searchwithelastic\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\i18n\Locale;
use craft\web\Controller;
use craft\web\Request;
use DateTime;
use DateTimeZone;
use Exception;
use pennebaker\searchwithelastic\behaviors\RateLimitBehavior;
use pennebaker\searchwithelastic\exceptions\IndexableElementModelException;
use pennebaker\searchwithelastic\helpers\validation\ValidationHelper;
use pennebaker\searchwithelastic\models\IndexableElementModel;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Control Panel controller for Search with Elastic plugin
 *
 * Handles web-based operations for testing connections, reindexing elements,
 * and managing Elasticsearch operations through the Craft CMS control panel.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class CpController extends Controller
{
    /**
     * @var array|bool|int Actions that allow anonymous access
     * @since 4.0.0
     */
    protected array|bool|int $allowAnonymous = [];

    /**
     * @inheritdoc
     * @since 4.0.0
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        // Add rate limiting for indexing actions
        $behaviors['rateLimiter'] = [
            'class' => RateLimitBehavior::class,
            'maxRequests' => 100, // 100 requests
            'window' => 60, // per minute
            'actions' => [
                'index-element',
                'reindex-element',
                'refresh-element-status',
                'reindex-all'
            ],
        ];

        return $behaviors;
    }

    /**
     * @inheritdoc
     * @since 4.0.0
     */
    public function beforeAction($action): bool
    {
        // Require control panel requests for all actions
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            throw new ForbiddenHttpException('Control panel access required');
        }

        return parent::beforeAction($action);
    }


    /**
     * Test the connection to the configured Elasticsearch instance
     *
     * Attempts to connect to Elasticsearch using the plugin's configuration
     * and sets appropriate session messages based on the connection result.
     *
     * @return Response Redirect response to the utilities page
     * @throws ForbiddenHttpException If user lacks utility:refresh-elasticsearch-index permission
     * @since 4.0.0
     */
    public function actionTestConnection(): Response
    {
        $this->requirePermission('utility:refresh-elasticsearch-index');
        $this->requirePostRequest();

        $searchWithElasticPlugin = SearchWithElastic::getInstance();
        assert($searchWithElasticPlugin !== null, "SearchWithElastic::getInstance() should always return the plugin instance when called from the plugin's code.");

        $settings = $searchWithElasticPlugin->getSettings();

        if ($searchWithElasticPlugin->elasticsearch->testConnection() === true) {
            Craft::$app->session->setNotice(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Successfully connected to {elasticsearchEndpoint}',
                    ['elasticsearchEndpoint' => $settings->elasticsearchEndpoint]
                )
            );
        } else {
            Craft::$app->session->setError(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Could not establish connection with {elasticsearchEndpoint}',
                    ['elasticsearchEndpoint' => $settings->elasticsearchEndpoint]
                )
            );
        }

        return $this->redirect(UrlHelper::cpUrl('utilities/elasticsearch-utilities'));
    }

    /**
     * Handle reindexing operations from the utility panel
     *
     * Processes bulk reindexing requests, supporting different modes (reset, all, missing, updated)
     * and element type filtering. Can either return the queue of elements to process or
     * process individual elements based on request parameters.
     *
     * @return Response JSON response with queue data or processing results
     * @throws Exception If reindexing operations fail
     * @throws BadRequestHttpException If request is missing required parameters
     * @throws ForbiddenHttpException If user lacks utility:refresh-elasticsearch-index permission
     * @since 4.0.0
     */
    public function actionReindexPerformAction(): Response
    {
        $this->requirePermission('utility:refresh-elasticsearch-index');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $params = $request->getRequiredBodyParam('params');

        // Validate required parameters
        if (!is_array($params)) {
            throw new BadRequestHttpException('Invalid params format');
        }

        // Handle initial request - return queue of elements to process
        if (!empty($params['start'])) {
            try {
                $siteIds = $this->getSiteIds($request);
                $elementTypes = $this->getElementTypes($request);
                $reindexMode = $request->getParam('params.reindexMode', 'reset');

                // Validate reindex mode
                $validModes = ['reset', 'all', 'missing', 'updated', 'missing-updated'];
                if (!in_array($reindexMode, $validModes, true)) {
                    throw new BadRequestHttpException('Invalid reindex mode specified');
                }

                switch ($reindexMode) {
                    case 'reset':
                        // Full index recreation
                        foreach ($siteIds as $siteId) {
                            SearchWithElastic::getInstance()->indexManagement->recreateSiteIndex($siteId);
                        }
                        break;

                    case 'all':
                    case 'missing':
                    case 'updated':
                    case 'missing-updated':
                        // For non-reset modes, we don't need to do any cleanup here
                        // The filtering will happen in getReindexQueue based on the mode
                        break;

                }
            } catch (BadRequestHttpException $e) {
                return $this->asFailure($e->getMessage());
            }

            return $this->getReindexQueue($siteIds, $elementTypes, $reindexMode);
        }

        // Handle individual element processing request
        $result = $this->reindexElement();

        if ($result !== null) {
            // Get element details for better error reporting
            $request = Craft::$app->getRequest();
            $elementId = $request->getBodyParam('params.elementId', 'Unknown');
            $elementType = $request->getBodyParam('params.type', 'Unknown');

            // Determine result type and format appropriate response
            if (str_starts_with($result['reason'] ?? $result, 'Failed to index element:') || str_starts_with($result['reason'] ?? $result, 'Element not found')) {
                return $this->asJson([
                    'success' => false,
                    'error' => is_array($result) ? $result['reason'] : $result,
                    'elementId' => $elementId,
                    'elementType' => $this->getElementTypeName($elementType),
                ]);
            }

            if (is_array($result) && $result['status'] === 'partial') {
                // This is a partial index (indexed with missing content)
                $response = [
                    'success' => true,
                    'partial' => true,
                    'reason' => $result['reason'],
                    'elementId' => $elementId,
                    'elementType' => $this->getElementTypeName($elementType),
                ];

                // Add debug information if available and enabled
                if (isset($result['frontendFetchDebug'])) {
                    $response['frontendFetchDebug'] = $result['frontendFetchDebug'];
                }

                return $this->asJson($response);
            }

            // This is a skip reason (like "Element has no URL" etc.)
            return $this->asJson([
                'success' => true,
                'skipped' => true,
                'reason' => is_array($result) ? $result['reason'] : $result,
            ]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Reindex a single element from the sidebar interface
     *
     * Handles individual element reindexing requests, typically triggered from
     * the element edit sidebar. Supports both AJAX and regular form submissions.
     *
     * @return Response JSON response for AJAX or redirect for regular requests
     * @throws Exception If reindexing operations fail
     * @throws BadRequestHttpException If required parameters are missing
     * @throws ForbiddenHttpException If user lacks search-with-elastic:index-element permission
     * @since 4.0.0
     */
    public function actionReindexSingleElement(): Response
    {
        $this->requirePermission('search-with-elastic:index-element');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $elementId = $request->getRequiredBodyParam('elementId');
        $siteId = $request->getRequiredBodyParam('siteId');
        $elementType = $request->getRequiredBodyParam('elementType');

        // Validate parameters
        $elementId = $this->validatePositiveInteger($elementId, 'element ID');

        $siteId = $this->validatePositiveInteger($siteId, 'site ID');

        // Validate element type
        $validElementTypes = [
            Entry::class,
            Asset::class,
            Category::class,
        ];

        // Add Commerce element types if available
        if (class_exists(Product::class)) {
            $validElementTypes[] = Product::class;
        }

        // Add Digital Products element types if available
        if (class_exists(DigitalProduct::class)) {
            $validElementTypes[] = DigitalProduct::class;
        }

        if (!is_string($elementType) || !in_array($elementType, $validElementTypes, true)) {
            throw new BadRequestHttpException('Invalid element type');
        }

        $model = new IndexableElementModel();
        $model->elementId = $elementId;
        $model->siteId = $siteId;
        $model->type = $elementType;

        $element = $model->getElement();

        try {
            $result = SearchWithElastic::getInstance()->elementIndexer->indexElement($element);

            // Format response based on request type (AJAX vs regular)
            if ($request->getIsAjax()) {
                if ($result->isSuccess()) {
                    return $this->asJson([
                        'success' => true,
                        'status' => $result->status,
                        'message' => $result->message,
                        'frontendFetch' => [
                            'attempted' => $result->frontendFetchAttempted,
                            'success' => $result->frontendFetchSuccess
                        ]
                    ]);
                }

                if ($result->isPartial()) {
                    $settings = SearchWithElastic::getInstance()->getSettings();
                    $response = [
                        'success' => true,
                        'status' => $result->status,
                        'warning' => $result->message,
                        'reason' => $result->reason,
                        'frontendFetch' => [
                            'attempted' => $result->frontendFetchAttempted,
                            'success' => $result->frontendFetchSuccess
                        ]
                    ];

                    // Add debug information if enabled
                    if ($settings->enableFrontendFetchDebug && $result->frontendFetchAttempted) {
                        $response['frontendFetchDebug'] = [
                            'url' => $result->frontendFetchUrl,
                            'statusCode' => $result->frontendFetchStatusCode,
                            'error' => $result->frontendFetchError,
                            'headers' => $result->frontendFetchHeaders
                        ];
                    }

                    return $this->asJson($response);
                }

                if ($result->isSkipped() || $result->isDisabled()) {
                    return $this->asJson([
                        'success' => true,
                        'status' => $result->status,
                        'skipped' => true,
                        'message' => $result->message,
                        'reason' => $result->reason
                    ]);
                }

                return $this->asJson([
                    'success' => false,
                    'status' => $result->status,
                    'error' => $result->message,
                    'reason' => $result->reason
                ]);
            }

            // Non-AJAX requests
            if ($result->isSuccess()) {
                Craft::$app->session->setNotice($result->message);
            } elseif ($result->isPartial()) {
                Craft::$app->session->setNotice($result->message);
            } elseif ($result->isSkipped() || $result->isDisabled()) {
                Craft::$app->session->setNotice($result->message);
            } else {
                Craft::$app->session->setError($result->message);
            }
        } catch (Exception $e) {
            Craft::error("Error while re-indexing element $element->url: {$e->getMessage()}", __METHOD__);
            $errorMessage = Craft::t('search-with-elastic', 'Failed to re-index element: {error}', ['error' => $e->getMessage()]);

            // Format error response based on request type
            if ($request->getIsAjax()) {
                return $this->asJson([
                    'success' => false,
                    'status' => 'failed',
                    'error' => $errorMessage,
                ]);
            }

            Craft::$app->session->setError($errorMessage);
        }

        // Handle redirect for non-AJAX requests only
        if (!$request->getIsAjax()) {
            return $this->redirectToPostedUrl();
        }

        // Default success response for AJAX requests
        return $this->asJson(['success' => true]);
    }

    /**
     * Delete a single element from the Elasticsearch index
     *
     * Handles individual element deletion requests from the sidebar interface.
     * Removes the element from all applicable indexes and updates the local tracking record.
     *
     * @return Response JSON response for AJAX requests
     * @throws Exception If deletion operations fail
     * @throws BadRequestHttpException If required parameters are missing
     * @throws ForbiddenHttpException If user lacks search-with-elastic:index-element permission
     * @since 4.0.0
     */
    public function actionDeleteSingleElement(): Response
    {
        $this->requirePermission('search-with-elastic:index-element');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $elementId = $request->getRequiredBodyParam('elementId');
        $siteId = $request->getRequiredBodyParam('siteId');
        $elementType = $request->getRequiredBodyParam('elementType');

        // Validate parameters
        $elementId = $this->validatePositiveInteger($elementId, 'element ID');

        $siteId = $this->validatePositiveInteger($siteId, 'site ID');

        // Validate element type
        $validElementTypes = [
            Entry::class,
            Asset::class,
            Category::class,
        ];

        // Add Commerce element types if available
        if (class_exists(Product::class)) {
            $validElementTypes[] = Product::class;
        }

        // Add Digital Products element types if available
        if (class_exists(DigitalProduct::class)) {
            $validElementTypes[] = DigitalProduct::class;
        }

        if (!is_string($elementType) || !in_array($elementType, $validElementTypes, true)) {
            throw new BadRequestHttpException('Invalid element type');
        }

        $model = new IndexableElementModel();
        $model->elementId = $elementId;
        $model->siteId = $siteId;
        $model->type = $elementType;

        $element = $model->getElement();

        try {
            $deletedCount = SearchWithElastic::getInstance()->elementIndexer->deleteElement($element);

            if ($request->getIsAjax()) {
                return $this->asJson([
                    'success' => true,
                    'message' => Craft::t('search-with-elastic', 'Element removed from {count} index(es).', ['count' => $deletedCount]),
                    'deletedCount' => $deletedCount
                ]);
            }

            Craft::$app->session->setNotice(Craft::t('search-with-elastic', 'Element removed from {count} index(es).', ['count' => $deletedCount]));
        } catch (Exception $e) {
            Craft::error("Error while deleting element $element->id from index: {$e->getMessage()}", __METHOD__);
            $errorMessage = Craft::t('search-with-elastic', 'Failed to delete element from index: {error}', ['error' => $e->getMessage()]);

            if ($request->getIsAjax()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $errorMessage,
                ]);
            }

            Craft::$app->session->setError($errorMessage);
        }

        // Handle redirect for non-AJAX requests
        if (!$request->getIsAjax()) {
            return $this->redirectToPostedUrl();
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Get the current status of an element's index
     *
     * Returns the current indexing status and metadata for an element,
     * used for live updates in the sidebar interface.
     *
     * @return Response JSON response with element status data
     * @throws BadRequestHttpException If required parameters are missing
     * @throws ForbiddenHttpException If user lacks search-with-elastic:index-element permission
     * @throws IndexableElementModelException
     * @throws InvalidConfigException
     * @throws Exception
     * @since 4.0.0
     */
    public function actionGetElementStatus(): Response
    {
        $this->requirePermission('search-with-elastic:index-element');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $elementId = $request->getRequiredBodyParam('elementId');
        $siteId = $request->getRequiredBodyParam('siteId');
        $elementType = $request->getRequiredBodyParam('elementType');

        // Validate parameters
        $elementId = $this->validatePositiveInteger($elementId, 'element ID');

        $siteId = $this->validatePositiveInteger($siteId, 'site ID');

        // Validate element type
        $validElementTypes = [
            Entry::class,
            Asset::class,
            Category::class,
        ];

        // Add Commerce element types if available
        if (class_exists(Product::class)) {
            $validElementTypes[] = Product::class;
        }

        // Add Digital Products element types if available
        if (class_exists(DigitalProduct::class)) {
            $validElementTypes[] = DigitalProduct::class;
        }

        if (!is_string($elementType) || !in_array($elementType, $validElementTypes, true)) {
            throw new BadRequestHttpException('Invalid element type');
        }

        $model = new IndexableElementModel();
        $model->elementId = $elementId;
        $model->siteId = $siteId;
        $model->type = $elementType;

        $element = $model->getElement();

        $plugin = SearchWithElastic::getInstance();

        if (!$plugin) {
            return $this->asFailure(Craft::t('search-with-elastic', 'Plugin instance not found.'));
        }

        // Check if element type is disabled
        $settings = $plugin->getSettings();
        $disabledType = false;

        switch (get_class($element)) {
            case Entry::class:
                $disabledType = in_array($element->type->handle, $settings->excludedEntryTypes, true);
                break;
            case Asset::class:
                $disabledType = in_array($element->volume->handle, $settings->excludedAssetVolumes, true) || !in_array($element->kind, $settings->assetKinds, true);
                break;
            case Category::class:
                $disabledType = in_array($element->group->handle, $settings->excludedCategoryGroups, true);
                break;
        }

        // Check Commerce products
        if (class_exists(Product::class) && $element instanceof Product) {
            $disabledType = in_array($element->type->handle, $settings->excludedProductTypes, true);
        }

        // Check Digital Products
        if (class_exists(DigitalProduct::class) && $element instanceof Product) {
            $disabledType = in_array($element->type->handle, $settings->excludedDigitalProductTypes, true);
        }

        // Get index status and record
        $esRecord = $plugin->elasticsearch->getElementIndex($element);
        $status = $plugin->elasticsearch->getElementIndexStatus($element);

        $dateUpdated = null;
        $revisionNum = null;

        if ($esRecord) {
            $dateString = $esRecord->attributes['updateDate'] ?? null;
            if ($dateString) {
                // Try parsing the date
                $dateUpdated = DateTime::createFromFormat('c', $dateString);
                if (!$dateUpdated) {
                    $dateUpdated = DateTime::createFromFormat('Y-m-d H:i:s', $dateString, new DateTimeZone('UTC'));
                }
                if (!$dateUpdated) {
                    try {
                        $dateUpdated = new DateTime($dateString);
                    } catch (Exception) {
                        $dateUpdated = null;
                    }
                }

                if ($dateUpdated instanceof DateTime) {
                    $dateUpdated->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
                    $revisionNum = $plugin->elasticsearch->getElementIndexRevision($element, $esRecord->attributes['updateDate']);
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'status' => $status,
            'disabledType' => $disabledType,
            'dateUpdated' => $dateUpdated ? $dateUpdated->format(Craft::$app->getLocale()->getDateTimeFormat('short', Locale::FORMAT_PHP)) : null,
            'revisionNum' => $revisionNum,
            'attributes' => $esRecord ? $esRecord->attributes : []
        ]);
    }

    /**
     * Generate the queue of elements to be reindexed
     *
     * Retrieves indexable elements based on the specified criteria and formats
     * them for consumption by the JavaScript reindexing utility.
     *
     * @param int[] $siteIds Site IDs to include in reindexing
     * @param array $elementTypes Element types to filter by
     * @param string $reindexMode Reindex mode (all, missing, updated, missing-updated, reset)
     * @return Response JSON response containing the element queue
     * @since 4.0.0
     */
    protected function getReindexQueue(array $siteIds, array $elementTypes = [], string $reindexMode = 'reset'): Response
    {
        /** @var SearchWithElastic $plugin */
        $plugin = SearchWithElastic::getInstance();

        $indexableElementModels = $plugin->elasticsearch->getIndexableElementModels($siteIds, $elementTypes, $reindexMode);

        // Format elements for JavaScript compatibility with Craft's SearchIndexUtility
        array_walk(
            $indexableElementModels,
            static function (&$element) {
                $element = ['params' => $element->toArray()];
            }
        );

        return $this->asJson(
            [
                'entries' => [$indexableElementModels],
            ]
        );
    }

    /**
     * Extract and normalize site IDs from request parameters
     *
     * Handles the special case where Garnish's CheckboxSelect component sends "*"
     * when all sites are selected, converting it to an array of all site IDs.
     *
     * @param Request $request The web request object
     * @return int[] Array of site IDs to process
     * @throws BadRequestHttpException
     * @since 4.0.0
     */
    protected function getSiteIds(Request $request): array
    {
        $elementTypes = $this->getElementTypes($request);

        // If no element types specified, process all sites
        if (empty($elementTypes)) {
            return Craft::$app->getSites()->getAllSiteIds();
        }

        // Get sites that have element types selected
        return array_keys($elementTypes);
    }

    /**
     * Extract element types from request parameters
     *
     * Simple extraction of element types configuration from the request.
     * Returns array keyed by site ID with arrays of element type strings.
     *
     * @param Request $request The web request object
     * @return array<int, string[]> Element types configuration: [siteId => [elementType, ...], ...]
     * @throws BadRequestHttpException If the element types parameter is invalid
     * @since 4.0.0
     */
    protected function getElementTypes(Request $request): array
    {
        $elementTypes = $request->getParam('params.elementTypes', []);

        // Validate that elementTypes is an array
        if (!is_array($elementTypes)) {
            throw new BadRequestHttpException('Element types must be an array');
        }

        // If no element types parameter at all, return empty (legacy support)
        if (empty($elementTypes)) {
            return [];
        }

        // Valid element type names (matching what the UI sends)
        $validElementTypes = [
            'entries',
            'assets',
            'categories',
        ];

        // Add Commerce element types if available
        if (class_exists(Product::class)) {
            $validElementTypes[] = 'products';
        }

        // Add Digital Products element types if available
        if (class_exists(DigitalProduct::class)) {
            $validElementTypes[] = 'digitalProducts';
        }

        // Clean up the array - remove empty values and ensure proper structure
        $cleanedTypes = [];
        foreach ($elementTypes as $siteId => $types) {
            // Validate site ID
            try {
                $siteId = $this->validatePositiveInteger($siteId, 'site ID');
            } catch (BadRequestHttpException) {
                continue; // Skip invalid site IDs
            }

            if (is_array($types)) {
                // Filter out empty values and validate element types
                $validTypes = array_filter($types, static function($type) use ($validElementTypes) {
                    return !empty($type) && is_string($type) && in_array($type, $validElementTypes, true);
                });

                if (!empty($validTypes)) {
                    $cleanedTypes[$siteId] = array_values($validTypes);
                }
            }
        }

        return $cleanedTypes;
    }

    /**
     * Process a single element reindexing request
     *
     * Extracts element information from the request and attempts to index it,
     * returning appropriate status information based on the result.
     *
     * @return array|string|null Structured result array, error message, or null on success
     * @throws Exception If reindexing operations fail
     * @throws BadRequestHttpException If request is missing required parameters
     * @since 4.0.0
     */
    protected function reindexElement(): array|string|null
    {
        $request = Craft::$app->getRequest();

        // Validate required parameters
        $elementId = $request->getRequiredBodyParam('params.elementId');
        $siteId = $request->getRequiredBodyParam('params.siteId');
        $elementType = $request->getRequiredBodyParam('params.type');

        // Validate element ID
        $elementId = $this->validatePositiveInteger($elementId, 'element ID');

        // Validate site ID
        $siteId = $this->validatePositiveInteger($siteId, 'site ID');

        // Validate element type
        $validElementTypes = [
            Entry::class,
            Asset::class,
            Category::class,
        ];

        // Add Commerce element types if available
        if (class_exists(Product::class)) {
            $validElementTypes[] = Product::class;
        }

        // Add Digital Products element types if available
        if (class_exists(DigitalProduct::class)) {
            $validElementTypes[] = DigitalProduct::class;
        }

        if (!is_string($elementType) || !in_array($elementType, $validElementTypes, true)) {
            throw new BadRequestHttpException('Invalid element type');
        }

        $model = new IndexableElementModel();
        $model->elementId = $elementId;
        $model->siteId = $siteId;
        $model->type = $elementType;
        $element = $model->getElement();

        try {
            $SearchWithElastic = SearchWithElastic::getInstance();
            if (!$SearchWithElastic) {
                return 'Failed to index element: SearchWithElastic instance not found.';
            }
            $result = $SearchWithElastic->elementIndexer->indexElement($element);

            // Process indexing result and format appropriate response
            if ($result->isSuccess()) {
                return null; // Complete success
            }

            if ($result->isPartial()) {
                $settings = $SearchWithElastic->getSettings();
                $response = [
                    'status' => 'partial',
                    'reason' => $result->reason,
                    'message' => $result->message
                ];

                // Add debug information if enabled
                if ($settings->enableFrontendFetchDebug && $result->frontendFetchAttempted) {
                    $response['frontendFetchDebug'] = [
                        'url' => $result->frontendFetchUrl,
                        'statusCode' => $result->frontendFetchStatusCode,
                        'error' => $result->frontendFetchError,
                        'headers' => $result->frontendFetchHeaders
                    ];
                }

                return $response;
            }

            if ($result->isSkipped() || $result->isDisabled()) {
                return $result->reason; // Element was skipped
            }

            return 'Failed to index element: ' . $result->reason; // Indexing failed
        } catch (Exception $e) {
            // Log detailed error for debugging while providing user-friendly message
            Craft::error("Error while re-indexing element $element->id: {$e->getMessage()}", __METHOD__);

            // Provide safe error message without sensitive details
            return 'Failed to index element: ' . $e->getMessage();
        }
    }

    /**
     * Convert element type class names to user-friendly display names
     *
     * Maps fully-qualified class names to readable element type names
     * for display in error messages and user interfaces.
     *
     * @param string $elementType Fully-qualified element class name
     * @return string User-friendly element type name
     * @since 4.0.0
     */
    protected function getElementTypeName(string $elementType): string
    {
        $typeMap = [
            Entry::class => 'Entry',
            Asset::class => 'Asset',
            Category::class => 'Category',
            'craft\\commerce\\elements\\Product' => 'Product',
            'craft\\digitalproducts\\elements\\Product' => 'Digital Product',
        ];

        return $typeMap[$elementType] ?? 'Element';
    }

    /**
     * Remove documents from Elasticsearch for specific element types
     *
     * Performs targeted cleanup by removing all documents of specified element types
     * for a given site before reindexing. This ensures clean reindexing for selected types.
     *
     * @param int $siteId Site ID to clean up
     * @param array $elementTypes Element types to remove, keyed by site ID
     * @return void
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    protected function cleanupSelectedElementTypes(int $siteId, array $elementTypes): void
    {
        if (empty($elementTypes) || empty($elementTypes[$siteId])) {
            // No specific types selected for this site - skip cleanup
            return;
        }

        $connection = SearchWithElastic::getConnection();
        $selectedTypes = $elementTypes[$siteId];

        // Map UI element type identifiers to their corresponding class names
        $typeClassMap = [
            'entries' => Entry::class,
            'assets' => Asset::class,
            'categories' => Category::class,
            'products' => 'craft\\commerce\\elements\\Product',
            'digitalProducts' => 'craft\\digitalproducts\\elements\\Product',
        ];

        foreach ($selectedTypes as $elementType) {
            if (!isset($typeClassMap[$elementType])) {
                continue;
            }

            $elementClass = $typeClassMap[$elementType];
            $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($siteId, $elementClass);

            try {
                // Remove all documents matching the element type and site criteria
                $deleteParams = [
                    'index' => $indexName,
                    'body' => [
                        'query' => [
                            'bool' => [
                                'must' => [
                                    ['term' => ['siteId' => $siteId]],
                                    ['term' => ['elementType' => $elementClass]]
                                ]
                            ]
                        ]
                    ]
                ];

                $connection->createCommand()->deleteByQuery($deleteParams);
            } catch (Exception $e) {
                Craft::warning("Failed to cleanup element type $elementType for site $siteId: " . $e->getMessage(), __METHOD__);
            }
        }
    }

    /**
     * Validate that a value is a positive integer
     *
     * This method now delegates to the centralized ValidationHelper for enhanced security.
     * Maintained for backward compatibility and consistency within the controller.
     *
     * @param mixed $value The value to validate
     * @param string $fieldName The field name for error messages
     * @return int The validated integer
     * @throws BadRequestHttpException if validation fails
     * @since 4.0.0
     */
    private function validatePositiveInteger(mixed $value, string $fieldName): int
    {
        return ValidationHelper::validatePositiveInteger($value, $fieldName);
    }
}

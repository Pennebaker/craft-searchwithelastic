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
use craft\errors\SiteNotFoundException;
use craft\web\Controller;
use pennebaker\searchwithelastic\behaviors\RateLimitBehavior;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Search Controller
 *
 * Handles public search endpoints with rate limiting support.
 * These endpoints are designed for AJAX/API usage from frontend templates.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class SearchController extends Controller
{
    /**
     * @var array|bool|int Allow anonymous access to search endpoints
     * @since 4.0.0
     */
    protected array|bool|int $allowAnonymous = ['search', 'search-extra'];

    /**
     * @inheritdoc
     * @since 4.0.0
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        
        $settings = SearchWithElastic::getInstance()->getSettings();
        
        // Add rate limiting behavior if enabled
        if ($settings->rateLimitingEnabled) {
            $behaviors['rateLimiter'] = [
                'class' => RateLimitBehavior::class,
                'usePluginSettings' => true,
                'actions' => ['search', 'search-extra'], // Apply to all search actions
            ];
        }
        
        return $behaviors;
    }

    /**
     * Perform a basic search query
     *
     * Accepts GET or POST requests with a 'query' parameter.
     * Optional 'siteId' parameter to search within a specific site.
     *
     * @return Response JSON response with search results
     * @throws BadRequestHttpException if query parameter is missing
     * @throws SiteNotFoundException if specified site doesn't exist
     * @since 4.0.0
     */
    public function actionSearch(): Response
    {
        $request = Craft::$app->getRequest();
        
        // Get query parameter
        $query = $request->getParam('query');
        if (empty($query)) {
            throw new BadRequestHttpException('Query parameter is required');
        }
        
        // Get optional site ID
        $siteId = $request->getParam('siteId');
        
        try {
            // Perform search using the Elasticsearch service
            $results = SearchWithElastic::getInstance()->elasticsearch->search($query, $siteId);
            
            // Return JSON response
            return $this->asJson([
                'success' => true,
                'results' => $results,
                'meta' => [
                    'query' => $query,
                    'siteId' => $siteId,
                    'timestamp' => time(),
                ],
            ]);
        } catch (\Exception $e) {
            Craft::error('Search error: ' . $e->getMessage(), __METHOD__);
            
            return $this->asJson([
                'success' => false,
                'error' => 'Search failed. Please try again later.',
                'meta' => [
                    'query' => $query,
                    'siteId' => $siteId,
                    'timestamp' => time(),
                ],
            ]);
        }
    }

    /**
     * Perform an advanced search with additional options
     *
     * Accepts GET or POST requests with:
     * - 'query' (required): The search query string
     * - 'fuzzy' (optional): Enable fuzzy matching (true/false)
     * - 'fields' (optional): Array of fields to search in
     * - 'siteId' (optional): Site ID to search within
     * - 'size' (optional): Number of results to return
     * - 'from' (optional): Offset for pagination
     *
     * @return Response JSON response with search results
     * @throws BadRequestHttpException if query parameter is missing
     * @throws SiteNotFoundException if specified site doesn't exist
     * @since 4.0.0
     */
    public function actionSearchExtra(): Response
    {
        $request = Craft::$app->getRequest();
        
        // Get query parameter
        $query = $request->getParam('query');
        if (empty($query)) {
            throw new BadRequestHttpException('Query parameter is required');
        }
        
        // Build options array
        $options = [];
        
        // Get optional parameters
        if ($request->getParam('fuzzy') !== null) {
            $options['fuzzy'] = filter_var($request->getParam('fuzzy'), FILTER_VALIDATE_BOOLEAN);
        }
        
        if ($request->getParam('fields') !== null) {
            $fields = $request->getParam('fields');
            if (is_string($fields)) {
                $fields = explode(',', $fields);
            }
            $options['fields'] = array_map('trim', $fields);
        }
        
        if ($request->getParam('siteId') !== null) {
            $options['siteId'] = (int) $request->getParam('siteId');
        }
        
        if ($request->getParam('size') !== null) {
            $options['size'] = min(100, max(1, (int) $request->getParam('size'))); // Limit between 1-100
        }
        
        if ($request->getParam('from') !== null) {
            $options['from'] = max(0, (int) $request->getParam('from'));
        }
        
        try {
            // Perform advanced search using the Elasticsearch service
            $results = SearchWithElastic::getInstance()->elasticsearch->advancedSearch($query, $options);
            
            // Add rate limit info to response if enabled
            $meta = [
                'query' => $query,
                'options' => $options,
                'timestamp' => time(),
            ];
            
            $settings = SearchWithElastic::getInstance()->getSettings();
            if ($settings->rateLimitingEnabled) {
                $rateLimiter = SearchWithElastic::getInstance()->rateLimiter;
                $meta['rateLimit'] = [
                    'remaining' => $rateLimiter->getRemainingTokens(),
                    'limit' => $settings->rateLimitRequestsPerMinute,
                    'reset' => time() + 60,
                ];
            }
            
            // Return JSON response
            return $this->asJson([
                'success' => true,
                'results' => $results,
                'meta' => $meta,
            ]);
        } catch (\Exception $e) {
            Craft::error('Advanced search error: ' . $e->getMessage(), __METHOD__);
            
            return $this->asJson([
                'success' => false,
                'error' => 'Search failed. Please try again later.',
                'meta' => [
                    'query' => $query,
                    'options' => $options,
                    'timestamp' => time(),
                ],
            ]);
        }
    }
}
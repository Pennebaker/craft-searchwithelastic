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

namespace pennebaker\searchwithelastic\variables;

use Craft;
use craft\errors\SiteNotFoundException;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\web\TooManyRequestsHttpException;

/**
 * Twig template variable for Elasticsearch search functionality
 *
 * Provides template methods for performing searches from Twig templates.
 * Available in templates as {{ craft.searchWithElastic }}.
 * 
 * Rate limiting is automatically applied when enabled in plugin settings.
 * 
 * @since 4.0.0
 */
class SearchVariable
{
    /**
     * Perform a basic search query against the Elasticsearch index
     *
     * @param string $query The search query string
     * @param null $siteId Optional site ID to search within (defaults to current site)
     * @return array Search results with formatted elements and highlighting
     * @throws SiteNotFoundException
     * @throws TooManyRequestsHttpException if rate limit exceeded
     * @since 4.0.0
     */
    public function search(string $query, $siteId = null): array
    {
        // Apply rate limiting if enabled
        $this->checkRateLimit();
        
        return SearchWithElastic::getInstance()->elasticsearch->search($query, $siteId);
    }

    /**
     * Perform an advanced search with additional options like fuzzy matching and field selection
     *
     * @param string|null $query The search query string (null for aggregation-only queries)
     * @param array $options Search options (fuzzy, fields, siteId, size)
     * @return array Search results with formatted elements and highlighting
     * @throws SiteNotFoundException
     * @throws TooManyRequestsHttpException if rate limit exceeded
     * @since 4.0.0
     */
    public function searchExtra(?string $query = null, array $options = []): array
    {
        // Apply rate limiting if enabled
        $this->checkRateLimit();
        
        return SearchWithElastic::getInstance()->elasticsearch->advancedSearch($query ?? '', $options);
    }

    /**
     * Get index statistics for debugging
     *
     * @param null $indexOrSiteId Index name (string) or site ID (integer). If null, uses current site ID.
     * @return array Index statistics including document count and existence
     * @throws SiteNotFoundException
     * @since 4.0.0
     */
    public function getIndexStats($indexOrSiteId = null): array
    {
        // If no parameter provided, use current site ID explicitly (no magic values)
        if ($indexOrSiteId === null) {
            $indexOrSiteId = \Craft::$app->getSites()->getCurrentSite()->id;
        }

        return SearchWithElastic::getInstance()->elasticsearch->getIndexStats($indexOrSiteId);
    }

    /**
     * Get statistics for all Craft-related indexes
     *
     * @return array Array of index statistics for all Craft indexes
     * @since 4.0.0
     */
    public function getAllIndexStats(): array
    {
        return SearchWithElastic::getInstance()->elasticsearch->getAllIndexStats();
    }

    /**
     * Get a sample document for debugging
     *
     * @param int|null $siteId Optional site ID (defaults to current site)
     * @return array Sample document showing available fields
     * @since 4.0.0
     */
    public function getSampleDocument(int $siteId = null): array
    {
        return SearchWithElastic::getInstance()->elasticsearch->getSampleDocument($siteId);
    }

    /**
     * Get current rate limit status
     *
     * Returns information about the current rate limit status including
     * remaining requests, limit, and reset time.
     *
     * @return array Rate limit information
     * @since 4.0.0
     */
    public function getRateLimitStatus(): array
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        
        if (!$settings->rateLimitingEnabled) {
            return [
                'enabled' => false,
                'limit' => null,
                'remaining' => null,
                'reset' => null,
            ];
        }
        
        $rateLimiter = SearchWithElastic::getInstance()->rateLimiter;
        
        return [
            'enabled' => true,
            'limit' => $settings->rateLimitRequestsPerMinute,
            'remaining' => $rateLimiter->getRemainingTokens(),
            'reset' => time() + 60,
            'burstSize' => $settings->rateLimitBurstSize,
            'trackingMethod' => $settings->rateLimitTrackingMethod,
        ];
    }

    /**
     * Check if the current request should be rate limited
     *
     * @throws TooManyRequestsHttpException if rate limit exceeded
     * @since 4.0.0
     */
    private function checkRateLimit(): void
    {
        $settings = SearchWithElastic::getInstance()->getSettings();
        
        if ($settings->rateLimitingEnabled) {
            $rateLimiter = SearchWithElastic::getInstance()->rateLimiter;
            
            try {
                $rateLimiter->consumeTokens();
            } catch (TooManyRequestsHttpException $e) {
                // Log the rate limit violation
                Craft::warning(
                    sprintf('Rate limit exceeded in template variable for IP: %s', Craft::$app->getRequest()->getUserIP()),
                    __METHOD__
                );
                
                // Re-throw with a more user-friendly message for template usage
                throw new TooManyRequestsHttpException(
                    $e->retryAfter,
                    Craft::t('search-with-elastic', 'Too many search requests. Please wait {seconds} seconds before searching again.', [
                        'seconds' => $e->retryAfter
                    ])
                );
            }
        }
    }
}

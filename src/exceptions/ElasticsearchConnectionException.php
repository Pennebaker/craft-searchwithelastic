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

namespace pennebaker\searchwithelastic\exceptions;

/**
 * Exception thrown when Elasticsearch connection fails
 *
 * @since 4.0.0
 */
class ElasticsearchConnectionException extends SearchWithElasticException
{
    /**
     * @inheritdoc
     * @since 4.0.0
     */
    public function getUserMessage(): string
    {
        return 'Unable to connect to the search service. Please try again later.';
    }
}
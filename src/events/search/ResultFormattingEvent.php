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

namespace pennebaker\searchwithelastic\events\search;

use pennebaker\searchwithelastic\records\ElasticsearchRecord;
use yii\base\Event;

/**
 * ResultFormattingEvent is triggered when formatting search results
 *
 * @since 4.0.0
 */
class ResultFormattingEvent extends Event
{
    /**
     * @var array The formatted result array (can be modified by event handlers)
     * @since 4.0.0
     */
    public array $formattedResult;

    /**
     * @var ElasticsearchRecord The raw Elasticsearch result
     * @since 4.0.0
     */
    public ElasticsearchRecord $rawResult;

    /**
     * @var string The original search query
     * @since 4.0.0
     */
    public string $searchQuery;

    /**
     * @var int|null The site ID where the search was performed
     * @since 4.0.0
     */
    public ?int $siteId = null;
}

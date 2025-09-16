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

namespace pennebaker\searchwithelastic\events\search;

use yii\base\Event;

/**
 * SearchEvent is triggered when a search query is executed
 *
 * This event allows plugins to modify search queries before they are
 * executed against Elasticsearch, or to perform additional processing
 * during search operations.
 *
 * @since 4.0.0
 */
class SearchEvent extends Event
{
    /**
     * @var string|array The search query (can be modified by event handlers)
     * @since 4.0.0
     */
    public string|array $query;

    /**
     * @var array Additional search parameters
     * @since 4.0.0
     */
    public array $params = [];

    /**
     * @var int|null The site ID where the search is being performed
     * @since 4.0.0
     */
    public ?int $siteId = null;

    /**
     * @var bool Whether the default search execution should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultSearch = false;
}

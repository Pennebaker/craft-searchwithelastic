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
 * QueryEvent is triggered during indexable element query building
 *
 * This event allows plugins to modify query builders, add custom filters,
 * or perform additional operations during query construction.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class QueryEvent extends Event
{
    /**
     * @var int The site ID for the query
     * @since 4.0.0
     */
    public int $siteId;

    /**
     * @var string The element type class name being queried
     * @since 4.0.0
     */
    public string $elementType;

    /**
     * @var mixed The query builder instance (can be modified by event handlers)
     * @since 4.0.0
     */
    public mixed $query;

    /**
     * @var array Additional query parameters
     * @since 4.0.0
     */
    public array $params = [];

    /**
     * @var bool Whether the default query building should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultBuild = false;
}

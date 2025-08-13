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

namespace pennebaker\searchwithelastic\events\indexing;

use yii\base\Event;

/**
 * IndexManagementEvent is triggered during index management operations
 *
 * This event allows plugins to modify index configurations, perform additional
 * operations during index creation/deletion, or customize the index management process.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexManagementEvent extends Event
{
    /**
     * @var int The site ID for the index operation
     * @since 4.0.0
     */
    public int $siteId;

    /**
     * @var string The index name being created/deleted/recreated
     * @since 4.0.0
     */
    public string $indexName;

    /**
     * @var array The index configuration (for create/recreate operations)
     * @since 4.0.0
     */
    public array $indexConfig = [];

    /**
     * @var string The operation type: 'create', 'delete', or 'recreate'
     * @since 4.0.0
     */
    public string $operation;

    /**
     * @var bool Whether the default operation should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultOperation = false;

    /**
     * @var bool Whether the index existed before the operation
     * @since 4.0.0
     */
    public bool $indexExisted = false;
}

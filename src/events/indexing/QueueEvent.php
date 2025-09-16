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

namespace pennebaker\searchwithelastic\events\indexing;

use yii\base\Event;

/**
 * QueueEvent is triggered during reindex queue management operations
 *
 * This event allows plugins to modify queue operations, add custom tracking,
 * or perform additional operations during job enqueueing and management.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class QueueEvent extends Event
{
    /**
     * @var array The indexable element models being processed
     * @since 4.0.0
     */
    public array $elementModels = [];

    /**
     * @var array Job IDs that have been enqueued
     * @since 4.0.0
     */
    public array $jobIds = [];

    /**
     * @var string The operation type: 'enqueue' or 'clear'
     * @since 4.0.0
     */
    public string $operation;

    /**
     * @var bool Whether the default operation should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultOperation = false;

    /**
     * @var int|null A specific job ID (for single job operations)
     * @since 4.0.0
     */
    public ?int $jobId = null;

    /**
     * @var int|null Element ID for single job operations
     * @since 4.0.0
     */
    public ?int $elementId = null;

    /**
     * @var int|null Site ID for single job operations
     * @since 4.0.0
     */
    public ?int $siteId = null;

    /**
     * @var string|null Element type for single job operations
     * @since 4.0.0
     */
    public ?string $elementType = null;
}

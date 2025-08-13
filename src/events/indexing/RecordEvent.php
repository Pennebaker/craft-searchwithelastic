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

use craft\base\Element;
use yii\base\Event;

/**
 * RecordEvent is triggered during record management operations
 *
 * This event allows plugins to perform additional operations during record
 * saving/deletion, add custom tracking, or modify record behavior.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class RecordEvent extends Event
{
    /**
     * @var Element The element associated with the record operation
     * @since 4.0.0
     */
    public Element $element;

    /**
     * @var string|null The document ID (for save operations)
     * @since 4.0.0
     */
    public ?string $documentId = null;

    /**
     * @var string The operation type: 'save' or 'delete'
     * @since 4.0.0
     */
    public string $operation;

    /**
     * @var bool Whether the default operation should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultOperation = false;

    /**
     * @var mixed The operation result (can be modified by event handlers)
     * @since 4.0.0
     */
    public mixed $result = null;
}

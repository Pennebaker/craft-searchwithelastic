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
 * ElementContentEvent is triggered when getting HTML content for an element to index
 *
 * @since 4.0.0
 */
class ElementContentEvent extends Event
{
    /**
     * @var Element The element being indexed
     * @since 4.0.0
     */
    public Element $element;

    /**
     * @var string|null The HTML content for the element (can be set by event handlers)
     * @since 4.0.0
     */
    public ?string $content = null;

    /**
     * @var bool Whether the default content fetching should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultFetching = false;

    /**
     * @var array Additional context data for content fetching
     * @since 4.0.0
     */
    public array $context = [];
}

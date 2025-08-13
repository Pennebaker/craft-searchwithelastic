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
 * ContentExtractionEvent is triggered when extracting indexable content from HTML
 *
 * @since 4.0.0
 */
class ContentExtractionEvent extends Event
{
    /**
     * @var string The raw HTML content to extract from
     * @since 4.0.0
     */
    public string $rawContent;

    /**
     * @var string The extracted content (can be modified by event handlers)
     * @since 4.0.0
     */
    public string $extractedContent;

    /**
     * @var Element|null The element being indexed (optional context)
     * @since 4.0.0
     */
    public ?Element $element = null;

    /**
     * @var bool Whether the default extraction should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultExtraction = false;
}

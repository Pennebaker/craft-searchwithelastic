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

use craft\base\Element;
use yii\base\Event;

/**
 * IndexElementEvent is triggered when indexing or removing elements from Elasticsearch
 *
 * This event allows plugins to modify indexing behavior, add custom data,
 * or perform additional operations during element indexing.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexElementEvent extends Event
{
    /**
     * @var Element The element being indexed or removed
     * @since 4.0.0
     */
    public Element $element;

    /**
     * @var array The document data that will be indexed (can be modified by event handlers)
     * @since 4.0.0
     */
    public array $documentData = [];

    /**
     * @var string The Elasticsearch index name
     * @since 4.0.0
     */
    public string $indexName;

    /**
     * @var bool Whether the element is being newly created (vs updated)
     * @since 4.0.0
     */
    public bool $isNew = false;

    /**
     * @var bool Whether the default indexing operation should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultOperation = false;
}

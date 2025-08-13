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
use pennebaker\searchwithelastic\models\IndexableElementModel;
use yii\base\Event;

/**
 * ModelEvent is triggered during indexable element model operations
 *
 * This event allows plugins to modify model creation, add custom properties,
 * or perform additional operations during model initialization.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ModelEvent extends Event
{
    /**
     * @var Element The Craft element being modeled
     * @since 4.0.0
     */
    public Element $element;

    /**
     * @var int The site ID for the model
     * @since 4.0.0
     */
    public int $siteId;

    /**
     * @var IndexableElementModel The indexable element model (can be modified by event handlers)
     * @since 4.0.0
     */
    public IndexableElementModel $model;

    /**
     * @var bool Whether the default model creation should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultCreation = false;
}

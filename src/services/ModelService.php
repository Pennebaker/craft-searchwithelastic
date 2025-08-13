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

namespace pennebaker\searchwithelastic\services;

use craft\base\Component;
use craft\base\Element;
use pennebaker\searchwithelastic\models\IndexableElementModel;

/**
 * The Model service provides APIs for managing indexable element models.
 *
 * An instance of the service is available via [[\pennebaker\searchwithelastic\SearchWithElastic::getInstance()|`SearchWithElastic::getInstance()->models`]].
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ModelService extends Component
{
    // Event constants following Craft patterns
    public const EVENT_BEFORE_CREATE_MODEL = 'beforeCreateModel';
    public const EVENT_AFTER_CREATE_MODEL = 'afterCreateModel';

    /**
     * Creates an IndexableElementModel instance from a Craft element.
     *
     * @param Element $element The Craft element to create a model for
     * @param int $siteId The site ID for the element
     * @return IndexableElementModel The created indexable element model
     */
    public function createIndexableElementModel(Element $element, int $siteId): IndexableElementModel
    {
        return (new IndexableElementModelFactory())->create($element, $siteId);
    }
}

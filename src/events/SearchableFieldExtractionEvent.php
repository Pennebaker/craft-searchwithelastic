<?php
/**
 * Search w/Elastic plugin for Craft CMS 4.x
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * SearchableFieldExtractionEvent class
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class SearchableFieldExtractionEvent extends Event
{
    /**
     * @var ElementInterface The element being processed
     */
    public ElementInterface $element;
    
    /**
     * @var array The extracted field data
     */
    public array $fields = [];
    
    /**
     * @var array Configuration options for extraction
     */
    public array $config = [];
    
    /**
     * @var bool Whether the extraction is valid
     */
    public bool $isValid = true;
}
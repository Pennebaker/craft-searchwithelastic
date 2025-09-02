<?php
/**
 * Search w/Elastic plugin for Craft CMS 4.x
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\events;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use yii\base\Event;

/**
 * FieldDataTransformEvent class
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class FieldDataTransformEvent extends Event
{
    /**
     * @var FieldInterface The field being transformed
     */
    public FieldInterface $field;
    
    /**
     * @var ElementInterface The element containing the field
     */
    public ElementInterface $element;
    
    /**
     * @var mixed The original field value
     */
    public mixed $originalValue;
    
    /**
     * @var string The extracted search keywords
     */
    public string $keywords = '';
    
    /**
     * @var array The transformed field data
     */
    public array $transformedData = [];
}
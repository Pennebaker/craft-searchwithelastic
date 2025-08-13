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
use pennebaker\searchwithelastic\records\ElasticsearchRecord;
use yii\base\Event;

/**
 * ExtraFieldsEvent is triggered when defining additional fields for indexing
 *
 * @since 4.0.0
 */
class ExtraFieldsEvent extends Event
{
    /**
     * @var array The extra fields configuration (can be modified by event handlers)
     * Format: ['fieldName' => ['mapping' => [...], 'highlighter' => [...], 'value' => callable]]
     * @since 4.0.0
     */
    public array $extraFields = [];

    /**
     * @var Element|null The element being indexed (optional context)
     * @since 4.0.0
     */
    public ?Element $element = null;

    /**
     * @var ElasticsearchRecord|null The Elasticsearch record being created (optional context)
     * @since 4.0.0
     */
    public ?ElasticsearchRecord $esRecord = null;

    /**
     * @var int|null The site ID being indexed
     * @since 4.0.0
     */
    public ?int $siteId = null;

    /**
     * Add an extra field to the configuration
     *
     * @param string $fieldName
     * @param array $mapping Elasticsearch mapping configuration
     * @param object|array|null $highlighter Highlighter configuration
     * @param callable|string $valueCallback Callback to get the field value
     * @return void
     * @since 4.0.0
     */
    public function addField(string $fieldName, array $mapping, $highlighter = null, $valueCallback = null): void
    {
        $this->extraFields[$fieldName] = [
            'mapping' => $mapping,
            'highlighter' => $highlighter ?? (object)[],
            'value' => $valueCallback
        ];
    }

    /**
     * Remove an extra field from the configuration
     *
     * @param string $fieldName
     * @return void
     * @since 4.0.0
     */
    public function removeField(string $fieldName): void
    {
        unset($this->extraFields[$fieldName]);
    }

    /**
     * Check if a field exists in the configuration
     *
     * @param string $fieldName
     * @return bool
     * @since 4.0.0
     */
    public function hasField(string $fieldName): bool
    {
        return isset($this->extraFields[$fieldName]);
    }

    /**
     * Get a specific field configuration
     *
     * @param string $fieldName
     * @return array|null
     * @since 4.0.0
     */
    public function getField(string $fieldName): ?array
    {
        return $this->extraFields[$fieldName] ?? null;
    }
}

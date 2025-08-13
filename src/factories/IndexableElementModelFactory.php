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

namespace pennebaker\searchwithelastic\factories;

use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use InvalidArgumentException;
use pennebaker\searchwithelastic\models\IndexableElementModel;

/**
 * Factory for creating IndexableElementModel instances
 *
 * Centralizes model creation logic to ensure consistency and reduce
 * duplication across the codebase. Provides both single and batch
 * creation methods for optimal performance.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableElementModelFactory
{
    /**
     * @var array Supported element types and their specific attributes
     * @since 4.0.0
     */
    private const SUPPORTED_ELEMENT_TYPES = [
        Entry::class => [
            'sectionId', 'typeId', 'postDate', 'expiryDate', 'authorId'
        ],
        Asset::class => [
            'volumeId', 'folderId', 'filename', 'kind', 'size', 'width', 'height', 'alt'
        ],
        Category::class => [
            'groupId', 'level', 'lft', 'rgt'
        ]
    ];

    /**
     * Create IndexableElementModel from element data array
     *
     * @param string $elementType The element type class name
     * @param array $elementData Raw element data
     * @return IndexableElementModel The created model
     * @throws InvalidArgumentException If element type is unsupported or data is invalid
     * @since 4.0.0
     */
    public function createFromElementData(string $elementType, array $elementData): IndexableElementModel
    {
        if (!$this->isElementTypeSupported($elementType)) {
            throw new InvalidArgumentException("Unsupported element type: $elementType");
        }

        if (!$this->validateElementData($elementData)) {
            throw new InvalidArgumentException("Invalid element data provided");
        }

        $model = new IndexableElementModel();

        // Set base properties
        $model->elementId = $elementData['elementId'];
        $model->siteId = $elementData['siteId'];
        $model->type = $elementType;

        // Set common properties
        $this->applyCommonProperties($model, $elementData);

        // Apply element type specific properties
        $this->applyElementTypeSpecificData($model, $elementType, $elementData);

        return $model;
    }

    /**
     * Create IndexableElementModel from element instance
     *
     * @param ElementInterface|null $element The element instance
     * @return IndexableElementModel The created model
     * @throws InvalidArgumentException If element is null
     * @since 4.0.0
     */
    public function createFromElement(?ElementInterface $element): IndexableElementModel
    {
        if ($element === null) {
            throw new InvalidArgumentException('Element cannot be null');
        }

        $elementData = [
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'title' => $element->title ?? '',
            'slug' => $element->slug ?? '',
            'uri' => $element->uri ?? '',
            'status' => $element->status ?? '',
            'dateCreated' => $element->dateCreated?->format('Y-m-d H:i:s'),
            'dateUpdated' => $element->dateUpdated?->format('Y-m-d H:i:s'),
        ];

        // Add element-specific properties
        $elementType = get_class($element);
        if (isset(self::SUPPORTED_ELEMENT_TYPES[$elementType])) {
            foreach (self::SUPPORTED_ELEMENT_TYPES[$elementType] as $property) {
                if (property_exists($element, $property)) {
                    $elementData[$property] = $element->$property;
                }
            }
        }

        return $this->createFromElementData($elementType, $elementData);
    }

    /**
     * Create multiple IndexableElementModel instances efficiently
     *
     * @param string $elementType The element type class name
     * @param array $elementsData Array of element data arrays
     * @return IndexableElementModel[] Array of created models
     * @since 4.0.0
     */
    public function createBatch(string $elementType, array $elementsData): array
    {
        $models = [];

        foreach ($elementsData as $elementData) {
            if ($this->validateElementData($elementData)) {
                try {
                    $models[] = $this->createFromElementData($elementType, $elementData);
                } catch (InvalidArgumentException) {
                    // Skip invalid data in batch operations
                    continue;
                }
            }
        }

        return $models;
    }

    /**
     * Get supported element types
     *
     * @return array Array of supported element type class names
     * @since 4.0.0
     */
    public function getSupportedElementTypes(): array
    {
        $types = array_keys(self::SUPPORTED_ELEMENT_TYPES);

        // Add commerce types if available
        if (class_exists(Product::class)) {
            $types[] = Product::class;
        }

        if (class_exists(DigitalProduct::class)) {
            $types[] = DigitalProduct::class;
        }

        return $types;
    }

    /**
     * Check if element type is supported
     *
     * @param string $elementType The element type class name
     * @return bool True if supported
     * @since 4.0.0
     */
    public function isElementTypeSupported(string $elementType): bool
    {
        return in_array($elementType, $this->getSupportedElementTypes(), true);
    }

    /**
     * Validate element data array
     *
     * @param array $elementData The element data to validate
     * @return bool True if valid
     * @since 4.0.0
     */
    public function validateElementData(array $elementData): bool
    {
        // Check required fields
        if (!isset($elementData['elementId'], $elementData['siteId'])) {
            return false;
        }

        // Validate field types
        if (!is_int($elementData['elementId']) || !is_int($elementData['siteId'])) {
            return false;
        }

        // Validate values
        if ($elementData['elementId'] <= 0 || $elementData['siteId'] <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Apply common properties to model
     *
     * @param IndexableElementModel $model The model to populate
     * @param array $elementData The element data
     * @since 4.0.0
     */
    private function applyCommonProperties(IndexableElementModel $model, array $elementData): void
    {
        $commonProperties = [
            'title', 'slug', 'uri', 'status', 'dateCreated', 'dateUpdated'
        ];

        foreach ($commonProperties as $property) {
            if (isset($elementData[$property])) {
                $model->$property = $elementData[$property];
            }
        }
    }

    /**
     * Apply element type specific properties to model
     *
     * @param IndexableElementModel $model The model to populate
     * @param string $elementType The element type
     * @param array $elementData The element data
     * @since 4.0.0
     */
    private function applyElementTypeSpecificData(IndexableElementModel $model, string $elementType, array $elementData): void
    {
        if (!isset(self::SUPPORTED_ELEMENT_TYPES[$elementType])) {
            return;
        }

        $specificProperties = self::SUPPORTED_ELEMENT_TYPES[$elementType];

        foreach ($specificProperties as $property) {
            if (isset($elementData[$property])) {
                $model->$property = $elementData[$property];
            }
        }
    }
}

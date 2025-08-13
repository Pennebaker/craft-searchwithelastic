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
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use pennebaker\searchwithelastic\events\indexing\ModelEvent;
use pennebaker\searchwithelastic\models\IndexableElementModel;

/**
 * The IndexableElementModelFactory provides centralized model creation functionality.
 *
 * This factory handles the creation of IndexableElementModel instances with proper
 * configuration, validation, and event handling for all supported element types.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableElementModelFactory extends Component
{
    // Event constants following Craft patterns
    public const EVENT_BEFORE_CREATE_MODEL = 'beforeCreateModel';
    public const EVENT_AFTER_CREATE_MODEL = 'afterCreateModel';

    /**
     * Create an IndexableElementModel from a Craft element
     *
     * @param Element $element The Craft element to create a model for
     * @param int $siteId The site ID for the element
     * @return IndexableElementModel The created indexable element model
     */
    public function create(Element $element, int $siteId): IndexableElementModel
    {
        // Initialize base model
        $model = $this->initializeModel($element, $siteId);

        // Fire a 'beforeCreateModel' event
        $event = new ModelEvent([
            'element' => $element,
            'siteId' => $siteId,
            'model' => $model,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_CREATE_MODEL)) {
            $this->trigger(self::EVENT_BEFORE_CREATE_MODEL, $event);
        }

        // If event handler wants to skip default creation and provides model
        if ($event->skipDefaultCreation) {
            return $event->model;
        }

        // Configure model based on element type
        $finalModel = $this->configureModelByType($event->model, $element);

        // Fire an 'afterCreateModel' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_CREATE_MODEL)) {
            $event->model = $finalModel;
            $this->trigger(self::EVENT_AFTER_CREATE_MODEL, $event);
            $finalModel = $event->model;
        }

        return $finalModel;
    }

    /**
     * Create multiple IndexableElementModel instances from a collection of elements
     *
     * @param array $elements Array of Craft elements
     * @param int $siteId The site ID for the elements
     * @return array Array of IndexableElementModel instances
     */
    public function createMultiple(array $elements, int $siteId): array
    {
        $models = [];
        foreach ($elements as $element) {
            if ($element instanceof Element) {
                $models[] = $this->create($element, $siteId);
            }
        }
        return $models;
    }

    /**
     * Create IndexableElementModel from element type and ID
     *
     * @param string $elementType The element class name
     * @param int $elementId The element ID
     * @param int $siteId The site ID
     * @return IndexableElementModel|null The created model or null if element not found
     */
    public function createFromTypeAndId(string $elementType, int $elementId, int $siteId): ?IndexableElementModel
    {
        $element = $this->findElement($elementType, $elementId, $siteId);
        return $element ? $this->create($element, $siteId) : null;
    }

    /**
     * Check if an element type is supported for indexing
     *
     * @param string $elementType The element class name
     * @return bool True if the element type is supported
     */
    public function isElementTypeSupported(string $elementType): bool
    {
        $supportedTypes = [
            Entry::class,
            Asset::class,
            Category::class,
        ];

        // Add commerce types if available
        if (class_exists(Product::class)) {
            $supportedTypes[] = Product::class;
        }

        if (class_exists(DigitalProduct::class)) {
            $supportedTypes[] = DigitalProduct::class;
        }

        return in_array($elementType, $supportedTypes);
    }

    /**
     * Initialize a basic model with common properties
     *
     * @param Element $element The Craft element
     * @param int $siteId The site ID
     * @return IndexableElementModel The initialized model
     */
    protected function initializeModel(Element $element, int $siteId): IndexableElementModel
    {
        $model = new IndexableElementModel();
        $model->elementId = $element->id;
        $model->siteId = $siteId;
        $model->type = get_class($element);

        return $model;
    }

    /**
     * Configure model based on specific element type
     *
     * @param IndexableElementModel $model The model to configure
     * @param Element $element The Craft element
     * @return IndexableElementModel The configured model
     */
    protected function configureModelByType(IndexableElementModel $model, Element $element): IndexableElementModel
    {
        return match (get_class($element)) {
            Entry::class => $this->configureEntryModel($model),
            Asset::class => $this->configureAssetModel($model),
            Category::class => $this->configureCategoryModel($model),
            Product::class => $this->configureProductModel($model),
            DigitalProduct::class => $this->configureDigitalProductModel($model),
            default => $model,
        };
    }

    /**
     * Configure model for Entry elements
     *
     * @param IndexableElementModel $model The model to configure
     * @return IndexableElementModel The configured model
     */
    protected function configureEntryModel(IndexableElementModel $model): IndexableElementModel
    {
        // No additional configuration needed - IndexableElementModel only stores elementId, siteId, and type
        return $model;
    }

    /**
     * Configure model for Asset elements
     *
     * @param IndexableElementModel $model The model to configure
     * @return IndexableElementModel The configured model
     */
    protected function configureAssetModel(IndexableElementModel $model): IndexableElementModel
    {
        // No additional configuration needed - IndexableElementModel only stores elementId, siteId, and type
        return $model;
    }

    /**
     * Configure model for Category elements
     *
     * @param IndexableElementModel $model The model to configure
     * @return IndexableElementModel The configured model
     */
    protected function configureCategoryModel(IndexableElementModel $model): IndexableElementModel
    {
        // No additional configuration needed - IndexableElementModel only stores elementId, siteId, and type
        return $model;
    }

    /**
     * Configure model for Product elements (Commerce)
     *
     * @param IndexableElementModel $model The model to configure
     * @return IndexableElementModel The configured model
     */
    protected function configureProductModel(IndexableElementModel $model): IndexableElementModel
    {
        // No additional configuration needed - IndexableElementModel only stores elementId, siteId, and type
        return $model;
    }

    /**
     * Configure model for DigitalProduct elements
     *
     * @param IndexableElementModel $model The model to configure
     * @return IndexableElementModel The configured model
     */
    protected function configureDigitalProductModel(IndexableElementModel $model): IndexableElementModel
    {
        // No additional configuration needed - IndexableElementModel only stores elementId, siteId, and type
        return $model;
    }

    /**
     * Find an element by type, ID, and site
     *
     * @param string $elementType The element class name
     * @param int $elementId The element ID
     * @param int $siteId The site ID
     * @return Element|null The found element or null
     */
    protected function findElement(string $elementType, int $elementId, int $siteId): ?Element
    {
        if (!$this->isElementTypeSupported($elementType)) {
            return null;
        }

        try {
            return $elementType::find()
                ->id($elementId)
                ->siteId($siteId)
                ->status(null) // Include disabled elements
                ->one();
        } catch (\Exception) {
            return null;
        }
    }
}

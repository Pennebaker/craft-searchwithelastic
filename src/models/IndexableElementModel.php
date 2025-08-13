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

namespace pennebaker\searchwithelastic\models;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use JsonSerializable;
use pennebaker\searchwithelastic\exceptions\IndexableElementModelException;
use yii\base\InvalidConfigException;

/**
 * Model representing an element that can be indexed in Elasticsearch
 *
 * This model provides a way to reference and retrieve Craft elements
 * (entries, assets, categories, products, etc.) for indexing operations.
 *
 * @property-read Element $element The actual Craft element instance
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableElementModel extends Model implements JsonSerializable
{
    /**
     * @var int|null The ID of the element to be indexed
     */
    public ?int $elementId = null;

    /**
     * @var int|null The site ID for the element
     */
    public ?int $siteId = null;

    /**
     * @var class-string<ElementInterface>|null The fully qualified class name of the element type
     */
    public ?string $type = null;

    /**
     * Retrieves the actual Craft element instance based on the stored type, ID, and site
     *
     * @return Element The element instance
     * @throws IndexableElementModelException|InvalidConfigException When the element type is not supported,
     *                                        required plugins are not installed, or element is not found
     * @since 4.0.0
     */
    public function getElement(): Element
    {
        switch ($this->type) {
            case Product::class:
                $commercePlugin = craft\commerce\Plugin::getInstance();
                if (!$commercePlugin) {
                    throw new IndexableElementModelException($this, IndexableElementModelException::CRAFT_COMMERCE_NOT_INSTALLED);
                }
                $element = $commercePlugin->getProducts()->getProductById($this->elementId, $this->siteId);
                break;
            case DigitalProduct::class:
                $digitalProductsPlugin = craft\digitalproducts\Plugin::getInstance();
                if (!$digitalProductsPlugin) {
                    throw new IndexableElementModelException($this, IndexableElementModelException::DIGITAL_PRODUCTS_NOT_INSTALLED);
                }
                $element = Craft::$app->getElements()->getElementById($this->elementId, DigitalProduct::class, $this->siteId);
                break;
            case Entry::class:
                $element = Craft::$app->getEntries()->getEntryById($this->elementId, $this->siteId);
                break;
            case Asset::class:
                $element = Craft::$app->getAssets()->getAssetById($this->elementId, $this->siteId);
                break;
            case Category::class:
                $element = Craft::$app->getCategories()->getCategoryById($this->elementId, $this->siteId);
                break;
            default:
                throw new IndexableElementModelException($this, IndexableElementModelException::UNEXPECTED_TYPE);
        }

        if ($element === null) {
            throw new IndexableElementModelException($this, IndexableElementModelException::ELEMENT_NOT_FOUND);
        }

        return $element;
    }


    /**
     * Serializes the model to JSON by converting it to an array
     *
     * @return array The array representation of the model
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

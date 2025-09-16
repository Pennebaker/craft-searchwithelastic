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

namespace pennebaker\searchwithelastic\queries;

use craft\commerce\elements\Product;
use pennebaker\searchwithelastic\traits\FrontendFetchingTrait;
use pennebaker\searchwithelastic\traits\PriceFilteringTrait;
use pennebaker\searchwithelastic\traits\StatusFilteringTrait;

/**
 * Query builder for indexable Commerce products
 *
 * Provides specialized filtering methods for querying Commerce products that should be
 * indexed in Elasticsearch, including filtering by product types, prices, stock status,
 * shipping properties, and frontend fetching configuration.
 *
 * @template TElement of Product
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableProductQuery extends IndexableElementQuery
{
    use FrontendFetchingTrait;
    use StatusFilteringTrait;
    use PriceFilteringTrait;

    /**
     * Get the element type class for this query
     *
     * @return class-string<TElement> The fully qualified element class name
     */
    public static function elementType(): string
    {
        return Product::class;
    }

    /**
     * Apply default filtering based on plugin settings
     *
     * Applies status, excluded product types, and URL filtering based on
     * the plugin configuration.
     *
     * @return self self reference
     */
    protected function applyDefaultFilters(): self
    {
        $settings = $this->getPlugin()->getSettings();

        // Apply default status filtering
        $this->statuses($settings->indexableProductStatuses);

        // Apply excluded product types
        $this->excluded($settings->excludedProductTypes);

        // Apply URL filtering based on settings
        $this->includeElementsWithoutUrls($settings->indexElementsWithoutUrls);

        return $this;
    }

    /**
     * Narrows the query results based on excluded product type handles
     *
     * Possible values include:
     *
     * | Value | Fetches products…
     * | - | -
     * | `['clothing', 'books']` | not with product types `clothing` or `books`
     *
     * @param array $handles Product type handles to exclude
     * @return self self reference
     */
    public function excluded(array $handles): self
    {
        $this->excludedHandles = $handles;
        $this->applyexcludeFilter('type');
        return $this;
    }

    /**
     * Narrows the query results based on the products' product types
     *
     * Possible values include:
     *
     * | Value | Fetches products…
     * | - | -
     * | `'clothing'` | with a product type handle of `clothing`
     * | `['clothing', 'books']` | with product type handles of `clothing` or `books`
     * | `['not', 'clothing']` | not with a product type handle of `clothing`
     *
     * @param mixed $productTypes Product type handles or IDs
     * @return self self reference
     */
    public function productTypes(mixed $productTypes): self
    {
        $this->type($productTypes);
        return $this;
    }

    /**
     * Get the setting key for excluded frontend fetching items
     *
     * @return string The settings key for excluded product types
     */
    protected function getExcludedFrontendFetchingSettingKey(): string
    {
        return 'excludedFrontendFetchingProductTypes';
    }

    /**
     * Get the element query method to apply exclusions
     *
     * @return string The method name for product type filtering
     */
    protected function getFrontendFetchingFilterMethod(): string
    {
        return 'type';
    }

    /**
     * Get the price column name for this element type
     *
     * @return string The price column name for Commerce products
     */
    protected function getPriceColumn(): string
    {
        return 'commerce_variants.price';
    }

    /**
     * Get the promotional price column name for this element type
     *
     * @return string The promotional price column name for Commerce products
     */
    protected function getPromotionalPriceColumn(): string
    {
        return 'commerce_variants.promotionalPrice';
    }


    /**
     * Narrows the query results based on stock availability (has available variants)
     *
     * @param bool $hasStock Whether to include products with stock (true) or without (false)
     * @return self self reference
     */
    public function hasStock(bool $hasStock = true): self
    {
        $this->hasVariants($hasStock);
        return $this;
    }


    /**
     * Override the trait's onSale method to handle Commerce-specific logic
     *
     * @return self self reference
     */
    public function onSale(): self
    {
        $this->hasVariants()->andWhere(['>', $this->getPromotionalPriceColumn(), 0]);
        return $this;
    }

    /**
     * Narrows the query results based on shipping status
     *
     * @param bool $shippable Whether to include shippable (true) or non-shippable (false) products
     * @return self self reference
     */
    public function shippable(bool $shippable = true): self
    {
        $this->hasVariants()->andWhere(['commerce_variants.isShippable' => $shippable]);
        return $this;
    }

    /**
     * Narrows the query results based on variant weight range
     *
     * @param float $minWeight Minimum weight
     * @param float|null $maxWeight Maximum weight (optional)
     * @return self self reference
     */
    public function weightRange(float $minWeight, ?float $maxWeight = null): self
    {
        if ($maxWeight !== null) {
            $this->hasVariants()->andWhere(['and',
                ['>=', 'commerce_variants.weight', $minWeight],
                ['<=', 'commerce_variants.weight', $maxWeight]
            ]);
        } else {
            $this->hasVariants()->andWhere(['>=', 'commerce_variants.weight', $minWeight]);
        }

        return $this;
    }

    /**
     * Narrows the query results based on variant SKU pattern using SQL LIKE matching
     *
     * @param string $skuPattern SKU pattern with % wildcards
     * @return self self reference
     */
    public function skuLike(string $skuPattern): self
    {
        $this->hasVariants()->andWhere(['like', 'commerce_variants.sku', $skuPattern]);
        return $this;
    }
}

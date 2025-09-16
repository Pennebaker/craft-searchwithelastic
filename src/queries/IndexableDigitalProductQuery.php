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

use craft\digitalproducts\elements\Product as DigitalProduct;
use pennebaker\searchwithelastic\traits\FrontendFetchingTrait;
use pennebaker\searchwithelastic\traits\PriceFilteringTrait;
use pennebaker\searchwithelastic\traits\StatusFilteringTrait;

/**
 * Query builder for indexable Digital Products
 *
 * Provides specialized filtering methods for querying digital products that should be
 * indexed in Elasticsearch, including filtering by product types, prices, licensing,
 * and frontend fetching configuration.
 *
 * @template TElement of DigitalProduct
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableDigitalProductQuery extends IndexableElementQuery
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
        return DigitalProduct::class;
    }

    /**
     * Apply default filtering based on plugin settings
     *
     * Applies status, excluded digital product types, and URL filtering based on
     * the plugin configuration.
     *
     * @return self self reference
     */
    protected function applyDefaultFilters(): self
    {
        $settings = $this->getPlugin()->getSettings();

        // Apply default status filtering
        $this->statuses($settings->indexableDigitalProductStatuses);

        // Apply excluded digital product types
        $this->excluded($settings->excludedDigitalProductTypes);

        // Apply URL filtering based on settings
        $this->includeElementsWithoutUrls($settings->indexElementsWithoutUrls);

        return $this;
    }

    /**
     * Narrows the query results based on excluded digital product type handles
     *
     * Possible values include:
     *
     * | Value | Fetches digital products…
     * | - | -
     * | `['ebooks', 'software']` | not with product types `ebooks` or `software`
     *
     * @param array $handles Digital product type handles to exclude
     * @return self self reference
     */
    public function excluded(array $handles): self
    {
        $this->excludedHandles = $handles;
        $this->applyexcludeFilter('type');
        return $this;
    }

    /**
     * Narrows the query results based on the digital products' product types
     *
     * Possible values include:
     *
     * | Value | Fetches digital products…
     * | - | -
     * | `'ebooks'` | with a product type handle of `ebooks`
     * | `['ebooks', 'software']` | with product type handles of `ebooks` or `software`
     * | `['not', 'ebooks']` | not with a product type handle of `ebooks`
     *
     * @param mixed $digitalProductTypes Digital product type handles or IDs
     * @return self self reference
     */
    public function digitalProductTypes(mixed $digitalProductTypes): self
    {
        $this->type($digitalProductTypes);
        return $this;
    }

    /**
     * Get the setting key for excluded frontend fetching items
     *
     * @return string The settings key for excluded digital product types
     */
    protected function getExcludedFrontendFetchingSettingKey(): string
    {
        return 'excludedFrontendFetchingDigitalProductTypes';
    }

    /**
     * Get the element query method to apply exclusions
     *
     * @return string The method name for digital product type filtering
     */
    protected function getFrontendFetchingFilterMethod(): string
    {
        return 'type';
    }

    /**
     * Get the price column name for this element type
     *
     * @return string The price column name for digital products
     */
    protected function getPriceColumn(): string
    {
        return 'digitalproducts_products.price';
    }

    /**
     * Get the promotional price column name for this element type
     *
     * @return string The promotional price column name for digital products
     */
    protected function getPromotionalPriceColumn(): string
    {
        return 'digitalproducts_products.promotionalPrice';
    }




    /**
     * Narrows the query results based on SKU pattern using SQL LIKE matching
     *
     * @param string $skuPattern SKU pattern with % wildcards
     * @return self self reference
     */
    public function skuLike(string $skuPattern): self
    {
        $this->andWhere(['like', 'digitalproducts_products.sku', $skuPattern]);
        return $this;
    }

    /**
     * Narrows the query results based on taxable status
     *
     * @param bool $taxable Whether to include taxable (true) or non-taxable (false) products
     * @return self self reference
     */
    public function taxable(bool $taxable = true): self
    {
        $this->andWhere(['digitalproducts_products.taxable' => $taxable]);
        return $this;
    }

    /**
     * Narrows the query results based on license types
     *
     * @param array $licenseTypes License type IDs
     * @return self self reference
     */
    public function licenseTypes(array $licenseTypes): self
    {
        $this->andWhere(['digitalproducts_products.licenseId' => $licenseTypes]);
        return $this;
    }

}

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

namespace pennebaker\searchwithelastic\traits;

use pennebaker\searchwithelastic\queries\IndexableDigitalProductQuery;
use pennebaker\searchwithelastic\queries\IndexableProductQuery;

/**
 * Trait for price filtering functionality
 *
 * Provides centralized price filtering logic for commerce-enabled queries
 * like IndexableProductQuery and IndexableDigitalProductQuery.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
trait PriceFilteringTrait
{
    /**
     * @var float|null Minimum price filter
     */
    protected ?float $minPrice = null;

    /**
     * @var float|null Maximum price filter
     */
    protected ?float $maxPrice = null;

    /**
     * Set price range filter
     *
     * @param float|null $min Minimum price (null for no minimum)
     * @param float|null $max Maximum price (null for no maximum)
     * @return PriceFilteringTrait|IndexableDigitalProductQuery|IndexableProductQuery For method chaining
     */
    public function priceRange(?float $min = null, ?float $max = null): self
    {
        if ($this->validatePriceRange($min, $max)) {
            $this->minPrice = $min;
            $this->maxPrice = $max;
        }

        return $this;
    }

    /**
     * Set minimum price filter
     *
     * @param float|null $price Minimum price
     * @return PriceFilteringTrait|IndexableDigitalProductQuery|IndexableProductQuery For method chaining
     */
    public function minPrice(?float $price): self
    {
        if ($price === null || $price >= 0) {
            $this->minPrice = $price;
        }

        return $this;
    }

    /**
     * Set maximum price filter
     *
     * @param float|null $price Maximum price
     * @return PriceFilteringTrait|IndexableDigitalProductQuery|IndexableProductQuery For method chaining
     */
    public function maxPrice(?float $price): self
    {
        if ($price === null || $price >= 0) {
            $this->maxPrice = $price;
        }

        return $this;
    }

    /**
     * Set exact price filter (min and max to same value)
     *
     * @param float $price Exact price to match
     * @return PriceFilteringTrait|IndexableDigitalProductQuery|IndexableProductQuery For method chaining
     */
    public function exactPrice(float $price): self
    {
        if ($price >= 0) {
            $this->minPrice = $price;
            $this->maxPrice = $price;
        }

        return $this;
    }

    /**
     * Filter to only free items (price = 0)
     *
     * @return PriceFilteringTrait|IndexableDigitalProductQuery|IndexableProductQuery For method chaining
     */
    public function freeOnly(): self
    {
        return $this->exactPrice(0);
    }

    /**
     * Filter to only paid items (price > 0)
     *
     * @return PriceFilteringTrait|IndexableDigitalProductQuery|IndexableProductQuery For method chaining
     */
    public function paidOnly(): self
    {
        return $this->minPrice(0.01);
    }

    /**
     * Check if any price filter is set
     *
     * @return bool True if min or max price is set
     */
    public function hasPriceFilter(): bool
    {
        return $this->minPrice !== null || $this->maxPrice !== null;
    }

    /**
     * Clear all price filters
     *
     * @return PriceFilteringTrait|IndexableDigitalProductQuery|IndexableProductQuery For method chaining
     */
    public function clearPriceFilter(): self
    {
        $this->minPrice = null;
        $this->maxPrice = null;
        return $this;
    }

    /**
     * Validate price range values
     *
     * @param float|null $min Minimum price
     * @param float|null $max Maximum price
     * @return bool True if the range is valid
     */
    public function validatePriceRange(?float $min, ?float $max): bool
    {
        // Null values are valid (no constraint)
        if ($min === null && $max === null) {
            return true;
        }

        // Check for negative values
        if (($min !== null && $min < 0) || ($max !== null && $max < 0)) {
            return false;
        }

        // If both are set, min should not be greater than max
        if ($min !== null && $max !== null && $min > $max) {
            return false;
        }

        return true;
    }

    /**
     * Format price value for consistent display
     *
     * @param float|null $price Price to format
     * @return string|null Formatted price string or null
     */
    public function formatPrice(?float $price): ?string
    {
        if ($price === null) {
            return null;
        }

        return number_format($price, 2, '.', '');
    }
}

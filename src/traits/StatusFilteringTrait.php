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

namespace pennebaker\searchwithelastic\traits;

use pennebaker\searchwithelastic\queries\IndexableCategoryQuery;
use pennebaker\searchwithelastic\queries\IndexableDigitalProductQuery;
use pennebaker\searchwithelastic\queries\IndexableEntryQuery;
use pennebaker\searchwithelastic\queries\IndexableProductQuery;

/**
 * Trait for status filtering functionality
 *
 * Reduces ~60 lines of duplication across query classes by centralizing
 * status filtering logic for entries, products, and digital products.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
trait StatusFilteringTrait
{
    /**
     * @var array|null Array of status values to filter by
     */
    protected ?array $status = null;

    /**
     * Set the status filter
     *
     * @param string|array $value Status value(s) to filter by
     * @return IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery|StatusFilteringTrait For method chaining
     */
    public function status(string|array $value): self
    {
        $this->status = $this->normalizeStatus($value);
        return $this;
    }

    /**
     * Filter to only live elements
     *
     * @return IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery|StatusFilteringTrait For method chaining
     */
    public function liveOnly(): self
    {
        return $this->status(['live']);
    }

    /**
     * Filter to only pending elements
     *
     * @return IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery|StatusFilteringTrait For method chaining
     */
    public function pendingOnly(): self
    {
        return $this->status(['pending']);
    }

    /**
     * Filter to only expired elements
     *
     * @return IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery|StatusFilteringTrait For method chaining
     */
    public function expiredOnly(): self
    {
        return $this->status(['expired']);
    }

    /**
     * Filter to only disabled elements
     *
     * @return IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery|StatusFilteringTrait For method chaining
     */
    public function disabledOnly(): self
    {
        return $this->status(['disabled']);
    }

    /**
     * Filter to only enabled elements
     *
     * @return IndexableCategoryQuery|IndexableDigitalProductQuery|IndexableEntryQuery|IndexableProductQuery|StatusFilteringTrait For method chaining
     */
    public function enabledOnly(): self
    {
        return $this->status(['enabled']);
    }

    /**
     * Check if the query has a specific status filter
     *
     * @param string $status The status to check for
     * @return bool True if the status is included in the filter
     */
    public function hasStatus(string $status): bool
    {
        if ($this->status === null) {
            return false;
        }

        return in_array($status, $this->status, true);
    }

    /**
     * Get valid status values
     *
     * @return array Array of valid status values
     */
    public function getValidStatuses(): array
    {
        return ['live', 'pending', 'expired', 'disabled', 'enabled'];
    }

    /**
     * Check if a status value is valid
     *
     * @param string $status The status to validate
     * @return bool True if the status is valid
     */
    public function isValidStatus(string $status): bool
    {
        return in_array($status, $this->getValidStatuses(), true);
    }

    /**
     * Normalize status input to array format
     *
     * @param string|array $status Status value(s) to normalize
     * @return array Normalized array of valid status values
     */
    public function normalizeStatus(string|array $status): array
    {
        if (is_string($status)) {
            $status = [$status];
        }

        // Filter out invalid statuses
        return array_values(array_filter($status, [$this, 'isValidStatus']));
    }
}

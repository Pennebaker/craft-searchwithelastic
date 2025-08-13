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

/**
 * Represents the result of an indexing operation
 *
 * This class encapsulates the outcome of attempting to index an element
 * in Elasticsearch, including status, reason for failure/skip, and
 * additional metadata about the operation.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexingResult
{
    /**
     * @var string Indexing completed successfully
     * @since 4.0.0
     */
    public const STATUS_SUCCESS = 'success';

    /**
     * @var string Indexing was skipped (e.g., element disabled, not published)
     * @since 4.0.0
     */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var string Indexing partially succeeded (e.g., indexed but missing frontend content)
     * @since 4.0.0
     */
    public const STATUS_PARTIAL = 'partial';

    /**
     * @var string Indexing failed due to an error
     * @since 4.0.0
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @var string Element type is disabled for indexing
     * @since 4.0.0
     */
    public const STATUS_DISABLED = 'disabled';

    /**
     * @var string The status of the indexing operation
     * @since 4.0.0
     */
    public string $status;

    /**
     * @var string|null Reason for skip/partial/failure
     * @since 4.0.0
     */
    public ?string $reason = null;

    /**
     * @var string|null User-friendly message
     * @since 4.0.0
     */
    public ?string $message = null;

    /**
     * @var bool Whether frontend content fetching was attempted
     * @since 4.0.0
     */
    public bool $frontendFetchAttempted = false;

    /**
     * @var bool Whether frontend content fetching succeeded
     * @since 4.0.0
     */
    public bool $frontendFetchSuccess = false;

    /**
     * @var string|null Error details for debugging
     * @since 4.0.0
     */
    public ?string $errorDetails = null;

    /**
     * @var string|null The URL that was attempted for frontend fetching
     * @since 4.0.0
     */
    public ?string $frontendFetchUrl = null;

    /**
     * @var int|null HTTP status code received during frontend fetching
     * @since 4.0.0
     */
    public ?int $frontendFetchStatusCode = null;

    /**
     * @var string|null Error message from frontend fetching attempt
     * @since 4.0.0
     */
    public ?string $frontendFetchError = null;

    /**
     * @var array Response headers from frontend fetching attempt
     * @since 4.0.0
     */
    public array $frontendFetchHeaders = [];

    /**
     * Constructs a new indexing result
     *
     * @param string $status The status of the indexing operation
     * @param string|null $reason Optional reason for skip/partial/failure
     * @param string|null $message Optional user-friendly message
     * @since 4.0.0
     */
    public function __construct(string $status, ?string $reason = null, ?string $message = null)
    {
        $this->status = $status;
        $this->reason = $reason;
        $this->message = $message;
    }

    /**
     * Create a success result
     *
     * @param string|null $message Optional success message
     * @return self
     * @since 4.0.0
     */
    public static function success(?string $message = null): self
    {
        return new self(self::STATUS_SUCCESS, null, $message);
    }

    /**
     * Create a skipped result
     *
     * @param string $reason The reason why indexing was skipped
     * @param string|null $message Optional user-friendly message
     * @return self
     * @since 4.0.0
     */
    public static function skipped(string $reason, ?string $message = null): self
    {
        return new self(self::STATUS_SKIPPED, $reason, $message);
    }

    /**
     * Create a partial result (indexed but missing content)
     *
     * @param string $reason The reason for partial success
     * @param string|null $message Optional user-friendly message
     * @return self
     * @since 4.0.0
     */
    public static function partial(string $reason, ?string $message = null): self
    {
        return new self(self::STATUS_PARTIAL, $reason, $message);
    }

    /**
     * Create a failed result
     *
     * @param string $reason The reason for failure
     * @param string|null $message Optional user-friendly message
     * @param string|null $errorDetails Optional detailed error information for debugging
     * @return self
     * @since 4.0.0
     */
    public static function failed(string $reason, ?string $message = null, ?string $errorDetails = null): self
    {
        $result = new self(self::STATUS_FAILED, $reason, $message);
        $result->errorDetails = $errorDetails;
        return $result;
    }

    /**
     * Create a disabled result (element type not enabled for indexing)
     *
     * @param string $reason The reason why the element type is disabled
     * @param string|null $message Optional user-friendly message
     * @return self
     * @since 4.0.0
     */
    public static function disabled(string $reason, ?string $message = null): self
    {
        return new self(self::STATUS_DISABLED, $reason, $message);
    }

    /**
     * Check if the result represents a successful operation
     *
     * @return bool True if the operation was successful
     * @since 4.0.0
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if the result represents a partial success
     *
     * @return bool True if the operation was partially successful
     * @since 4.0.0
     */
    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    /**
     * Check if the operation was skipped
     *
     * @return bool True if the operation was skipped
     * @since 4.0.0
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * Check if the operation failed
     *
     * @return bool True if the operation failed
     * @since 4.0.0
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the element type is disabled
     *
     * @return bool True if the element type is disabled for indexing
     * @since 4.0.0
     */
    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    /**
     * Convert to array for JSON responses
     *
     * @param bool $includeDebugInfo Whether to include debugging information
     * @return array The result data as an associative array
     * @since 4.0.0
     */
    public function toArray(bool $includeDebugInfo = false): array
    {
        $result = [
            'status' => $this->status,
            'reason' => $this->reason,
            'message' => $this->message,
            'frontendFetchAttempted' => $this->frontendFetchAttempted,
            'frontendFetchSuccess' => $this->frontendFetchSuccess,
            'errorDetails' => $this->errorDetails,
        ];

        // Include debugging information only when requested
        if ($includeDebugInfo && $this->frontendFetchAttempted) {
            $result['frontendFetchDebug'] = [
                'url' => $this->frontendFetchUrl,
                'statusCode' => $this->frontendFetchStatusCode,
                'error' => $this->frontendFetchError,
                'headers' => $this->frontendFetchHeaders
            ];
        }

        return $result;
    }
}

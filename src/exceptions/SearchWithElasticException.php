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

namespace pennebaker\searchwithelastic\exceptions;

use Craft;
use ReflectionClass;
use Throwable;
use yii\base\Exception;

/**
 * Base exception class for Search with Elastic plugin
 *
 * @package pennebaker\searchwithelastic\exceptions
 * @since 4.0.0
 */
class SearchWithElasticException extends Exception
{
    /**
     * @var string|null The category for logging
     * @since 4.0.0
     */
    public ?string $logCategory = null;

    /**
     * @var array Additional context data
     * @since 4.0.0
     */
    public array $context = [];

    /**
     * @var bool Whether this error should be logged
     * @since 4.0.0
     */
    public bool $shouldLog = true;

    /**
     * Constructor
     *
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     * @param string|null $logCategory
     * @param array $context
     * @since 4.0.0
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        Throwable $previous = null,
        ?string $logCategory = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->logCategory = $logCategory ?? 'search-with-elastic';
        $this->context = $context;
    }

    /**
     * Get user-friendly error message
     *
     * @return string
     * @since 4.0.0
     */
    public function getUserMessage(): string
    {
        // Override in subclasses to provide user-friendly messages
        return $this->getMessage();
    }

    /**
     * Get context data
     *
     * @return array
     * @since 4.0.0
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get the user-friendly name of this exception
     *
     * Automatically generates exception names based on the class name.
     * Converts CamelCase to space-separated words and adds "Error" suffix.
     *
     * @return string The user-friendly exception name
     * @since 4.0.0
     */
    public function getName(): string
    {
        $className = (new ReflectionClass($this))->getShortName();

        // Convert CamelCase to space-separated words
        $friendlyName = preg_replace('/(?<!^)([A-Z])/', ' $1', $className);

        // Remove "Exception" suffix if present and add "Error"
        $friendlyName = preg_replace('/\s*Exception$/', '', $friendlyName);

        return Craft::t('searchwithelastic', $friendlyName . ' Error');
    }
}

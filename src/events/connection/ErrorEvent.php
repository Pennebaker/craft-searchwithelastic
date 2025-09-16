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

namespace pennebaker\searchwithelastic\events\connection;

use Exception;
use yii\base\Event;

/**
 * ErrorEvent is triggered when an error occurs during search operations
 *
 * This event allows plugins to handle or log errors that occur during
 * search indexing, querying, or other Elasticsearch operations.
 *
 * @since 4.0.0
 */
class ErrorEvent extends Event
{
    /**
     * @var Exception The exception that was thrown
     * @since 4.0.0
     */
    public Exception $exception;

    /**
     * ErrorEvent constructor
     *
     * @param Exception $exception The exception that occurred
     * @param array $config Additional configuration options
     * @since 4.0.0
     */
    public function __construct(Exception $exception, array $config = [])
    {
        parent::__construct($config);
        $this->exception = $exception;
    }
}

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

namespace pennebaker\searchwithelastic\events\connection;

use yii\base\Event;

/**
 * ConnectionTestEvent is triggered when testing the Elasticsearch connection
 *
 * This event allows plugins to perform additional validation or logging
 * during connection tests.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ConnectionTestEvent extends Event
{
    /**
     * @var string The Elasticsearch endpoint being tested
     * @since 4.0.0
     */
    public string $endpoint;

    /**
     * @var array Connection configuration parameters
     * @since 4.0.0
     */
    public array $config = [];

    /**
     * @var bool|null The connection test result (null if not yet determined)
     * @since 4.0.0
     */
    public ?bool $result = null;

    /**
     * @var string|null Any error message from the connection test
     * @since 4.0.0
     */
    public ?string $errorMessage = null;

    /**
     * @var bool Whether the default connection test should be skipped
     * @since 4.0.0
     */
    public bool $skipDefaultTest = false;
}

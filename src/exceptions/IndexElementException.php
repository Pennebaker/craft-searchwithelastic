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

namespace pennebaker\searchwithelastic\exceptions;

use yii\base\Exception;

/**
 * IndexElementException represents an exception caused by element indexing failures.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexElementException extends Exception
{
    /**
     * @return string the user-friendly name of this exception
     * @since 4.0.0
     */
    public function getName(): string
    {
        return 'Element Index Error';
    }
}

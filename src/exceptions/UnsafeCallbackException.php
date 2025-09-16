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

use yii\base\Exception;

/**
 * Unsafe Callback Exception
 * 
 * Thrown when an unsafe callback is detected during validation
 * 
 * @author Pennebaker
 * @since 4.0.0
 */
class UnsafeCallbackException extends Exception
{
    /**
     * @inheritdoc
     * @since 4.0.0
     */
    public function getName(): string
    {
        return 'Unsafe Callback Exception';
    }
}

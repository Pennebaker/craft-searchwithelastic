<?php

namespace pennebaker\searchwithelastic\services;

use Craft;
use craft\base\Component;

/**
 * Callback Validator Service
 * 
 * Validates and safely executes callbacks
 * 
 * @since 4.0.0
 */
class CallbackValidator extends Component
{
    /**
     * Safely execute a callback with validation
     * 
     * @param callable $callback The callback to execute
     * @param array $params Parameters to pass to the callback
     * @param string $context Context for error messages
     * @param mixed $default Default value if callback fails
     * @return mixed
     * @since 4.0.0
     */
    public function safeExecute($callback, array $params = [], string $context = 'callback', $default = null)
    {
        try {
            if (!is_callable($callback)) {
                Craft::warning("Invalid callback in context: {$context}", __METHOD__);
                return $default;
            }
            
            return call_user_func_array($callback, $params);
        } catch (\Exception $e) {
            Craft::warning("Callback execution failed in {$context}: " . $e->getMessage(), __METHOD__);
            return $default;
        }
    }
}
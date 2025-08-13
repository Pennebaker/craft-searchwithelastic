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

use Craft;
use craft\errors\MissingComponentException;
use pennebaker\searchwithelastic\exceptions\SearchWithElasticException;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\base\InvalidConfigException;
use yii\elasticsearch\Exception;

/**
 * Trait for consistent error handling across services
 * 
 * @since 4.0.0
 */
trait ErrorHandlingTrait
{
    /**
     * Handle exceptions consistently
     *
     * @param \Throwable $e
     * @param string $context
     * @param array $data
     * @param string|null $userMessage
     * @return void
     * @throws MissingComponentException
     * @throws \JsonException
     * @since 4.0.0
     */
    protected function handleException(\Throwable $e, string $context, array $data = [], ?string $userMessage = null): void
    {
        // Get appropriate log level based on exception type
        $logLevel = $this->getLogLevel($e);

        // Build log message
        $logMessage = sprintf(
            "[%s] %s: %s",
            $context,
            get_class($e),
            $e->getMessage()
        );

        // Add context data if available
        if (!empty($data)) {
            $logMessage .= ' | Context: ' . json_encode($data, JSON_THROW_ON_ERROR);
        }

        // Log the error
        switch ($logLevel) {
            case 'error':
                Craft::error($logMessage, __METHOD__);
                break;
            case 'warning':
                Craft::warning($logMessage, __METHOD__);
                break;
            case 'info':
                Craft::info($logMessage, __METHOD__);
                break;
            default:
                Craft::info($logMessage, __METHOD__);
                break;
        }

        // Log stack trace for errors and above
        if (in_array($logLevel, ['error', 'critical'])) {
            Craft::error("Stack trace:\n" . $e->getTraceAsString(), __METHOD__);
        }

        // Set flash message for user if in CP request
        if ($userMessage && Craft::$app->getRequest()->getIsCpRequest()) {
            Craft::$app->getSession()->setError($userMessage);
        }
    }

    /**
     * Get appropriate log level for exception
     *
     * @param \Throwable $e
     * @return string
     * @since 4.0.0
     */
    protected function getLogLevel(\Throwable $e): string
    {
        // Critical errors
        if ($e instanceof Exception ||
            $e instanceof InvalidConfigException) {
            return 'error';
        }

        // Plugin-specific exceptions
        if ($e instanceof SearchWithElasticException) {
            return $e->shouldLog ? 'warning' : 'info';
        }

        // Default to warning
        return 'warning';
    }

    /**
     * Get user-friendly error message
     *
     * @param \Throwable $e
     * @param string $defaultMessage
     * @return string
     * @since 4.0.0
     */
    protected function getUserMessage(\Throwable $e, string $defaultMessage = ''): string
    {
        if ($e instanceof SearchWithElasticException) {
            return $e->getUserMessage();
        }

        // Don't expose technical details to users
        if (empty($defaultMessage)) {
            return Craft::t('search-with-elastic', 'An error occurred. Please try again later.');
        }

        return $defaultMessage;
    }

    /**
     * Safe execution with validation and error handling
     *
     * @param callable $callback
     * @param string $context
     * @param mixed|null $defaultReturn
     * @param array $data
     * @return mixed
     * @throws \JsonException
     * @throws MissingComponentException
     * @since 4.0.0
     */
    protected function safeExecute(callable $callback, string $context, mixed $defaultReturn = null, array $data = []): mixed
    {
        try {
            // Validate callback is safe before execution
            $validator = SearchWithElastic::getInstance()->callbackValidator;
            if (!$validator->validateCallback($callback, $context, false)) {
                Craft::warning("Unsafe callback blocked in context: $context", __METHOD__);
                return $defaultReturn;
            }
            
            // Execute the validated callback
            return $callback();
        } catch (\Throwable $e) {
            $this->handleException($e, $context, $data);
            return $defaultReturn;
        }
    }
}

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

namespace pennebaker\searchwithelastic\validators;

use Craft;
use pennebaker\searchwithelastic\SearchWithElastic;

/**
 * Elasticsearch Index Name Validator
 *
 * Validates index names according to Elasticsearch naming conventions:
 * - Must be lowercase
 * - Cannot start with -, _, or +
 * - Cannot contain: \, /, *, ?, ", <, >, |, space, comma, #, :
 * - Cannot be . or ..
 * - Max 255 bytes
 * - No NULL bytes
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexValidator
{
    /**
     * Validates an Elasticsearch index name
     *
     * @param string $indexName The index name to validate
     * @return array Array of error messages, empty if valid
     * @since 4.0.0
     */
    public static function validateIndexName(string $indexName): array
    {
        $errors = [];

        // Check for empty string
        if ($indexName === '') {
            $errors[] = self::translate('Index name cannot be empty.');
            return $errors;
        }

        // Check for NULL bytes
        if (strpos($indexName, "\0") !== false) {
            $errors[] = self::translate('Index name cannot contain NULL bytes.');
        }

        // Check byte length (max 255 bytes)
        if (strlen($indexName) > 255) {
            $errors[] = self::translate('Index name cannot exceed 255 bytes.');
        }

        // Check for forbidden names
        if ($indexName === '.' || $indexName === '..') {
            $errors[] = self::translate('Index name cannot be "." or "..".');
        }

        // Check if lowercase
        if ($indexName !== strtolower($indexName)) {
            $errors[] = self::translate('Index name must be lowercase.');
        }

        // Check starting characters (cannot start with -, _, or +)
        if (preg_match('/^[-_+]/', $indexName)) {
            $errors[] = self::translate('Index name cannot start with "-", "_", or "+".');
        }

        // Check for forbidden characters: \, /, *, ?, ", <, >, |, space, comma, #, :
        if (preg_match('/[\\\\\/\*\?"<>|\\s,#:]/', $indexName)) {
            $errors[] = self::translate('Index name contains forbidden characters. Cannot contain: \\, /, *, ?, ", <, >, |, space, comma, #, or :');
        }

        return $errors;
    }

    /**
     * Checks if an index name is valid
     *
     * @param string $indexName The index name to validate
     * @return bool True if valid, false otherwise
     * @since 4.0.0
     */
    public static function isValidIndexName(string $indexName): bool
    {
        return empty(self::validateIndexName($indexName));
    }

    /**
     * Validates multiple index names at once
     *
     * @param array $indexNames Array of index names to validate
     * @return array Associative array with index names as keys and arrays of errors as values
     * @since 4.0.0
     */
    public static function validateMultipleIndexNames(array $indexNames): array
    {
        $results = [];
        
        foreach ($indexNames as $name) {
            if (is_string($name)) {
                $errors = self::validateIndexName($name);
                if (!empty($errors)) {
                    $results[$name] = $errors;
                }
            }
        }
        
        return $results;
    }

    /**
     * Sanitizes an index name to make it Elasticsearch compliant
     *
     * @param string $indexName The index name to sanitize
     * @return string The sanitized index name
     * @since 4.0.0
     */
    public static function sanitizeIndexName(string $indexName): string
    {
        // Convert to lowercase
        $sanitized = strtolower($indexName);
        
        // Remove NULL bytes
        $sanitized = str_replace("\0", '', $sanitized);
        
        // Replace forbidden characters with hyphens
        $sanitized = preg_replace('/[\\\\\/\*\?"<>|\\s,#:]/', '-', $sanitized);
        
        // Remove leading forbidden characters
        $sanitized = preg_replace('/^[-_+]+/', '', $sanitized);
        
        // Handle special cases
        if ($sanitized === '.' || $sanitized === '..') {
            $sanitized = 'index';
        }
        
        // Truncate if too long (leave room for potential suffixes)
        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 200);
        }
        
        // Ensure we have a valid result
        if ($sanitized === '' || !self::isValidIndexName($sanitized)) {
            $sanitized = 'index';
        }
        
        return $sanitized;
    }

    /**
     * Translates a message, falling back to the raw message if Craft is not available
     *
     * @param string $message The message to translate
     * @return string The translated message
     * @since 4.0.0
     */
    private static function translate(string $message): string
    {
        if (class_exists('Craft') && defined('pennebaker\searchwithelastic\SearchWithElastic::PLUGIN_HANDLE')) {
            return Craft::t(SearchWithElastic::PLUGIN_HANDLE, $message);
        }
        
        return $message;
    }
}

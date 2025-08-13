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

namespace pennebaker\searchwithelastic\helpers\validation;

use Craft;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\web\BadRequestHttpException;

/**
 * ValidationHelper provides common validation utilities for the Search w/Elastic plugin
 *
 * Includes secure numeric validation methods that prevent various attack vectors
 * such as scientific notation, hexadecimal, octal, and integer overflow attacks.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ValidationHelper
{
    /**
     * Filter out empty values from an array
     *
     * @param array<string, mixed> $array The array to filter
     * @return array<string, mixed> The filtered array with empty values removed
     * @since 4.0.0
     */
    public static function filterEmptyValues(array $array): array
    {
        return array_filter($array, static function ($value) {
            return !empty($value);
        });
    }

    /**
     * Validate that at least one status is selected for element indexing
     *
     * @param string[] $statuses The array of statuses to validate
     * @param string $elementType The element type for error messages
     * @return string|null Error message if validation fails, null if valid
     * @since 4.0.0
     */
    public static function validateStatusArray(array $statuses, string $elementType): ?string
    {
        if (empty($statuses)) {
            return "At least one $elementType status must be selected.";
        }

        return null;
    }

    /**
     * Validate multiple status arrays and return all error messages
     *
     * @param array $statusArrays Associative array of fieldName => [statuses, elementType]
     * @return array Array of error messages keyed by field name
     * @since 4.0.0
     */
    public static function validateMultipleStatusArrays(array $statusArrays): array
    {
        $errors = [];

        foreach ($statusArrays as $fieldName => [$statuses, $elementType]) {
            $error = self::validateStatusArray($statuses, $elementType);
            if ($error !== null) {
                $errors[$fieldName] = $error;
            }
        }

        return $errors;
    }

    /**
     * Clean up multiple exclude arrays by removing empty values
     *
     * @param array $excludeArrays Associative array of fieldName => array values
     * @return array Cleaned arrays keyed by field name
     * @since 4.0.0
     */
    public static function cleanupExcludeArrays(array $excludeArrays): array
    {
        return array_map(static function ($array) {
            return self::filterEmptyValues($array);
        }, $excludeArrays);
    }

    /**
     * Validate entry type exclude format (should include section:entryType)
     *
     * @param array $entryTypes Array of entry type handles
     * @return array Array of validation errors
     * @since 4.0.0
     */
    public static function validateEntryTypeFormat(array $entryTypes): array
    {
        $errors = [];

        foreach ($entryTypes as $entryType) {
            if (!empty($entryType) && !str_contains($entryType, ':')) {
                $errors[] = "Entry type '$entryType' should be in format 'section:entryType'";
            }
        }

        return $errors;
    }

    /**
     * Convert legacy entry type format to new section:entryType format
     *
     * @param array $entryTypes Array of entry type handles
     * @return array Converted entry types with deprecation warnings
     * @since 4.0.0
     */
    public static function convertLegacyEntryTypes(array $entryTypes): array
    {
        $converted = [];
        $warnings = [];

        foreach ($entryTypes as $entryType) {
            if (!empty($entryType) && !str_contains($entryType, ':')) {
                $warnings[] = "Entry type '$entryType' uses legacy format. Please update to 'section:entryType' format.";
                // For now, keep the legacy format but mark for future conversion
            }
            $converted[] = $entryType;
        }

        return [
            'entryTypes' => $converted,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate that a value is a positive integer with comprehensive security checks
     *
     * This method prevents various attack vectors including:
     * - Scientific notation (1e10, 1E10, 2.5e3)
     * - Hexadecimal notation (0x1234, 0X1234)
     * - Octal notation (0777, 0123)
     * - Float/decimal numbers (1.5, 1.0)
     * - Negative numbers (-1, -123)
     * - Integer overflow attacks
     * - Type juggling attacks
     * - Leading/trailing whitespace exploitation
     *
     * @param mixed $value The value to validate
     * @param string $fieldName The field name for error messages and logging
     * @param int $minValue Minimum allowed value (default: 1)
     * @param int $maxValue Maximum allowed value (default: PHP_INT_MAX)
     * @return int The validated positive integer
     * @throws BadRequestHttpException If validation fails
     * @since 4.0.0
     */
    public static function validatePositiveInteger(
        mixed $value,
        string $fieldName,
        int $minValue = 1,
        int $maxValue = PHP_INT_MAX
    ): int {
        // 1. Type validation - reject non-scalar values and booleans
        if (!is_scalar($value) || is_bool($value)) {
            self::logSecurityViolation('non_scalar_input', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: must be a number',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 2. String conversion and trimming (safe since we know it's scalar)
        $stringValue = trim((string)$value);

        // 3. Empty value check
        if ($stringValue === '' || $stringValue === '0') {
            self::logSecurityViolation('empty_or_zero_input', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: must be greater than zero',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 4. Format validation - only allow positive integers without leading zeros
        // This regex prevents: scientific notation, hex, octal, decimals, negatives
        if (!preg_match('/^[1-9]\d*$/', $stringValue)) {
            self::logSecurityViolation('invalid_format', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: must be a positive integer',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 5. Length validation - prevent DoS attacks with extremely long strings
        // PHP_INT_MAX is 19 digits on 64-bit systems, 10 digits on 32-bit systems
        $maxLength = PHP_INT_SIZE === 8 ? 19 : 10;
        if (strlen($stringValue) > $maxLength) {
            self::logSecurityViolation('excessive_length', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: value too large',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 6. Safe integer conversion
        $intValue = (int)$stringValue;

        // 7. Overflow detection - ensure conversion was accurate
        if ((string)$intValue !== $stringValue) {
            self::logSecurityViolation('integer_overflow', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: value too large',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 8. Range validation
        if ($intValue < $minValue || $intValue > $maxValue) {
            self::logSecurityViolation('out_of_range', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: must be between {min} and {max}',
                    [
                        'fieldName' => $fieldName,
                        'min' => $minValue,
                        'max' => $maxValue
                    ]
                )
            );
        }

        return $intValue;
    }

    /**
     * Validate that a value is a non-negative integer (includes zero)
     *
     * Similar security protections as validatePositiveInteger but allows zero.
     *
     * @param mixed $value The value to validate
     * @param string $fieldName The field name for error messages and logging
     * @param int $maxValue Maximum allowed value (default: PHP_INT_MAX)
     * @return int The validated non-negative integer
     * @throws BadRequestHttpException If validation fails
     * @since 4.0.0
     */
    public static function validateNonNegativeInteger(
        mixed $value,
        string $fieldName,
        int $maxValue = PHP_INT_MAX
    ): int {
        // Handle zero as a special case
        if ($value === 0 || $value === '0') {
            return 0;
        }

        // For non-zero values, use the positive integer validator
        return self::validatePositiveInteger($value, $fieldName, 1, $maxValue);
    }

    /**
     * Validate an integer within a specific range (can be negative)
     *
     * Provides secure validation for signed integers within specified bounds.
     *
     * @param mixed $value The value to validate
     * @param string $fieldName The field name for error messages and logging
     * @param int $minValue Minimum allowed value
     * @param int $maxValue Maximum allowed value
     * @return int The validated integer
     * @throws BadRequestHttpException If validation fails
     * @since 4.0.0
     */
    public static function validateIntegerRange(
        mixed $value,
        string $fieldName,
        int $minValue,
        int $maxValue
    ): int {
        // 1. Type validation
        if (!is_scalar($value) || is_bool($value)) {
            self::logSecurityViolation('non_scalar_input', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: must be a number',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 2. String conversion and trimming
        $stringValue = trim((string)$value);

        // 3. Empty value check
        if ($stringValue === '') {
            self::logSecurityViolation('empty_input', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: cannot be empty',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 4. Format validation - allow negative integers but prevent other attacks
        if (!preg_match('/^-?(?:0|[1-9]\d*)$/', $stringValue)) {
            self::logSecurityViolation('invalid_format', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: must be an integer',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 5. Length validation
        $maxLength = PHP_INT_SIZE === 8 ? 20 : 11; // Account for negative sign
        if (strlen($stringValue) > $maxLength) {
            self::logSecurityViolation('excessive_length', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: value too large',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 6. Safe conversion and overflow check
        $intValue = (int)$stringValue;
        if ((string)$intValue !== $stringValue) {
            self::logSecurityViolation('integer_overflow', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: value too large',
                    ['fieldName' => $fieldName]
                )
            );
        }

        // 7. Range validation
        if ($intValue < $minValue || $intValue > $maxValue) {
            self::logSecurityViolation('out_of_range', $fieldName, $value);
            throw new BadRequestHttpException(
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid {fieldName}: must be between {min} and {max}',
                    [
                        'fieldName' => $fieldName,
                        'min' => $minValue,
                        'max' => $maxValue
                    ]
                )
            );
        }

        return $intValue;
    }

    /**
     * Sanitize potentially malicious numeric input for logging
     *
     * Removes or escapes dangerous characters while preserving useful information
     * for security monitoring.
     *
     * @param mixed $value The value to sanitize
     * @return string Sanitized value safe for logging
     * @since 4.0.0
     */
    public static function sanitizeForLogging(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '[' . gettype($value) . ']';
        }

        $stringValue = (string)$value;

        // Truncate extremely long values
        if (strlen($stringValue) > 100) {
            $stringValue = substr($stringValue, 0, 97) . '...';
        }

        // Escape control characters and null bytes
        $stringValue = addcslashes($stringValue, "\x00..\x1F\x7F");

        return $stringValue;
    }

    /**
     * Log security validation violations for monitoring
     *
     * Records suspicious input validation failures for security monitoring
     * without exposing sensitive information.
     *
     * @param string $violationType Type of violation detected
     * @param string $fieldName Field that failed validation
     * @param mixed $value The invalid value (will be sanitized)
     * @since 4.0.0
     */
    private static function logSecurityViolation(
        string $violationType,
        string $fieldName,
        mixed $value
    ): void {
        $sanitizedValue = self::sanitizeForLogging($value);
        $request = Craft::$app->getRequest();
        
        $context = [
            'violationType' => $violationType,
            'fieldName' => $fieldName,
            'sanitizedValue' => $sanitizedValue,
            'userAgent' => $request->getUserAgent() ?? 'unknown',
            'ipAddress' => $request->getUserIP() ?? 'unknown',
        ];

        // Add user context if available
        $user = Craft::$app->getUser()->getIdentity();
        if ($user) {
            $context['userId'] = $user->id;
            $context['username'] = $user->username;
        }

        Craft::warning(
            'Numeric validation security violation: {violationType} for field {fieldName} with value: {sanitizedValue}',
            SearchWithElastic::PLUGIN_HANDLE,
            $context
        );
    }
}

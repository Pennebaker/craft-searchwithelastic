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

use Craft;
use pennebaker\searchwithelastic\models\IndexableElementModel;

/**
 * IndexableElementModelException represents exceptions related to indexable element model operations.
 *
 * This exception is thrown when operations on IndexableElementModel encounter errors such as:
 * - Missing required plugins for specific element types
 * - Unsupported element types
 * - Elements that cannot be found or retrieved
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableElementModelException extends SearchWithElasticException
{
    /**
     * Error code for when Craft Commerce plugin is not installed but required
     * @since 4.0.0
     */
    public const CRAFT_COMMERCE_NOT_INSTALLED = 1001;

    /**
     * Error code for when Digital Products plugin is not installed but required
     * @since 4.0.0
     */
    public const DIGITAL_PRODUCTS_NOT_INSTALLED = 1002;

    /**
     * Error code for when an unexpected or unsupported element type is encountered
     * @since 4.0.0
     */
    public const UNEXPECTED_TYPE = 1003;

    /**
     * Error code for when an element cannot be found or retrieved
     * @since 4.0.0
     */
    public const ELEMENT_NOT_FOUND = 1004;

    /**
     * @var IndexableElementModel|null The element model that caused this exception
     * @since 4.0.0
     */
    public ?IndexableElementModel $elementModel = null;

    /**
     * Constructor
     *
     * @param IndexableElementModel|null $elementModel The element model that caused this exception
     * @param int $code The error code (use class constants)
     * @param string|null $message Custom error message (will be generated if null)
     * @param \Throwable|null $previous Previous exception
     * @since 4.0.0
     */
    public function __construct(
        ?IndexableElementModel $elementModel = null,
        int $code = 0,
        ?string $message = null,
        ?\Throwable $previous = null
    ) {
        $this->elementModel = $elementModel;

        // Generate message if not provided
        if ($message === null) {
            $message = $this->generateMessage($code, $elementModel);
        }

        parent::__construct(
            $message,
            $code,
            $previous,
            'search-with-elastic.element-model',
            $this->buildContext($elementModel, $code)
        );
    }

    /**
     * Generate appropriate error message based on error code and element model
     *
     * @param int $code Error code
     * @param IndexableElementModel|null $elementModel Element model context
     * @return string Generated error message
     * @since 4.0.0
     */
    private function generateMessage(int $code, ?IndexableElementModel $elementModel): string
    {
        $elementInfo = $elementModel ? " (ID: $elementModel->elementId, Type: $elementModel->type, Site: $elementModel->siteId)" : '';

        return match ($code) {
            self::CRAFT_COMMERCE_NOT_INSTALLED => "Craft Commerce plugin is required to index product elements but is not installed $elementInfo",
            self::DIGITAL_PRODUCTS_NOT_INSTALLED => "Digital Products plugin is required to index digital product elements but is not installed $elementInfo",
            self::UNEXPECTED_TYPE => "Unsupported element type encountered during indexing $elementInfo",
            self::ELEMENT_NOT_FOUND => "Element could not be found or retrieved for indexing $elementInfo",
            default => "Element model operation failed $elementInfo",
        };
    }

    /**
     * Build context array for logging
     *
     * @param IndexableElementModel|null $elementModel Element model context
     * @param int $code Error code
     * @return array Context data
     * @since 4.0.0
     */
    private function buildContext(?IndexableElementModel $elementModel, int $code): array
    {
        $context = ['errorCode' => $code];

        if ($elementModel !== null) {
            $context['elementModel'] = [
                'elementId' => $elementModel->elementId,
                'type' => $elementModel->type,
                'siteId' => $elementModel->siteId,
            ];
        }

        return $context;
    }

    /**
     * Get user-friendly error message
     *
     * @return string User-friendly error message
     * @since 4.0.0
     */
    public function getUserMessage(): string
    {
        return match ($this->getCode()) {
            self::CRAFT_COMMERCE_NOT_INSTALLED => Craft::t('searchwithelastic', 'Commerce products cannot be indexed because Craft Commerce is not installed.'),
            self::DIGITAL_PRODUCTS_NOT_INSTALLED => Craft::t('searchwithelastic', 'Digital products cannot be indexed because the Digital Products plugin is not installed.'),
            self::UNEXPECTED_TYPE => Craft::t('searchwithelastic', 'This element type is not supported for search indexing.'),
            self::ELEMENT_NOT_FOUND => Craft::t('searchwithelastic', 'The requested element could not be found.'),
            default => Craft::t('searchwithelastic', 'An error occurred while processing the element for search indexing.'),
        };
    }

    /**
     * Get the element model that caused this exception
     *
     * @return IndexableElementModel|null The element model
     * @since 4.0.0
     */
    public function getElementModel(): ?IndexableElementModel
    {
        return $this->elementModel;
    }
}

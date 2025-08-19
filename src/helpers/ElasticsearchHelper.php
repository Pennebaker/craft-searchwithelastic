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

namespace pennebaker\searchwithelastic\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use pennebaker\searchwithelastic\SearchWithElastic;
use pennebaker\searchwithelastic\services\CallbackValidator;
use RuntimeException;
use yii\base\InvalidConfigException;

/**
 * Elasticsearch utility helper functions
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ElasticsearchHelper
{
    /**
     * Text field with keyword multi-field mapping constant
     * Allows both full-text search and exact matching/aggregations
     * 
     * @since 4.0.0
     */
    public const TEXT_WITH_KEYWORD_MAPPING = [
        'type' => 'text',
        'fields' => [
            'keyword' => [
                'type' => 'keyword',
                'ignore_above' => 256
            ]
        ]
    ];

    /**
     * Date field mapping constant
     * @since 4.0.0
     */
    public const DATE_FIELD_MAPPING = [
        'type'   => 'date',
        'format' => 'yyyy-MM-dd HH:mm:ss',
        'store'  => true,
    ];

    /**
     * Boolean field mapping constant
     * @since 4.0.0
     */
    public const BOOLEAN_FIELD_MAPPING = [
        'type'  => 'boolean',
        'store' => true,
    ];

    /**
     * Stored text field mapping constant
     * @since 4.0.0
     */
    public const STORED_TEXT_FIELD_MAPPING = [
        'type'  => 'text',
        'store' => true,
    ];

    /**
     * Keyword field mapping constant
     * @since 4.0.0
     */
    public const KEYWORD_FIELD_MAPPING = [
        'type'  => 'keyword',
        'store' => true,
    ];

    /**
     * Get the mapping of language codes to Elasticsearch analyzers
     *
     * @return array<string, string> Language code => analyzer name mapping
     * @since 4.0.0
     */
    public static function getLanguageAnalyzersMap(): array
    {
        return [
            'ar'    => 'arabic',
            'hy'    => 'armenian',
            'eu'    => 'basque',
            'bn'    => 'bengali',
            'pt-BR' => 'brazilian',
            'bg'    => 'bulgarian',
            'ca'    => 'catalan',
            'cs'    => 'czech',
            'da'    => 'danish',
            'nl'    => 'dutch',
            'pl'    => 'polish',
            'en'    => 'english',
            'fi'    => 'finnish',
            'fr'    => 'french',
            'gl'    => 'galician',
            'de'    => 'german',
            'el'    => 'greek',
            'hi'    => 'hindi',
            'hu'    => 'hungarian',
            'id'    => 'indonesian',
            'ga'    => 'irish',
            'it'    => 'italian',
            'ja'    => 'cjk',
            'ko'    => 'cjk',
            'lv'    => 'latvian',
            'lt'    => 'lithuanian',
            'nb'    => 'norwegian',
            'fa'    => 'persian',
            'pt'    => 'portuguese',
            'ro'    => 'romanian',
            'ru'    => 'russian',
            'uk'    => 'ukrainian',
            'es'    => 'spanish',
            'sv'    => 'swedish',
            'tr'    => 'turkish',
            'th'    => 'thai',
            'zh'    => 'cjk'
        ];
    }

    /**
     * Get the best Elasticsearch analyzer for a given site ID
     *
     * @param int $siteId The site ID to get analyzer for
     * @return string The analyzer name
     * @throws InvalidConfigException If the site ID is invalid
     * @since 4.0.0
     */
    public static function getAnalyzerForSite(int $siteId): string
    {
        $analyzer = 'standard'; // Default analyzer
        $availableAnalyzers = self::getLanguageAnalyzersMap();

        $site = Craft::$app->getSites()->getSiteById($siteId);
        if (!$site) {
            throw new InvalidConfigException("Invalid site ID: $siteId");
        }

        $siteLanguage = $site->language;
        if (array_key_exists($siteLanguage, $availableAnalyzers)) {
            $analyzer = $availableAnalyzers[$siteLanguage];
        } else {
            $localParts = explode('-', $siteLanguage);
            $siteLanguage = $localParts[0];
            if (array_key_exists($siteLanguage, $availableAnalyzers)) {
                $analyzer = $availableAnalyzers[$siteLanguage];
            }
        }

        return $analyzer;
    }

    /**
     * Generate index name for a site with optional prefix
     *
     * @param int $siteId The site ID
     * @param string|null $customPrefix Optional custom prefix, uses plugin setting if null
     * @return string The generated index name
     * @since 4.0.0
     */
    public static function generateIndexName(int $siteId, ?string $customPrefix = null): string
    {
        $instance = SearchWithElastic::getInstance();
        if (!$instance) {
            throw new RuntimeException('SearchWithElastic instance not found');
        }

        $prefix = $customPrefix ?? $instance->getSettings()->indexNamePrefix ?? '';

        $indexName = 'craft-entries_' . $siteId;

        if (!empty($prefix)) {
            $indexName = $prefix . '_' . $indexName;
        }

        return $indexName;
    }

    /**
     * Get the attachment pipeline configuration
     *
     * @return array The pipeline configuration array
     * @since 4.0.0
     */
    public static function getAttachmentPipelineConfig(): array
    {
        return [
            'description' => 'Extract attachment information',
            'processors'  => [
                [
                    'attachment' => [
                        'field'          => 'content',
                        'target_field'   => 'attachment',
                        'indexed_chars'  => -1,
                        'ignore_missing' => true,
                    ],
                    'remove'     => [
                        'field' => 'content',
                    ],
                ],
            ],
        ];
    }


    /**
     * Create a text field mapping with analyzer
     *
     * @param string $analyzer The analyzer to use
     * @return array The text field mapping configuration
     * @since 4.0.0
     */
    public static function createAnalyzedTextFieldMapping(string $analyzer): array
    {
        return [
            'type'     => 'text',
            'analyzer' => $analyzer,
            'store'    => true,
        ];
    }



    /**
     * Create the attachment property mapping
     *
     * @param string $analyzer The analyzer to use for attachment content
     * @return array The attachment mapping configuration
     * @since 4.0.0
     */
    public static function createAttachmentMapping(string $analyzer): array
    {
        return [
            'properties' => [
                'content' => self::createAnalyzedTextFieldMapping($analyzer),
            ],
        ];
    }


    /**
     * Create an extra field configuration for element order/position
     *
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createOrderField(?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) {
                return Entry::find()->positionedBefore($element)->count();
            }
        ];
    }

    /**
     * Create an extra field configuration for accessing element field values
     *
     * @param string $fieldName The field name to access
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createFieldValueAccessor(string $fieldName, ?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                $entry = Craft::$app->entries->getEntryById($element->id);
                if (!$entry || !isset($entry->$fieldName)) {
                    return null;
                }
                
                $fieldValue = $entry->$fieldName;
                
                // Handle null values
                if ($fieldValue === null) {
                    return null;
                }
                
                // If it's an object with common field properties, extract the appropriate value
                if (is_object($fieldValue)) {
                    // Check for common Craft field value properties
                    if (isset($fieldValue->value)) {
                        return $fieldValue->value;
                    }
                    if (isset($fieldValue->handle)) {
                        return $fieldValue->handle;
                    }
                    if (isset($fieldValue->label)) {
                        return $fieldValue->label;
                    }
                    if (isset($fieldValue->title)) {
                        return $fieldValue->title;
                    }
                    if (isset($fieldValue->name)) {
                        return $fieldValue->name;
                    }
                    
                    // If it's a query, get the IDs
                    if ($fieldValue instanceof \craft\elements\db\ElementQuery) {
                        return $fieldValue->ids();
                    }
                    
                    // If we can't extract a simple value, convert to string
                    if (method_exists($fieldValue, '__toString')) {
                        return (string)$fieldValue;
                    }
                    
                    // Last resort - return null to avoid indexing errors
                    return null;
                }
                
                // If it's an array, check if it contains objects and extract values
                if (is_array($fieldValue)) {
                    $values = [];
                    foreach ($fieldValue as $item) {
                        if (is_object($item)) {
                            if (isset($item->value)) {
                                $values[] = $item->value;
                            } elseif (isset($item->handle)) {
                                $values[] = $item->handle;
                            } elseif (isset($item->label)) {
                                $values[] = $item->label;
                            } elseif (method_exists($item, '__toString')) {
                                $values[] = (string)$item;
                            }
                        } else {
                            $values[] = $item;
                        }
                    }
                    return $values;
                }
                
                // For scalar values, return as-is
                return $fieldValue;
            }
        ];
    }

    /**
     * Create an extra field configuration for accessing field's value property
     *
     * @param string $fieldName The field name to access
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createFieldValueProperty(string $fieldName, ?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                $entry = Craft::$app->entries->getEntryById($element->id);
                return $entry?->$fieldName?->value;
            }
        ];
    }

    /**
     * Create an extra field configuration for accessing field's handle property
     *
     * @param string $fieldName The field name to access
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createFieldHandleAccessor(string $fieldName, ?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                $entry = Craft::$app->entries->getEntryById($element->id);
                return $entry?->$fieldName?->handle;
            }
        ];
    }

    /**
     * Create an extra field configuration for collecting titles from related elements
     *
     * @param string $fieldName The relation field name
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createRelationTitlesField(string $fieldName, ?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                if (!isset($element->$fieldName)) {
                    return null;
                }
                $titles = [];
                $relatedElements = $element->$fieldName->all();
                foreach ($relatedElements as $relatedElement) {
                    $titles[] = $relatedElement->title;
                }
                return $titles;
            }
        ];
    }

    /**
     * Create an extra field configuration for formatted date fields
     *
     * @param string $fieldName The date field name
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createFormattedDateField(string $fieldName, ?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? [
                'type' => 'date',
                'format' => 'strict_date_optional_time||epoch_millis'
            ],
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                return $element->$fieldName ? $element->$fieldName->format('c') : null;
            }
        ];
    }

    /**
     * Create an extra field configuration for year extraction with optional limiting
     *
     * @param string $fieldName The date field name
     * @param int|null $yearLimit Optional year limit for grouping older entries
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createYearField(string $fieldName, ?int $yearLimit = null, ?array $mapping = null): array
    {
        $limitYear = null;
        if ($yearLimit) {
            $limitYear = date('Y') - $yearLimit;
        }

        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName, $limitYear) {
                $year = $element->$fieldName ? (int)$element->$fieldName->format('Y') : null;
                if ($limitYear !== null && $year <= $limitYear) {
                    $year = 'Before ' . $limitYear;
                }
                if ($limitYear !== null) {
                    return (string)$year;
                }
                return $year;
            }
        ];
    }

    /**
     * Create an extra field configuration for image field data with configurable subfields
     *
     * @param string $fieldName The image field name
     * @param array $subFields Array of subfield configurations
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createImageField(string $fieldName, array $subFields = [], ?array $mapping = null): array
    {
        // Default to 'nested' type if no mapping is provided to handle arrays of objects properly
        if ($mapping === null) {
            $mapping = ['type' => 'nested'];
        }
        
        return [
            'mapping' => $mapping,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName, $subFields) {
                $entry = Craft::$app->entries->getEntryById($element->id);

                if ($entry && $element->$fieldName) {
                    $images = [];
                    foreach ($entry->$fieldName->all() as $image) {
                        $imageData = [
                            'id' => $image->id,
                            'title' => $image->title,
                            'mimeType' => $image->mimeType,
                            'alt' => $image->alt,
                            'url' => $image->url,
                            'focalPoint' => $image->focalPoint ? [$image->focalPoint['x'], $image->focalPoint['y']] : null,
                            'width' => $image->width,
                            'height' => $image->height,
                            '__typename' => 'assets_Asset',
                        ];

                        // Process custom subfields
                        foreach ($subFields as $subField) {
                            if (!isset($subField['type'], $subField['name'])) {
                                continue;
                            }

                            $fieldData = self::extractSubFieldValue($image, $subField);
                            if ($fieldData !== null) {
                                $imageData[$subField['name']] = $fieldData;
                            }
                        }

                        $images[] = $imageData;
                    }
                    return $images;
                }
                return null;
            }
        ];
    }

    /**
     * Create an extra field configuration for asset field data
     *
     * @param string $fieldName The asset field name
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createAssetField(string $fieldName, ?array $mapping = null): array
    {
        // Default to 'nested' type if no mapping is provided to handle arrays of objects properly
        if ($mapping === null) {
            $mapping = ['type' => 'nested'];
        }
        
        return [
            'mapping' => $mapping,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                $entry = Craft::$app->entries->getEntryById($element->id);

                if ($entry && $element->$fieldName) {
                    $assets = [];
                    foreach ($entry->$fieldName->all() as $asset) {
                        $fileType = pathinfo($asset->filename, PATHINFO_EXTENSION);

                        $assets[] = [
                            'id' => $asset->id,
                            'title' => $asset->title,
                            'url' => $asset->url,
                            'size' => $asset->size,
                            'fileType' => $fileType,
                        ];
                    }
                    return $assets;
                }
                return null;
            }
        ];
    }

    /**
     * Create an extra field configuration for category parent titles
     *
     * @param string $fieldName The category field name
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createCategoryParentField(string $fieldName, ?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                if (!isset($element->$fieldName)) {
                    return null;
                }

                $categories = $element->$fieldName->all();
                $parentTitles = [];

                foreach ($categories as $category) {
                    if ($category->level === 1) {
                        $parentTitles[] = $category->title;
                    } else {
                        $topLevelParent = $category->getAncestors()->level(1)->one();
                        if ($topLevelParent) {
                            $parentTitles[] = $topLevelParent->title;
                        } else {
                            $parentTitles[] = null;
                        }
                    }
                }

                return $parentTitles;
            }
        ];
    }

    /**
     * Create an extra field configuration for category child titles
     *
     * @param string $fieldName The category field name
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createCategoryChildField(string $fieldName, ?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) use ($fieldName) {
                if (!isset($element->$fieldName)) {
                    return null;
                }

                $categories = $element->$fieldName->all();
                $childTitles = [];

                foreach ($categories as $category) {
                    if ($category->level > 1) {
                        $childTitles[] = $category->title;
                    } else {
                        $childTitles[] = null;
                    }
                }

                return $childTitles;
            }
        ];
    }

    /**
     * Create an extra field configuration for element type name generation
     *
     * @param array|null $mapping Optional custom mapping configuration
     * @return array The extra field configuration
     * @since 4.0.0
     */
    public static function createTypeNameField(?array $mapping = null): array
    {
        return [
            'mapping' => $mapping ?? self::KEYWORD_FIELD_MAPPING,
            'highlighter' => (object)[],
            'value' => function (ElementInterface $element) {
                $entry = Craft::$app->entries->getEntryById($element->id);
                if ($entry) {
                    return $entry->section->handle . '_' . $entry->type->handle . '_Entry';
                }
                return null;
            }
        ];
    }

    /**
     * Extract subfield value from an asset based on field configuration
     *
     * @param Asset $asset The asset to extract from
     * @param array $subField The subfield configuration
     * @return mixed The extracted value or null if not found
     * @since 4.0.0
     */
    private static function extractSubFieldValue(Asset $asset, array $subField): mixed
    {
        switch ($subField['type']) {
            case 'field':
                // Extract standard asset field
                if (property_exists($asset, $subField['name'])) {
                    return $asset->{$subField['name']};
                }
                break;

            case 'OptimizedImage':
                // Extract OptimizedImage field data
                if (property_exists($asset, $subField['name'])) {
                    $optimizedImage = $asset->{$subField['name']};
                    if ($optimizedImage) {
                        // Get the class name for typename mapping
                        $optimizedImageClass = get_class($optimizedImage);

                        // Map the class name to typename
                        $typenameMapping = [
                            'nystudio107\imageoptimize\models\OptimizedImage' => $subField['name'] . '_OptimizedImage',
                        ];

                        return [
                            'focalPoint' => $optimizedImage->focalPoint ? [$optimizedImage->focalPoint['x'], $optimizedImage->focalPoint['y']] : null,
                            'src' => $optimizedImage->src,
                            'placeholderWidth' => $optimizedImage->placeholderWidth,
                            'placeholderHeight' => $optimizedImage->placeholderHeight,
                            'placeholderImage' => $optimizedImage->placeholderImage,
                            'placeholderSilhouette' => $optimizedImage->placeholderSilhouette,
                            'colorPalette' => $optimizedImage->colorPalette,
                            '__typename' => $typenameMapping[$optimizedImageClass] ?? null,
                        ];
                    }
                }
                break;

            case 'method':
                // Extract via method call - use safe method invocation
                if (isset($subField['method'])) {
                    $method = $subField['method'];
                    $args = $subField['args'] ?? [];
                    
                    // Use the callback validator for safe method execution
                    $validator = SearchWithElastic::getInstance()->callbackValidator;
                    return $validator->safeMethodCall(
                        $asset,
                        $method,
                        $args,
                        'asset_subfield_extraction',
                        null
                    );
                }
                break;

            case 'property':
                // Extract nested property with dot notation support
                return self::extractNestedProperty($asset, $subField['name']);

            default:
                // Unknown type, return null
                break;
        }

        return null;
    }

    /**
     * Extract nested property value using dot notation
     *
     * @param object $object The object to extract from
     * @param string $path The property path (e.g., 'property.subproperty')
     * @return mixed The extracted value or null if not found
     * @since 4.0.0
     */
    private static function extractNestedProperty(object $object, string $path): mixed
    {
        $parts = explode('.', $path);
        $current = $object;

        foreach ($parts as $part) {
            if (is_object($current)) {
                if (property_exists($current, $part)) {
                    $current = $current->$part;
                } elseif (method_exists($current, $part)) {
                    // Use safe method invocation through validator
                    $validator = new CallbackValidator();
                    $current = $validator->safeMethodCall(
                        $current,
                        $part,
                        [],
                        'nested_property_extraction',
                        null
                    );
                } else {
                    return null;
                }
            } elseif (is_array($current)) {
                if (isset($current[$part])) {
                    $current = $current[$part];
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        return $current;
    }
}
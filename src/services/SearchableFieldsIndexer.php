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

namespace pennebaker\searchwithelastic\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\Tag;
use craft\elements\db\ElementQuery;
use craft\fields\Assets as AssetsField;
use craft\fields\Categories as CategoriesField;
use craft\fields\Country as CountryField;
use craft\fields\Date as DateField;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix as MatrixField;
use craft\fields\Money as MoneyField;
use craft\fields\Table as TableField;
use craft\fields\Tags as TagsField;
use craft\fields\Time as TimeField;
use craft\fields\Users as UsersField;
use craft\helpers\ElementHelper;
use pennebaker\searchwithelastic\events\SearchableFieldExtractionEvent;
use pennebaker\searchwithelastic\events\FieldDataTransformEvent;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\base\Event;

/**
 * SearchableFieldsIndexer Service
 *
 * Leverages CraftCMS's native searchable field functionality to extract
 * and prepare field data for Elasticsearch indexing.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class SearchableFieldsIndexer extends Component
{
    /**
     * @event SearchableFieldExtractionEvent Triggered before extracting searchable fields
     * @since 4.0.0
     */
    const EVENT_BEFORE_EXTRACT_FIELDS = 'beforeExtractFields';
    
    /**
     * @event SearchableFieldExtractionEvent Triggered after extracting searchable fields
     * @since 4.0.0
     */
    const EVENT_AFTER_EXTRACT_FIELDS = 'afterExtractFields';
    
    /**
     * @event FieldDataTransformEvent Triggered when transforming field data
     * @since 4.0.0
     */
    const EVENT_TRANSFORM_FIELD_DATA = 'transformFieldData';

    /**
     * Extract all searchable fields from an element
     *
     * @param ElementInterface $element The element to extract fields from
     * @param array $config Optional configuration for extraction
     * @return array Extracted searchable field data
     * @since 4.0.0
     */
    public function extractSearchableFields(ElementInterface $element, array $config = []): array
    {
        $searchableData = [];
        
        // Get field layout for the element
        $fieldLayout = $element->getFieldLayout();
        
        if (!$fieldLayout) {
            Craft::info(
                "No field layout found for element {$element->id}",
                __METHOD__
            );
            return $searchableData;
        }
        
        // Fire before extract event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_EXTRACT_FIELDS)) {
            $event = new SearchableFieldExtractionEvent([
                'element' => $element,
                'fields' => [],
                'config' => $config
            ]);
            $this->trigger(self::EVENT_BEFORE_EXTRACT_FIELDS, $event);
            
            // Allow event to override extraction
            if ($event->isValid === false) {
                return $event->fields;
            }
        }
        
        // Extract native element attributes
        $searchableData = array_merge(
            $searchableData,
            $this->extractElementAttributes($element)
        );
        
        // Extract custom searchable fields
        $customFields = $this->extractCustomFields($element, $fieldLayout, $config);
        $searchableData = array_merge($searchableData, $customFields);
        
        // Fire after extract event
        if ($this->hasEventHandlers(self::EVENT_AFTER_EXTRACT_FIELDS)) {
            $event = new SearchableFieldExtractionEvent([
                'element' => $element,
                'fields' => $searchableData,
                'config' => $config
            ]);
            $this->trigger(self::EVENT_AFTER_EXTRACT_FIELDS, $event);
            $searchableData = $event->fields;
        }
        
        return $searchableData;
    }
    
    /**
     * Extract native element attributes
     *
     * @param ElementInterface $element
     * @return array
     * @since 4.0.0
     */
    protected function extractElementAttributes(ElementInterface $element): array
    {
        $attributes = [];
        
        // Get searchable attributes defined by the element type
        $searchableAttributes = $element::searchableAttributes();
        
        foreach ($searchableAttributes as $attribute) {
            try {
                $value = $element->$attribute;
                if ($value !== null) {
                    $attributes[$attribute] = [
                        'value' => $value,
                        'keywords' => $this->extractKeywords($value),
                        'type' => 'attribute',
                        'searchable' => true
                    ];
                }
            } catch (\Exception $e) {
                Craft::warning(
                    "Failed to extract attribute {$attribute} from element {$element->id}: " . $e->getMessage(),
                    __METHOD__
                );
            }
        }
        
        return $attributes;
    }
    
    /**
     * Extract custom searchable fields
     *
     * @param ElementInterface $element
     * @param $fieldLayout
     * @param array $config
     * @return array
     * @since 4.0.0
     */
    protected function extractCustomFields(ElementInterface $element, $fieldLayout, array $config = []): array
    {
        $fields = [];
        $includeNonSearchable = $config['includeNonSearchable'] ?? false;
        
        foreach ($fieldLayout->getCustomFields() as $field) {
            // Special handling for Matrix, Neo, and SuperTable fields - always process them to check sub-fields
            $isNeoField = class_exists('\\benf\\neo\\Field') && $field instanceof \benf\neo\Field;
            $isSuperTableField = class_exists('\\verbb\\supertable\\fields\\SuperTableField') && $field instanceof \verbb\supertable\fields\SuperTableField;
            if ($field instanceof MatrixField || $isNeoField || $isSuperTableField) {
                try {
                    // Extract Matrix field data (will check sub-field searchability internally)
                    $fieldData = $this->extractFieldData($element, $field);
                    if ($fieldData !== null && !empty($fieldData['value'])) {
                        // Only include if there are searchable sub-fields with data
                        $fields[$field->handle] = $fieldData;
                    }
                } catch (\Exception $e) {
                    Craft::warning(
                        "Failed to extract matrix field {$field->handle} from element {$element->id}: " . $e->getMessage(),
                        __METHOD__
                    );
                }
                continue;
            }
            
            // For non-Matrix fields, check searchable flag
            if (!$field->searchable && !$includeNonSearchable) {
                continue;
            }
            
            try {
                $fieldData = $this->extractFieldData($element, $field);
                if ($fieldData !== null) {
                    $fields[$field->handle] = $fieldData;
                }
            } catch (\Exception $e) {
                Craft::warning(
                    "Failed to extract field {$field->handle} from element {$element->id}: " . $e->getMessage(),
                    __METHOD__
                );
            }
        }
        
        return $fields;
    }
    
    /**
     * Extract data from a single field
     *
     * @param ElementInterface $element
     * @param FieldInterface $field
     * @return array|null
     * @since 4.0.0
     */
    protected function extractFieldData(ElementInterface $element, FieldInterface $field): ?array
    {
        // Get raw field value
        $value = $element->getFieldValue($field->handle);
        
        if ($value === null) {
            return null;
        }
        
        // Get search keywords using Craft's built-in extraction
        $keywords = '';
        if ($field->searchable) {
            $keywords = $field->getSearchKeywords($value, $element);
        }
        
        // Transform based on field type
        $transformedData = $this->transformFieldData($field, $value, $keywords, $element);
        
        // Fire transform event
        if ($this->hasEventHandlers(self::EVENT_TRANSFORM_FIELD_DATA)) {
            $event = new FieldDataTransformEvent([
                'field' => $field,
                'element' => $element,
                'originalValue' => $value,
                'keywords' => $keywords,
                'transformedData' => $transformedData
            ]);
            $this->trigger(self::EVENT_TRANSFORM_FIELD_DATA, $event);
            $transformedData = $event->transformedData;
        }
        
        return $transformedData;
    }
    
    /**
     * Transform field data for Elasticsearch
     *
     * @param FieldInterface $field
     * @param mixed $value
     * @param string $keywords
     * @param ElementInterface $element
     * @return array
     * @since 4.0.0
     */
    protected function transformFieldData(FieldInterface $field, mixed $value, string $keywords, ElementInterface $element): array
    {
        $baseData = [
            'keywords' => $keywords,
            'field_type' => get_class($field),
            'field_handle' => $field->handle,
            'field_name' => $field->name,
            'searchable' => $field->searchable
        ];
        
        // Handle different field types
        switch (true) {
            case $field instanceof AssetsField:
                $baseData['value'] = $this->transformAssetField($value);
                $baseData['structured_type'] = 'assets';
                break;
                
            case $field instanceof EntriesField:
                $baseData['value'] = $this->transformEntriesField($value);
                $baseData['structured_type'] = 'entries';
                break;
                
            case $field instanceof CategoriesField:
                $baseData['value'] = $this->transformCategoriesField($value);
                $baseData['structured_type'] = 'categories';
                break;
                
            case $field instanceof TagsField:
                $baseData['value'] = $this->transformTagsField($value);
                $baseData['structured_type'] = 'tags';
                break;
                
            case $field instanceof UsersField:
                $baseData['value'] = $this->transformUsersField($value);
                $baseData['structured_type'] = 'users';
                break;
                
            case $field instanceof MatrixField:
                $baseData['value'] = $this->transformMatrixField($value, $element);
                $baseData['structured_type'] = 'matrix';
                break;
                
            case class_exists('\\benf\\neo\\Field') && $field instanceof \benf\neo\Field:
                $baseData['value'] = $this->transformNeoField($value, $element);
                $baseData['structured_type'] = 'neo';
                break;
                
            case $field instanceof TableField:
                $baseData['value'] = $this->transformTableField($value);
                $baseData['structured_type'] = 'table';
                break;
                
            case class_exists('\\verbb\\tablemaker\\fields\\TableMakerField') && $field instanceof \verbb\tablemaker\fields\TableMakerField:
                $baseData['value'] = $this->transformTableMakerField($value);
                $baseData['structured_type'] = 'tablemaker';
                // Override keywords to only include row content, not column metadata
                if (!empty($baseData['value']['rows'])) {
                    $keywords = [];
                    foreach ($baseData['value']['rows'] as $row) {
                        foreach ($row as $cell) {
                            if (!empty($cell) && is_string($cell)) {
                                $keywords[] = $cell;
                            }
                        }
                    }
                    $baseData['keywords'] = implode(' ', $keywords);
                }
                break;
                
            case class_exists('\\verbb\\supertable\\fields\\SuperTableField') && $field instanceof \verbb\supertable\fields\SuperTableField:
                $baseData['value'] = $this->transformSuperTableField($value, $element);
                $baseData['structured_type'] = 'supertable';
                break;
                
            case $field instanceof CountryField:
                $baseData['value'] = $this->transformCountryField($value, $field);
                $baseData['structured_type'] = 'country';
                // Include both code and label in keywords
                if (!empty($baseData['value'])) {
                    $keywords = [];
                    if (!empty($baseData['value']['code'])) {
                        $keywords[] = $baseData['value']['code'];
                    }
                    if (!empty($baseData['value']['label'])) {
                        $keywords[] = $baseData['value']['label'];
                    }
                    $baseData['keywords'] = implode(' ', $keywords);
                }
                break;
                
            case $field instanceof DateField:
            case $field instanceof TimeField:
                $baseData['value'] = $this->transformDateTimeField($value);
                $baseData['structured_type'] = $field instanceof DateField ? 'date' : 'time';
                // Ensure keywords are set for date/time fields
                if (empty($baseData['keywords']) && !empty($baseData['value'])) {
                    $baseData['keywords'] = $baseData['value'];
                }
                break;
                
            case $field instanceof MoneyField:
                $baseData['value'] = $this->transformMoneyField($value);
                $baseData['structured_type'] = 'money';
                // Ensure keywords are set for money fields
                if (empty($baseData['keywords']) && !empty($baseData['value'])) {
                    $keywords = [];
                    if (!empty($baseData['value']['formatted'])) {
                        $keywords[] = $baseData['value']['formatted'];
                    } elseif (!empty($baseData['value']['amount'])) {
                        $keywords[] = $baseData['value']['amount'];
                    }
                    if (!empty($baseData['value']['currency'])) {
                        $keywords[] = $baseData['value']['currency'];
                    }
                    $baseData['keywords'] = implode(' ', $keywords);
                }
                break;
                
            default:
                $baseData['value'] = $this->serializeValue($value);
                $baseData['structured_type'] = 'simple';
                break;
        }
        
        return $baseData;
    }
    
    /**
     * Transform asset field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformAssetField(mixed $value): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $assets = [];
        foreach ($value->all() as $asset) {
            if ($asset instanceof Asset) {
                $assets[] = [
                    'id' => $asset->id,
                    'title' => $asset->title,
                    'filename' => $asset->filename,
                    'url' => $asset->getUrl(),
                    'kind' => $asset->kind,
                    'size' => $asset->size,
                    'width' => $asset->width,
                    'height' => $asset->height,
                    'alt' => $asset->alt
                ];
            }
        }
        
        return $assets;
    }
    
    /**
     * Transform entries field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformEntriesField(mixed $value): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $entries = [];
        foreach ($value->all() as $entry) {
            if ($entry instanceof Entry) {
                $entries[] = [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'slug' => $entry->slug,
                    'uri' => $entry->uri,
                    'sectionHandle' => $entry->section->handle,
                    'typeHandle' => $entry->type->handle
                ];
            }
        }
        
        return $entries;
    }
    
    /**
     * Transform categories field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformCategoriesField(mixed $value): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $categories = [];
        foreach ($value->all() as $category) {
            if ($category instanceof Category) {
                $categories[] = [
                    'id' => $category->id,
                    'title' => $category->title,
                    'slug' => $category->slug,
                    'level' => $category->level,
                    'groupHandle' => $category->group->handle
                ];
            }
        }
        
        return $categories;
    }
    
    /**
     * Transform tags field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformTagsField(mixed $value): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $tags = [];
        foreach ($value->all() as $tag) {
            if ($tag instanceof Tag) {
                $tags[] = [
                    'id' => $tag->id,
                    'title' => $tag->title,
                    'slug' => $tag->slug,
                    'groupHandle' => $tag->group->handle
                ];
            }
        }
        
        return $tags;
    }
    
    /**
     * Transform users field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformUsersField(mixed $value): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $users = [];
        foreach ($value->all() as $user) {
            if ($user instanceof User) {
                $users[] = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'fullName' => $user->fullName,
                    'firstName' => $user->firstName,
                    'lastName' => $user->lastName
                ];
            }
        }
        
        return $users;
    }
    
    /**
     * Transform matrix field value
     *
     * @param mixed $value
     * @param ElementInterface $parentElement
     * @return array|null
     * @since 4.0.0
     */
    protected function transformMatrixField(mixed $value, ElementInterface $parentElement): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $blocks = [];
        $hasSearchableContent = false;
        
        foreach ($value->all() as $block) {
            $blockData = [
                'id' => $block->id,
                'typeHandle' => $block->type->handle,
                'fields' => []
            ];
            
            // Recursively extract ONLY searchable fields from matrix blocks
            $blockFieldLayout = $block->getFieldLayout();
            if ($blockFieldLayout) {
                foreach ($blockFieldLayout->getCustomFields() as $blockField) {
                    // Only process fields marked as searchable
                    if ($blockField->searchable) {
                        $blockFieldData = $this->extractFieldData($block, $blockField);
                        if ($blockFieldData !== null) {
                            $blockData['fields'][$blockField->handle] = $blockFieldData;
                            $hasSearchableContent = true;
                        }
                    }
                }
            }
            
            // Only include block if it has searchable fields
            if (!empty($blockData['fields'])) {
                $blocks[] = $blockData;
            }
        }
        
        // Return null if no searchable content was found
        return $hasSearchableContent ? $blocks : null;
    }
    
    /**
     * Transform Neo field value
     *
     * @param mixed $value
     * @param ElementInterface $parentElement
     * @return array|null
     * @since 4.0.0
     */
    protected function transformNeoField(mixed $value, ElementInterface $parentElement): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $blocks = [];
        $hasSearchableContent = false;
        
        foreach ($value->all() as $block) {
            $blockData = [
                'id' => $block->id,
                'level' => $block->level ?? 1,
                'fields' => []
            ];
            
            // Get type handle (Neo blocks have a getType() method)
            if (method_exists($block, 'getType')) {
                $blockType = $block->getType();
                $blockData['typeHandle'] = $blockType ? $blockType->handle : null;
            }
            
            // Recursively extract ONLY searchable fields from Neo blocks
            $blockFieldLayout = $block->getFieldLayout();
            if ($blockFieldLayout) {
                foreach ($blockFieldLayout->getCustomFields() as $blockField) {
                    // Only process fields marked as searchable
                    if ($blockField->searchable) {
                        $blockFieldData = $this->extractFieldData($block, $blockField);
                        if ($blockFieldData !== null) {
                            $blockData['fields'][$blockField->handle] = $blockFieldData;
                            $hasSearchableContent = true;
                        }
                    }
                }
            }
            
            // Only include block if it has searchable fields
            if (!empty($blockData['fields'])) {
                $blocks[] = $blockData;
            }
        }
        
        // Return null if no searchable content was found
        return $hasSearchableContent ? $blocks : null;
    }
    
    /**
     * Transform table field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformTableField(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rowData = [];
                foreach ($row as $column => $cellValue) {
                    // Skip DateTime objects in tables as per Craft's behavior
                    if (!$cellValue instanceof \DateTime) {
                        $rowData[$column] = $this->serializeValue($cellValue);
                    }
                }
                $rows[] = $rowData;
            }
        }
        
        return $rows;
    }
    
    /**
     * Transform TableMaker field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformTableMakerField(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        
        $result = [
            'columns' => [],
            'rows' => []
        ];
        
        // Extract column info (without width/align metadata for keywords)
        if (isset($value['columns']) && is_array($value['columns'])) {
            foreach ($value['columns'] as $column) {
                if (isset($column['heading'])) {
                    $result['columns'][] = $column['heading'];
                }
            }
        }
        
        // Extract row data
        if (isset($value['rows']) && is_array($value['rows'])) {
            foreach ($value['rows'] as $row) {
                $rowData = [];
                foreach ($row as $key => $cellValue) {
                    if (!empty($cellValue)) {
                        $rowData[$key] = $cellValue;
                    }
                }
                if (!empty($rowData)) {
                    $result['rows'][] = $rowData;
                }
            }
        }
        
        return !empty($result['rows']) ? $result : null;
    }
    
    /**
     * Transform SuperTable field value
     *
     * @param mixed $value
     * @param ElementInterface $parentElement
     * @return array|null
     * @since 4.0.0
     */
    protected function transformSuperTableField(mixed $value, ElementInterface $parentElement): ?array
    {
        if (!$value instanceof ElementQuery) {
            return null;
        }
        
        $rows = [];
        $hasSearchableContent = false;
        
        foreach ($value->all() as $row) {
            $rowData = [
                'id' => $row->id,
                'fields' => []
            ];
            
            // Recursively extract ONLY searchable fields from SuperTable rows
            $rowFieldLayout = $row->getFieldLayout();
            if ($rowFieldLayout) {
                foreach ($rowFieldLayout->getCustomFields() as $rowField) {
                    // Only process fields marked as searchable
                    if ($rowField->searchable) {
                        $rowFieldData = $this->extractFieldData($row, $rowField);
                        if ($rowFieldData !== null) {
                            $rowData['fields'][$rowField->handle] = $rowFieldData;
                            $hasSearchableContent = true;
                        }
                    }
                }
            }
            
            // Only include row if it has searchable fields
            if (!empty($rowData['fields'])) {
                $rows[] = $rowData;
            }
        }
        
        // Return null if no searchable content was found
        return $hasSearchableContent ? $rows : null;
    }
    
    /**
     * Transform country field value
     *
     * @param mixed $value
     * @param CountryField $field
     * @return array|null
     * @since 4.0.0
     */
    protected function transformCountryField(mixed $value, CountryField $field): ?array
    {
        if (empty($value)) {
            return null;
        }
        
        // Handle Country object from Commerce
        if (is_object($value) && class_exists('\\CommerceGuys\\Addressing\\Country\\Country')) {
            if ($value instanceof \CommerceGuys\Addressing\Country\Country) {
                return [
                    'code' => $value->getCountryCode(),
                    'label' => $value->getName()
                ];
            }
        }
        
        // Fallback for string values
        if (is_string($value)) {
            $countries = Craft::$app->getAddresses()->getCountryRepository()->getList(Craft::$app->language);
            $label = $countries[$value] ?? $value;
            
            return [
                'code' => $value,
                'label' => $label
            ];
        }
        
        return null;
    }
    
    /**
     * Transform date/time field value
     *
     * @param mixed $value
     * @return string|null
     * @since 4.0.0
     */
    protected function transformDateTimeField(mixed $value): ?string
    {
        if ($value instanceof \DateTime) {
            return $value->format('c');
        }
        
        return null;
    }
    
    /**
     * Transform money field value
     *
     * @param mixed $value
     * @return array|null
     * @since 4.0.0
     */
    protected function transformMoneyField(mixed $value): ?array
    {
        // Handle null or empty values
        if ($value === null || $value === '') {
            return null;
        }
        
        // Handle craft\fields\data\Money object
        if ($value instanceof \craft\fields\data\Money) {
            // Check if the Money object has a value
            if ($value->value === null || $value->value === '') {
                return null;
            }
            
            return [
                'amount' => (string) $value->value,
                'currency' => $value->currency ?? 'USD'
            ];
        }
        
        // Handle Money\Money object (moneyphp/money library used by Craft)
        if (class_exists('\\Money\\Money') && $value instanceof \Money\Money) {
            // Get the currency code
            $currencyCode = $value->getCurrency()->getCode();
            
            // Format the money value using Craft's formatter
            $formatter = Craft::$app->getFormatter();
            $formattedValue = $formatter->asCurrency($value->getAmount() / 100, $currencyCode);
            
            return [
                'amount' => (string) ($value->getAmount() / 100),  // Convert to decimal
                'currency' => $currencyCode,
                'formatted' => $formattedValue
            ];
        }
        
        // Handle array format (in case it's stored differently)
        if (is_array($value)) {
            if (isset($value['value']) || isset($value['amount'])) {
                return [
                    'amount' => (string) ($value['value'] ?? $value['amount'] ?? ''),
                    'currency' => $value['currency'] ?? 'USD'
                ];
            }
        }
        
        // Handle numeric values
        if (is_numeric($value)) {
            return [
                'amount' => (string) $value,
                'currency' => 'USD'
            ];
        }
        
        return null;
    }
    
    /**
     * Serialize a simple field value for storage
     *
     * @param mixed $value
     * @return mixed
     * @since 4.0.0
     */
    protected function serializeValue(mixed $value): mixed
    {
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return $value->toArray();
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            if ($value instanceof \DateTime) {
                return $value->format('c');
            }
            return null;
        }
        
        if (is_array($value)) {
            return array_map([$this, 'serializeValue'], $value);
        }
        
        return $value;
    }
    
    /**
     * Extract keywords from a value
     *
     * @param mixed $value
     * @return string
     * @since 4.0.0
     */
    protected function extractKeywords(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return (string) $value;
        }
        
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        
        if (is_array($value)) {
            $keywords = [];
            foreach ($value as $item) {
                $itemKeywords = $this->extractKeywords($item);
                if ($itemKeywords !== '') {
                    $keywords[] = $itemKeywords;
                }
            }
            return implode(' ', $keywords);
        }
        
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        
        return '';
    }
    
    /**
     * Get mapping configuration for Elasticsearch
     *
     * @param FieldInterface $field
     * @return array
     * @since 4.0.0
     */
    public function getFieldMapping(FieldInterface $field): array
    {
        // Base mapping for all fields
        $mapping = [
            'properties' => [
                'keywords' => [
                    'type' => 'text',
                    'analyzer' => 'standard'
                ],
                'field_type' => [
                    'type' => 'keyword'
                ],
                'field_handle' => [
                    'type' => 'keyword'
                ],
                'field_name' => [
                    'type' => 'keyword'
                ],
                'searchable' => [
                    'type' => 'boolean'
                ],
                'structured_type' => [
                    'type' => 'keyword'
                ]
            ]
        ];
        
        // Add field-specific mapping for value
        switch (true) {
            case $field instanceof AssetsField:
            case $field instanceof EntriesField:
            case $field instanceof CategoriesField:
            case $field instanceof TagsField:
            case $field instanceof UsersField:
            case $field instanceof MatrixField:
            case class_exists('\\benf\\neo\\Field') && $field instanceof \benf\neo\Field:
                $mapping['properties']['value'] = [
                    'type' => 'nested'
                ];
                break;
                
            case $field instanceof TableField:
                $mapping['properties']['value'] = [
                    'type' => 'object'
                ];
                break;
                
            default:
                $mapping['properties']['value'] = [
                    'type' => 'text',
                    'fields' => [
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256
                        ]
                    ]
                ];
                break;
        }
        
        return $mapping;
    }
}
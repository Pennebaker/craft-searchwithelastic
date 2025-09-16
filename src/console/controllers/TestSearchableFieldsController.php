<?php
/**
 * Search w/Elastic plugin for Craft CMS 4.x
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console as BaseConsole;
use pennebaker\searchwithelastic\SearchWithElastic;
use pennebaker\searchwithelastic\helpers\ElasticsearchHelper;
use yii\console\ExitCode;

/**
 * Test Searchable Fields Controller
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class TestSearchableFieldsController extends Controller
{
    /**
     * @var int The element ID to test
     */
    public ?int $elementId = null;
    
    /**
     * @var int The site ID to test
     */
    public ?int $siteId = null;
    
    /**
     * @var bool Whether to include non-searchable fields
     */
    public bool $includeNonSearchable = false;
    
    /**
     * @var bool Whether to show verbose output
     */
    public bool $verbose = false;
    
    /**
     * @var bool Whether to show combined content output
     */
    public bool $showCombined = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'elementId';
        $options[] = 'siteId';
        $options[] = 'includeNonSearchable';
        $options[] = 'verbose';
        $options[] = 'showCombined';
        return $options;
    }

    /**
     * Test searchable fields extraction for an element
     *
     * @return int
     */
    public function actionExtract(): int
    {
        $this->stdout("Testing Searchable Fields Extraction\n", BaseConsole::FG_CYAN, BaseConsole::BOLD);
        $this->stdout(str_repeat('=', 50) . "\n\n", BaseConsole::FG_CYAN);
        
        // Get element
        if ($this->elementId) {
            $element = Entry::find()
                ->id($this->elementId)
                ->siteId($this->siteId)
                ->one();
        } else {
            // Get first entry
            $element = Entry::find()
                ->siteId($this->siteId)
                ->one();
        }
        
        if (!$element) {
            $this->stderr("No element found\n", BaseConsole::FG_RED);
            return ExitCode::DATAERR;
        }
        
        $this->stdout("Testing element: {$element->title} (ID: {$element->id}, Site: {$element->siteId})\n\n");
        
        // Extract searchable fields
        $indexer = SearchWithElastic::getInstance()->searchableFieldsIndexer;
        
        $startTime = microtime(true);
        
        try {
            $searchableFields = $indexer->extractSearchableFields(
                $element,
                ['includeNonSearchable' => $this->includeNonSearchable]
            );
            
            $extractionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->stdout("Extraction completed in {$extractionTime}ms\n\n", BaseConsole::FG_GREEN);
            
            if (empty($searchableFields)) {
                $this->stdout("No searchable fields found\n", BaseConsole::FG_YELLOW);
            } else {
                $this->stdout("Found " . count($searchableFields) . " searchable fields:\n\n", BaseConsole::FG_GREEN);
                
                foreach ($searchableFields as $handle => $fieldData) {
                    $this->stdout("Field: {$handle}\n", BaseConsole::FG_YELLOW);
                    
                    if ($this->verbose) {
                        $this->stdout("  Type: {$fieldData['field_type']}\n");
                        $this->stdout("  Name: {$fieldData['field_name']}\n");
                        $this->stdout("  Searchable: " . ($fieldData['searchable'] ? 'Yes' : 'No') . "\n");
                        
                        if (!empty($fieldData['keywords'])) {
                            $keywords = substr($fieldData['keywords'], 0, 200);
                            if (strlen($fieldData['keywords']) > 200) {
                                $keywords .= '...';
                            }
                            $this->stdout("  Keywords: {$keywords}\n");
                        }
                        
                        if (isset($fieldData['value'])) {
                            $value = json_encode($fieldData['value'], JSON_PRETTY_PRINT);
                            if (strlen($value) > 500) {
                                $value = substr($value, 0, 500) . '...';
                            }
                            $this->stdout("  Value: {$value}\n");
                        }
                        
                        $this->stdout("\n");
                    }
                }
                
                // Calculate total size
                $totalKeywords = 0;
                foreach ($searchableFields as $fieldData) {
                    if (!empty($fieldData['keywords'])) {
                        $totalKeywords += strlen($fieldData['keywords']);
                    }
                }
                
                $this->stdout("\nSummary:\n", BaseConsole::FG_CYAN, BaseConsole::BOLD);
                $this->stdout("  Total fields extracted: " . count($searchableFields) . "\n");
                $this->stdout("  Total keywords size: " . number_format($totalKeywords) . " bytes\n");
                $this->stdout("  Extraction time: {$extractionTime}ms\n");
                
                // Show combined content if requested
                if ($this->showCombined) {
                    $this->stdout("\n" . str_repeat('=', 50) . "\n", BaseConsole::FG_CYAN);
                    $this->stdout("COMBINED CONTENT OUTPUT\n", BaseConsole::FG_CYAN, BaseConsole::BOLD);
                    $this->stdout(str_repeat('=', 50) . "\n\n", BaseConsole::FG_CYAN);
                    
                    // Build combined content similar to what would go in Elasticsearch
                    $combinedLines = [];
                    
                    // Add title first if it exists
                    if (isset($searchableFields['title'])) {
                        $combinedLines[] = strip_tags($searchableFields['title']['keywords'] ?? '');
                    }
                    
                    // Process all other fields
                    foreach ($searchableFields as $handle => $fieldData) {
                        if ($handle === 'title') continue; // Already added
                        
                        // Handle Matrix, Neo, and SuperTable fields specially - they contain searchable sub-fields
                        $isNeoField = isset($fieldData['field_type']) && str_contains($fieldData['field_type'], 'neo\\Field');
                        $isSuperTableField = isset($fieldData['field_type']) && str_contains($fieldData['field_type'], 'supertable\\fields\\SuperTableField');
                        $isTableMakerField = isset($fieldData['field_type']) && str_contains($fieldData['field_type'], 'tablemaker\\fields\\TableMakerField');
                        
                        if (($fieldData['field_type'] === 'craft\\fields\\Matrix' || $isNeoField || $isSuperTableField) && isset($fieldData['value'])) {
                            // Process Matrix, Neo, or SuperTable fields specially
                            if ($fieldData['field_type'] === 'craft\\fields\\Matrix') {
                                // Processing Matrix field
                            } elseif ($isNeoField) {
                                // Processing Neo field
                            } elseif ($isSuperTableField) {
                                // Processing SuperTable field
                            }
                            
                            // Extract text from Matrix/Neo/SuperTable blocks (searchable sub-fields)
                            // Extract text from Matrix/Neo/SuperTable blocks (searchable sub-fields)
                            $blockContent = $this->extractBlockContent($fieldData['value']);
                            if (!empty($blockContent)) {
                                $combinedLines[] = $blockContent;
                            }
                        } elseif ($isTableMakerField && isset($fieldData['value'])) {
                            // Format TableMaker as a readable table
                            $tableContent = $this->formatTableMakerContent($fieldData['value']);
                            if (!empty($tableContent)) {
                                $combinedLines[] = $tableContent;
                            }
                        } elseif (!empty($fieldData['keywords'])) {
                            // Regular fields with keywords
                            $cleanContent = strip_tags($fieldData['keywords']);
                            $cleanContent = preg_replace('/\s+/', ' ', trim($cleanContent));
                            
                            if (!empty($cleanContent)) {
                                $combinedLines[] = $cleanContent;
                            }
                        }
                    }
                    
                    // Display the combined content
                    $combinedContent = implode("\n", $combinedLines);
                    
                    $this->stdout("\nCombined searchable content (" . strlen($combinedContent) . " bytes):\n", BaseConsole::FG_YELLOW);
                    $this->stdout(str_repeat('-', 50) . "\n", BaseConsole::FG_CYAN);
                    
                    // Show first 2000 chars or full content if less
                    if (strlen($combinedContent) > 2000) {
                        $this->stdout(substr($combinedContent, 0, 2000) . "\n");
                        $this->stdout("... [truncated - showing first 2000 of " . strlen($combinedContent) . " characters]\n", BaseConsole::FG_YELLOW);
                    } else {
                        $this->stdout($combinedContent . "\n");
                    }
                    
                    $this->stdout(str_repeat('-', 50) . "\n", BaseConsole::FG_CYAN);
                    
                    // Show how this would look as a single Elasticsearch field
                    $this->stdout("\nElasticsearch 'content' field structure:\n", BaseConsole::FG_CYAN, BaseConsole::BOLD);
                    $this->stdout("{\n");
                    $this->stdout("  \"content\": \"" . substr(json_encode($combinedContent), 1, 100) . "...\",\n");
                    $this->stdout("  \"content_length\": " . strlen($combinedContent) . ",\n");
                    $this->stdout("  \"line_count\": " . count($combinedLines) . ",\n");
                    $this->stdout("  \"extraction_method\": \"searchable_fields\"\n");
                    $this->stdout("}\n");
                }
            }
            
        } catch (\Exception $e) {
            $this->stderr("\nExtraction failed: " . $e->getMessage() . "\n", BaseConsole::FG_RED);
            if ($this->verbose) {
                $this->stderr($e->getTraceAsString() . "\n", BaseConsole::FG_RED);
            }
            return ExitCode::SOFTWARE;
        }
        
        return ExitCode::OK;
    }
    
    /**
     * Compare searchable fields extraction with frontend fetching
     *
     * @return int
     */
    public function actionCompare(): int
    {
        $this->stdout("Comparing Searchable Fields vs Frontend Fetching\n", BaseConsole::FG_CYAN, BaseConsole::BOLD);
        $this->stdout(str_repeat('=', 50) . "\n\n", BaseConsole::FG_CYAN);
        
        // Get element
        if ($this->elementId) {
            $element = Entry::find()
                ->id($this->elementId)
                ->siteId($this->siteId)
                ->one();
        } else {
            // Get first entry
            $element = Entry::find()
                ->siteId($this->siteId)
                ->one();
        }
        
        if (!$element) {
            $this->stderr("No element found\n", BaseConsole::FG_RED);
            return ExitCode::DATAERR;
        }
        
        $this->stdout("Comparing element: {$element->title} (ID: {$element->id}, Site: {$element->siteId})\n\n");
        
        // Test searchable fields extraction
        $indexer = SearchWithElastic::getInstance()->searchableFieldsIndexer;
        
        $this->stdout("1. Searchable Fields Extraction:\n", BaseConsole::FG_YELLOW, BaseConsole::BOLD);
        $this->stdout(str_repeat('-', 30) . "\n");
        
        $searchableStart = microtime(true);
        $searchableMemStart = memory_get_usage(true);
        
        try {
            $searchableFields = $indexer->extractSearchableFields(
                $element,
                ['includeNonSearchable' => $this->includeNonSearchable]
            );
            
            $searchableTime = round((microtime(true) - $searchableStart) * 1000, 2);
            $searchableMemory = memory_get_usage(true) - $searchableMemStart;
            
            $searchableSize = 0;
            foreach ($searchableFields as $fieldData) {
                if (!empty($fieldData['keywords'])) {
                    $searchableSize += strlen($fieldData['keywords']);
                }
            }
            
            $this->stdout("  Fields extracted: " . count($searchableFields) . "\n");
            $this->stdout("  Total size: " . number_format($searchableSize) . " bytes\n");
            $this->stdout("  Time: {$searchableTime}ms\n");
            $this->stdout("  Memory: " . number_format($searchableMemory) . " bytes\n");
            
        } catch (\Exception $e) {
            $this->stderr("  Failed: " . $e->getMessage() . "\n", BaseConsole::FG_RED);
            $searchableTime = 0;
            $searchableSize = 0;
        }
        
        $this->stdout("\n");
        
        // Test frontend fetching
        $this->stdout("2. Frontend Fetching:\n", BaseConsole::FG_YELLOW, BaseConsole::BOLD);
        $this->stdout(str_repeat('-', 30) . "\n");
        
        $frontendStart = microtime(true);
        $frontendMemStart = memory_get_usage(true);
        
        try {
            $elasticsearchHelper = new ElasticsearchHelper();
            $frontendContent = $elasticsearchHelper->fetchPageContent($element->url);
            
            $frontendTime = round((microtime(true) - $frontendStart) * 1000, 2);
            $frontendMemory = memory_get_usage(true) - $frontendMemStart;
            $frontendSize = strlen($frontendContent);
            
            $this->stdout("  Content fetched: Yes\n");
            $this->stdout("  Total size: " . number_format($frontendSize) . " bytes\n");
            $this->stdout("  Time: {$frontendTime}ms\n");
            $this->stdout("  Memory: " . number_format($frontendMemory) . " bytes\n");
            
        } catch (\Exception $e) {
            $this->stderr("  Failed: " . $e->getMessage() . "\n", BaseConsole::FG_RED);
            $frontendTime = 0;
            $frontendSize = 0;
        }
        
        // Display comparison
        $this->stdout("\n" . str_repeat('=', 50) . "\n", BaseConsole::FG_CYAN);
        $this->stdout("COMPARISON RESULTS\n", BaseConsole::FG_CYAN, BaseConsole::BOLD);
        $this->stdout(str_repeat('=', 50) . "\n\n", BaseConsole::FG_CYAN);
        
        if ($searchableTime > 0 && $frontendTime > 0) {
            $speedImprovement = round((($frontendTime - $searchableTime) / $frontendTime) * 100, 1);
            $sizeRatio = $frontendSize > 0 ? round(($searchableSize / $frontendSize) * 100, 1) : 0;
            
            $this->stdout("Performance:\n", BaseConsole::FG_GREEN, BaseConsole::BOLD);
            $this->stdout("  Searchable Fields: {$searchableTime}ms\n");
            $this->stdout("  Frontend Fetching: {$frontendTime}ms\n");
            
            if ($speedImprovement > 0) {
                $this->stdout("  → Searchable fields is {$speedImprovement}% faster\n", BaseConsole::FG_GREEN);
            } else {
                $this->stdout("  → Frontend fetching is " . abs($speedImprovement) . "% faster\n", BaseConsole::FG_YELLOW);
            }
            
            $this->stdout("\nContent Size:\n", BaseConsole::FG_GREEN, BaseConsole::BOLD);
            $this->stdout("  Searchable Fields: " . number_format($searchableSize) . " bytes\n");
            $this->stdout("  Frontend Fetching: " . number_format($frontendSize) . " bytes\n");
            $this->stdout("  → Searchable fields captures {$sizeRatio}% of frontend content\n");
        }
        
        return ExitCode::OK;
    }
    
    /**
     * Extract text content from Matrix, Neo, or SuperTable field data
     *
     * @param mixed $blockValue The field value
     * @return string
     */
    protected function extractBlockContent($blockValue): string
    {
        $lines = [];
        
        if (is_array($blockValue)) {
            // Check if this looks like SuperTable data (all blocks have same field structure)
            $isSuperTable = $this->isSuperTableData($blockValue);
            
            if ($isSuperTable) {
                // Format as table with headers
                $headers = [];
                $rows = [];
                
                // First pass: collect all headers from all rows
                foreach ($blockValue as $block) {
                    if (isset($block['fields']) && is_array($block['fields'])) {
                        foreach ($block['fields'] as $fieldHandle => $fieldData) {
                            if (is_array($fieldData) && !empty($fieldData['searchable'])) {
                                // Collect all unique headers from all rows
                                if (!isset($headers[$fieldHandle])) {
                                    $headers[$fieldHandle] = $fieldData['field_name'] ?? $fieldHandle;
                                }
                            }
                        }
                    }
                }
                
                // Second pass: build row data
                foreach ($blockValue as $block) {
                    if (isset($block['fields']) && is_array($block['fields'])) {
                        $rowData = [];
                        
                        foreach ($block['fields'] as $fieldHandle => $fieldData) {
                            if (is_array($fieldData) && !empty($fieldData['searchable'])) {
                                // Get the content
                                $content = $this->extractFieldContent($fieldData);
                                $rowData[$fieldHandle] = $content;
                            }
                        }
                        
                        if (!empty($rowData)) {
                            $rows[] = $rowData;
                        }
                    }
                }
                
                // Build table output
                if (!empty($headers) && !empty($rows)) {
                    // Add headers
                    $lines[] = implode(' ', array_values($headers));
                    
                    // Add rows
                    foreach ($rows as $row) {
                        $rowValues = [];
                        foreach (array_keys($headers) as $fieldHandle) {
                            $rowValues[] = $row[$fieldHandle] ?? '';
                        }
                        $lines[] = implode(' ', $rowValues);
                    }
                }
            } else {
                // Handle Matrix/Neo blocks - extract each field separately
                foreach ($blockValue as $blockIdx => $block) {
                    if (isset($block['fields']) && is_array($block['fields'])) {
                        foreach ($block['fields'] as $fieldHandle => $fieldData) {
                            if (is_array($fieldData)) {
                                
                                // Check if this is a nested SuperTable field (it may not be marked searchable itself)
                                if (isset($fieldData['field_type']) && str_contains($fieldData['field_type'], 'supertable\\fields\\SuperTableField') && isset($fieldData['value'])) {
                                    // Format nested SuperTable as a table
                                    $nestedTableContent = $this->extractBlockContent($fieldData['value']);
                                    if (!empty($nestedTableContent)) {
                                        $lines[] = $nestedTableContent;
                                    }
                                } elseif (!empty($fieldData['searchable'])) {
                                    // Regular searchable field
                                    $content = $this->extractFieldContent($fieldData);
                                    if (!empty($content)) {
                                        $lines[] = $content;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Check if data looks like SuperTable (consistent field structure across blocks)
     *
     * @param array $blockValue
     * @return bool
     */
    protected function isSuperTableData(array $blockValue): bool
    {
        if (empty($blockValue)) {
            return false;
        }
        
        // Matrix/Neo blocks have 'typeHandle' property, SuperTable blocks don't
        // Also, SuperTable blocks should have consistent field structure
        $fieldSets = [];
        $hasTypeHandle = false;
        
        foreach ($blockValue as $block) {
            // Check for Matrix/Neo indicators
            if (isset($block['typeHandle']) || isset($block['level'])) {
                $hasTypeHandle = true;
                break;
            }
            
            // Collect field names for each block
            if (isset($block['fields']) && is_array($block['fields'])) {
                $fieldNames = array_keys($block['fields']);
                sort($fieldNames);
                $fieldSets[] = implode(',', $fieldNames);
            }
        }
        
        // If has typeHandle or level, it's Matrix/Neo, not SuperTable
        if ($hasTypeHandle) {
            return false;
        }
        
        // SuperTable blocks should all have the same field structure
        // Check if all blocks have identical field sets
        if (count($fieldSets) > 0) {
            $uniqueFieldSets = array_unique($fieldSets);
            // If all blocks have the same field structure, it's likely SuperTable
            return count($uniqueFieldSets) === 1;
        }
        
        return false;
    }
    
    /**
     * Extract content from a field data array
     *
     * @param array $fieldData
     * @return string
     */
    protected function extractFieldContent(array $fieldData): string
    {
        $content = '';
        
        // Try to get content from keywords or value
        if (!empty($fieldData['keywords'])) {
            $content = $fieldData['keywords'];
        } elseif (!empty($fieldData['value'])) {
            // Handle different value types
            if (is_string($fieldData['value'])) {
                $content = $fieldData['value'];
            } elseif (is_array($fieldData['value']) || is_object($fieldData['value'])) {
                $content = json_encode($fieldData['value']);
            }
        }
        
        if (!empty($content)) {
            $content = strip_tags($content);
            $content = preg_replace('/\s+/', ' ', trim($content));
        }
        
        return $content;
    }
    
    /**
     * Format TableMaker content as a readable table
     *
     * @param mixed $tableValue The TableMaker field value
     * @return string
     */
    protected function formatTableMakerContent($tableValue): string
    {
        if (!is_array($tableValue)) {
            return '';
        }
        
        $lines = [];
        
        // Add column headers if they exist
        if (isset($tableValue['columns']) && is_array($tableValue['columns'])) {
            $headers = implode(' ', $tableValue['columns']);
            if (!empty($headers)) {
                $lines[] = $headers;
            }
        }
        
        // Add row data
        if (isset($tableValue['rows']) && is_array($tableValue['rows'])) {
            foreach ($tableValue['rows'] as $row) {
                if (is_array($row)) {
                    $rowContent = implode(' ', $row);
                    if (!empty($rowContent)) {
                        $lines[] = $rowContent;
                    }
                }
            }
        }
        
        return implode("\n", $lines);
    }
}
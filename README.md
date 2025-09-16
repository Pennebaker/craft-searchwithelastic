# Search with Elastic

An Elasticsearch integration for Craft CMS 5.x that provides comprehensive search capabilities for your content. This plugin indexes entries, assets, and commerce products while providing fast search results with advanced features like fuzzy matching, faceted filtering, and relevance scoring. Built for scalability, it handles real-time content updates and supports complex queries across large datasets.

## Features

### Core Features
- **Multi-Element Support**: Index entries, assets, categories, and Craft Commerce products
- **Real-Time Indexing**: Automatic indexing when content is created, updated, or deleted
- **Advanced Search**: Powerful search capabilities with highlighting and relevance scoring
- **Flexible Configuration**: Extensive configuration options for fine-tuned control
- **Frontend Content Fetching**: Automatically fetch and index rendered HTML content
- **Asset Text Extraction**: Extract searchable text from supported documents
- **Multi-Site Support**: Full support for Craft's multi-site architecture
- **CP Integration**: Control panel utilities and element sidebar status
- **Developer Friendly**: Rich API, events, and Twig variables for customization

## Requirements

- Craft CMS 5.x
- PHP 8.x
- Elasticsearch 7.x

## Installation

### Via Composer

```bash
composer require pennebaker/craft-searchwithelastic
```

### Via Control Panel

1. Go to **Plugins Store**
2. Search for "Search with Elastic"
3. Click **Install**

## Quick Start

### 1. Configure Elasticsearch Connection

Create a config file at `config/search-with-elastic.php`:


```php
<?php
return [
    'elasticsearchEndpoint' => 'localhost:9200',
    'isAuthEnabled' => false,
    
    // Basic indexing settings
    'assetKinds' => ['pdf', 'text', 'html'],
    'indexableEntryStatuses' => ['live'],
];
```
or
```php
<?php
return [
    'elasticsearchEndpoint' => '$ELASTICSEARCH_ENDPOINT',
    'isAuthEnabled' => true,
    'username' => '$ELASTICSEARCH_USERNAME',
    'password' => '$ELASTICSEARCH_PASSWORD',
    
    // Rate limiting
    'rateLimitingEnabled' => true,
    'rateLimitRequestsPerMinute' => 60,
    'rateLimitBurstSize' => 10,
    
    // Basic indexing settings
    'assetKinds' => ['pdf', 'text', 'html'],
    'indexableEntryStatuses' => ['live'],
];
```

### 2. Test Connection

1. Go to **Settings** → **Plugins** → **Search with Elastic**
2. Configure your Elasticsearch endpoint
3. Click **Test Connection** to verify connectivity

### 3. Index Your Content

Use the CP utility to perform initial indexing:

1. Go to **Utilities** → **Refresh Elasticsearch Index**
2. Click **Reindex All Elements**

### 4. Search in Templates

```twig
{# Basic search #}
{% set results = craft.searchWithElastic.search('search query') %}

{# Display results #}
{% for result in results %}
    <h3>{{ result.title }}</h3>
    <p>{{ result.highlight|raw }}</p>
    <a href="{{ result.url }}">Read more</a>
{% endfor %}

{# AJAX search with CSRF protection #}
<meta name="csrf-token" content="{{ craft.app.request.csrfToken }}">
<script>
fetch('/search-with-elastic/search', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({ query: 'search term' })
});
</script>
```

## Security Highlights

### Query Injection Prevention
All searches use parameterized templates that separate query logic from user input:
```php
// Secure template execution
$results = SearchWithElastic::getInstance()->searchTemplate->executeTemplate(
    SearchTemplates::TEMPLATE_BASIC_SEARCH,
    ['query_text' => $userInput, 'search_fields' => ['title', 'content']],
    $indexName
);
```

## Documentation

View the documentation at **[searchwithelastic.pennebaker.io](https://searchwithelastic.pennebaker.io)**

The documentation covers:
- Installation and configuration
- Template integration and usage
- API reference and development
- Examples and best practices
- Troubleshooting and performance optimization

## Support

- **Documentation**: [searchwithelastic.pennebaker.io](https://searchwithelastic.pennebaker.io)
- **GitHub**: [Repository](https://github.com/pennebaker/craft-searchwithelastic)
- **Issues**: [Report Issues](https://github.com/pennebaker/craft-searchwithelastic/issues)

## License

This plugin is proprietary software developed by Pennebaker.

## Credits

Created by [Pennebaker](https://www.pennebaker.com)

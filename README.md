# Search with Elastic

An Elasticsearch integration for Craft CMS 4.x that provides comprehensive search capabilities for your content. This plugin indexes entries, assets, commerce products, and custom content types while providing fast search results with advanced features like fuzzy matching, faceted filtering, and relevance scoring. Built for scalability, it handles real-time content updates and supports complex queries across large datasets.

## Features

### Core Features
- **Multi-Element Support**: Index entries, assets, categories, and Craft Commerce products
- **Real-Time Indexing**: Automatic indexing when content is created, updated, or deleted
- **Advanced Search**: Powerful search capabilities with highlighting and relevance scoring
- **Flexible Configuration**: Extensive configuration options for fine-tuned control
- **Frontend Content Fetching**: Automatically fetch and index rendered HTML content
- **Asset Text Extraction**: Extract searchable text from PDFs and documents
- **Multi-Site Support**: Full support for Craft's multi-site architecture
- **CP Integration**: Control panel utilities and element sidebar status
- **Developer Friendly**: Rich API, events, and Twig variables for customization

## Requirements

- Craft CMS 4.0+
- PHP 8.0+
- Elasticsearch 7.x
- `yiisoft/yii2-elasticsearch` package (included)

## Installation

### Via Composer

```bash
composer require pennebaker/craft-searchwithelastic
```

### Via Control Panel

1. Go to **Settings** → **Plugins**
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

### Comprehensive Validation
All inputs are validated through multiple security layers before processing.

## Documentation

### Getting Started
- [Installation](docs/getting-started/installation.md) - Setup instructions
- [Quick Start](docs/getting-started/quick-start.md) - Get up and running fast
- [Requirements](docs/getting-started/requirements.md) - System requirements

### Configuration
- [Basic Setup](docs/configuration/basic-setup.md) - Essential configuration
- [Environment Config](docs/configuration/environment-config.md) - Multi-environment setup
- [Index Settings](docs/configuration/index-settings.md) - Index configuration
- [Multi-Site](docs/configuration/multi-site.md) - Multi-site configuration

### Usage
- [Search Implementation](docs/usage/search-implementation.md) - Using search in templates
- [AJAX Endpoints](docs/usage/ajax-endpoints.md) - AJAX search integration
- [Frontend Examples](docs/usage/frontend-examples.md) - JavaScript examples
- [Template Integration](docs/usage/template-integration.md) - Twig integration

### Advanced
- [Security Features](docs/advanced/security.md) - Security documentation
- [Performance](docs/advanced/performance.md) - Performance optimization
- [Troubleshooting](docs/advanced/troubleshooting.md) - Common issues and solutions
- [Advanced Features](docs/advanced/advanced-features.md) - Advanced plugin features

### Development
- [Security API](docs/development/security-api.md) - Security services reference
- [Services API](docs/development/services-api.md) - Service documentation
- [Events](docs/development/events.md) - Event system
- [Extending](docs/development/extending.md) - Plugin extension

## Support

- **Documentation**: [GitHub Repository](https://github.com/pennebaker/craft-searchwithelastic)
- **Issues**: [Report Issues](https://github.com/pennebaker/craft-searchwithelastic/issues)
- **Support**: Contact [Pennebaker](https://www.pennebaker.com)

## License

This plugin is proprietary software developed by Pennebaker.

## Credits

Created by [Pennebaker](https://www.pennebaker.com) - Experts in Craft CMS development and digital experiences.

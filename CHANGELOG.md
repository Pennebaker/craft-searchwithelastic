# pennebaker/craft-searchwithelastic Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [4.1.1] - 2025-09-16

### Fixed
- Parse environment variables for Elasticsearch endpoint and credentials in services

## [4.1.0] - 2025-09-15

### Added
- New `SearchableFieldsIndexer` service that leverages Craft's native searchable field settings for automatic field extraction
- Support for Matrix, Neo, SuperTable, and TableMaker fields with recursive extraction
- Three new events for field extraction customization (`EVENT_BEFORE_EXTRACT_FIELDS`, `EVENT_AFTER_EXTRACT_FIELDS`, `EVENT_TRANSFORM_FIELD_DATA`)
- Console command for testing field extraction (`searchwithelastic/test-searchable-fields`)

### Changed
- Simplified draft and revision handling using Craft's `ElementHelper::isDraftOrRevision()`
- Enhanced error handling and logging in element indexing

## [4.0.0] - 2025-08-14

### Added
- Initial release of Search w/Elastic plugin for Craft CMS

[Unreleased]: https://github.com/pennebaker/craft-searchwithelastic/compare/4.1.1...craft-4
[4.1.1]: https://github.com/pennebaker/craft-searchwithelastic/compare/4.1.0...4.1.1
[4.1.0]: https://github.com/pennebaker/craft-searchwithelastic/compare/4.0.0...4.1.0
[4.0.0]: https://github.com/pennebaker/craft-searchwithelastic/releases/tag/4.0.0

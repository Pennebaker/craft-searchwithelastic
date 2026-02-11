# pennebaker/craft-searchwithelastic Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [5.2.0] - 2026-02-11
### Added
- Dependent re-indexing for related elements when a referenced element is saved.
- Structure re-indexing to keep sibling entries updated when structure order changes.
- New settings: `enableRelationalReindexing`, `enableStructureReindexing`, `dependentReindexBatchLimit`.

### Changed
- Bumped minimum Craft CMS requirement to 5.9.
- Refactored settings access to use static `getPluginSettings()` helper across all services.

## [5.1.2] - 2025-12-30
### Fixed
- Fixed null section access during indexing to prevent “Attempt to read property 'type' on null” when saving entries.

## [5.1.1] - 2025-11-19

### Fixed
- Fix createTypeNameField to match Craft 5 typenames

## [5.1.0] - 2025-09-16

### Added
- Initial release of Search w/Elastic plugin for Craft CMS 5

[Unreleased]: https://github.com/pennebaker/craft-searchwithelastic/compare/5.2.0...craft-5
[5.2.0]: https://github.com/pennebaker/craft-searchwithelastic/releases/tag/5.2.0
[5.1.2]: https://github.com/pennebaker/craft-searchwithelastic/releases/tag/5.1.2
[5.1.1]: https://github.com/pennebaker/craft-searchwithelastic/releases/tag/5.1.1
[5.1.0]: https://github.com/pennebaker/craft-searchwithelastic/releases/tag/5.1.0

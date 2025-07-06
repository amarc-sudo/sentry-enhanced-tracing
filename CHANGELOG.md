# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2024-12-XX

### Added
- Initial release of Sentry Enhanced Tracing Bundle
- **SentryListenerPhasesTracer**: Core listener phase tracking with automatic child span capture
- **SentryUserContextListener**: Enhanced user context and request metadata capture
- **ChangeSentryListenerPriorityPass**: Optimized Sentry listener priority management
- **Automatic child span capture**: Database queries, cache operations, template rendering
- **Performance categorization**: Fast/normal/slow/very_slow classification
- **Rich breadcrumbs**: Detailed execution metrics and performance data
- **Zero-configuration setup**: Works out of the box with minimal setup
- **Symfony 6.x and 7.x support**: Compatible with latest Symfony versions
- **PHP 8.1+ support**: Modern PHP version requirements

### Features
- Hierarchical span organization in Sentry dashboard
- Automatic DBAL query tracing under event phases
- Cache operation monitoring (Redis, APCu, etc.)
- Template rendering performance tracking
- HTTP client request monitoring
- Memory usage and execution time metrics
- Exception context enrichment
- Security-aware PII handling
- Configurable performance thresholds
- High-priority listener execution (99999-99995)

### Documentation
- Comprehensive README with examples
- Configuration guide with best practices
- Installation instructions
- Troubleshooting section
- Performance optimization tips

### Technical Details
- PSR-4 autoloading
- Symfony Bundle architecture
- Dependency injection integration
- Event listener attributes
- Compiler pass optimization
- MIT License

## [0.1.0] - 2024-12-XX

### Added
- Initial development version
- Basic proof of concept
- Core listener implementation
- Sentry integration testing

---

## Legend
- **Added**: New features
- **Changed**: Changes in existing functionality
- **Deprecated**: Soon-to-be removed features
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security improvements 
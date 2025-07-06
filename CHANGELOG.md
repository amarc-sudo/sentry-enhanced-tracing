# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-07-06

### Added
- 🎉 **Initial stable release** of Sentry Enhanced Tracing Bundle
- **SentryListenerPhasesTracer**: Core listener phase tracking with automatic child span capture
- **SentryUserContextListener**: Enhanced user context and request metadata capture with JWT integration
- **ChangeSentryListenerPriorityPass**: Optimized Sentry listener priority management
- **EnhancedUserInterface**: Rich user data interface for better Sentry context
- **JWT Authentication Integration**: Automatic user capture after JWT authentication
- **Automatic child span capture**: Database queries, cache operations, template rendering
- **Performance categorization**: Fast/normal/slow/very_slow classification
- **Rich breadcrumbs**: Detailed execution metrics and performance data
- **Zero-configuration setup**: Works out of the box with minimal setup
- **Symfony 6.x and 7.x support**: Compatible with latest Symfony versions
- **PHP 8.1+ support**: Modern PHP version requirements

### Features
- **Hierarchical span organization** in Sentry dashboard
- **Automatic DBAL query tracing** under event phases
- **Cache operation monitoring** (Redis, APCu, etc.)
- **Template rendering performance tracking**
- **HTTP client request monitoring**
- **Memory usage and execution time metrics**
- **Exception context enrichment**
- **JWT authentication hooks** with `lexik_jwt_authentication.on_jwt_authenticated`
- **Enhanced user data capture** (firstname, lastname, email, UUID)
- **Security-aware PII handling**
- **Configurable performance thresholds**
- **High-priority listener execution** (99999-99995)

### Developer Experience
- **Code Quality Tools**: PHPStan (level 8), Psalm (level 1), PHPCS with PSR-12
- **Automated Quality Assurance**: Composer scripts for phpstan, phpcs, psalm, and qa
- **Strict Type Checking**: Full PHP 8.1+ type coverage
- **Professional Documentation**: Comprehensive examples and configuration guides
- **CI/CD Integration**: GitHub Actions workflows for automated testing
- **Multiple PHP versions support**: Tested on PHP 8.1, 8.2, 8.3

### Dependencies
- **LexikJWTAuthenticationBundle**: Required for JWT authentication integration
- **Symfony Security Bundle**: For user context management
- **Sentry Symfony Bundle**: Core Sentry integration
- **Modern PHP ecosystem**: Compatible with latest Symfony and PHP versions

### Documentation
- **Comprehensive README** with usage examples
- **JWT Integration Guide** with LexikJWTAuthenticationBundle
- **Enhanced User Interface** implementation examples
- **Configuration reference** with all available options
- **CI/CD documentation** for GitHub Actions
- **Installation and setup guides**
- **Performance optimization recommendations**

### Technical Architecture
- **PSR-4 autoloading** with proper namespacing
- **Symfony Bundle architecture** with dependency injection
- **Event listener attributes** for modern Symfony integration
- **Compiler pass optimization** for performance
- **Service configuration** with proper priorities
- **Interface-based design** for extensibility
- **MIT License** for open-source compatibility

### Quality Assurance
- **PHPStan Level 8**: Strictest static analysis
- **Psalm Level 1**: Advanced type checking
- **PHPCS PSR-12**: Coding standards compliance
- **Slevomat Coding Standard**: Additional quality rules
- **Automated testing**: GitHub Actions CI pipeline
- **Security scanning**: Dependency vulnerability checks

## [0.1.0] - 2025-07-01

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
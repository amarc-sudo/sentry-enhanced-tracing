# Sentry Enhanced Tracing Bundle

[![Latest Version](https://img.shields.io/github/release/amarc-sudo/sentry-enhanced-tracing.svg)](https://github.com/amarc-sudo/sentry-enhanced-tracing/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

Enhanced Sentry tracing package with **automatic child span capture** and **listener phase tracking** for Symfony applications.

## 🚀 Key Features

### 🔍 **Automatic Child Span Capture**
- **Database queries** (DBAL) automatically nested under event phases
- **Cache operations** (Redis, APCu) traced hierarchically  
- **Template rendering** (Twig) performance tracking
- **HTTP client** calls monitored automatically
- **Zero configuration** - works out of the box!

### 📊 **Listener Phase Tracking**
- Traces Symfony kernel event phases as Sentry spans
- Measures execution time of all event listeners
- Performance categorization (fast/normal/slow/very_slow)
- Detailed breadcrumbs with execution metrics

### 👤 **Enhanced User Context**
- Automatic user identification in Sentry **after JWT authentication**
- Enhanced user information capture with `EnhancedUserInterface`
- Request/response metadata capture
- Exception context enrichment
- Security-aware PII handling

## 📦 Installation

```bash
composer require amarc-sudo/sentry-enhanced-tracing
```

### 🔧 Requirements

This bundle requires **LexikJWTAuthenticationBundle** to be installed and configured:

```bash
composer require lexik/jwt-authentication-bundle
```

The bundle automatically hooks into JWT authentication events to capture user context **after** successful authentication.

### 📝 Bundle Registration

Add the bundle to your `config/bundles.php`:

```php
return [
    // ... other bundles
    Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle::class => ['all' => true],
    AmarcSudo\SentryEnhancedTracing\SentryEnhancedTracingBundle::class => ['all' => true],
];
```

## 🎯 How It Works

### Automatic Span Hierarchy

The bundle creates a **magical hierarchy** in Sentry by setting event phase spans as "current spans":

```
POST /api/resources (500ms)
├── Event Listeners Phase: kernel.request (50ms)
│   ├── db.query: SELECT users WHERE id=? (15ms)
│   ├── cache.get: user:permissions:123 (2ms)
│   └── security.check (30ms)
├── Event Listeners Phase: kernel.controller (200ms)  
│   ├── db.query: SELECT resources WHERE category=? (80ms)
│   ├── cache.set: resources:category:456 (5ms)
│   └── business.logic (115ms)
└── Event Listeners Phase: kernel.response (100ms)
    ├── twig.render: response.html.twig (85ms)
    └── cache.set: rendered:template:hash (3ms)
```

### 🔧 No Code Changes Required!

The bundle uses **Sentry Hub manipulation** to automatically capture:
- SQL queries from Doctrine DBAL
- Cache operations from Symfony Cache
- Template rendering from Twig
- HTTP client requests
- Redis commands
- Custom integrations

## 📋 What You Get

### 🎭 **Event Listeners**
- `SentryListenerPhasesTracer` - Core phase tracking with child span capture
- `SentryUserContextListener` - Enhanced user context and request metadata

### ⚡ **Performance Optimization**
- `ChangeSentryListenerPriorityPass` - Optimized Sentry listener priorities
- High-priority execution (99999-99995) for proper data capture
- Minimal performance overhead

### 📊 **Rich Metrics**
- Execution time categorization
- Memory usage tracking
- Request/response size analysis
- Performance breadcrumbs
- Exception context enrichment

## 🔧 Configuration

The bundle works **zero-config** but you can customize behavior:

```yaml
# config/packages/sentry_enhanced_tracing.yaml
sentry_enhanced_tracing:
    # Configuration options (if needed)
```

### 🔐 JWT Authentication Integration

The bundle automatically captures user context **after** successful JWT authentication by listening to the `lexik_jwt_authentication.on_jwt_authenticated` event.

### 👤 Enhanced User Interface

To provide richer user context in Sentry, implement the `EnhancedUserInterface` in your User entity:

```php
<?php

namespace App\Entity;

use AmarcSudo\SentryEnhancedTracing\User\EnhancedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface, EnhancedUserInterface
{
    // ... your existing User entity code

    public function getEnhancedFirstname(): ?string
    {
        return $this->firstname;
    }

    public function getEnhancedLastname(): ?string
    {
        return $this->lastname;
    }

    public function getEnhancedEmail(): ?string
    {
        return $this->email;
    }
}
```

This will automatically enrich your Sentry traces with:
- **User ID**: From `getUserIdentifier()` (used as UUID)
- **Full Name**: Combination of first and last name
- **Email**: User's email address
- **Auth Method**: Set to 'jwt' for JWT-authenticated users

## 🎨 Example Output

In your Sentry dashboard, you'll see:

**Transaction:** `POST /api/users/123/resources`  
**Duration:** 450ms  
**Spans:**
- 🔵 Event Listeners Phase: kernel.request (45ms)
  - 🟢 db.query: SELECT * FROM users WHERE id = ? (12ms)
  - 🟠 cache.get: user:123:permissions (3ms)
- 🔵 Event Listeners Phase: kernel.controller (320ms)
  - 🟢 db.query: SELECT * FROM resource WHERE user_id = ? (85ms)
  - 🟠 cache.set: resource:user:123 (5ms)
  - 🟡 business.validation (180ms)
- 🔵 Event Listeners Phase: kernel.response (75ms)
  - 🟣 twig.render: templates/api/resource.json.twig (65ms)

## 🛡️ Security & Privacy

- PII data handling following Sentry best practices
- Configurable data sanitization
- User context capture with privacy controls
- No sensitive data in breadcrumbs by default

## 📄 License

MIT License. See [LICENSE](LICENSE) for details.

## 🤝 Contributing

Contributions welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 🆘 Support

- 📧 Email: aurelien_marc@icloud.com
- 🐛 Issues: [GitHub Issues](https://github.com/amarc-sudo/sentry-enhanced-tracing/issues)
- 📚 Docs: [Documentation](https://github.com/amarc-sudo/sentry-enhanced-tracing/wiki)

---

Made with ❤️ by [Aurelien Marc](https://github.com/amarc-sudo) 
# LiteInertia

[![PHP 8.2+](https://img.shields.io/badge/php-8.2%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()

Zero-dependency [Inertia.js](https://inertiajs.com/) protocol adapter for Slim 4 and PSR-7/PSR-15 applications. Seamlessly bridge your backend into React, Vue, or Svelte without building complex REST/GraphQL APIs or configuring client-side routing.

---

## Key Features

- **Full Inertia.js Protocol Compliance**:
  - Initial browser visit: Renders root HTML view containing `<div id="app" data-page="..."></div>` with escaped JSON.
  - Subsequent client visits (`X-Inertia: true`): Returns JSON payload containing `{ component, props, url, version }`.
- **Shared Props**:
  - Global props (`Inertia::share()`) merged across all page responses (auth user, flash alerts, menu items).
- **Partial Reloads & Lazy Props**:
  - Supports `X-Inertia-Partial-Component` and `X-Inertia-Partial-Data`.
  - Lazy props (`Inertia::lazy(fn() => ...)`) are evaluated **only** when explicitly requested, preventing expensive queries on initial navigation.
- **Asset Versioning**:
  - Automatic `409 Conflict` response with `X-Inertia-Location` header via `InertiaMiddleware` whenever frontend assets change, instructing client to perform a full hard refresh.
- **Micro-Footprint**: Zero external dependencies beyond standard PSR-7 and PSR-15 interfaces.

---

## Installation

```bash
composer require kzxl/lite-inertia
```

---

## Usage Example (Slim 4 + React)

### 1. Root Layout (`views/app.html`)

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inertia App</title>
    <!-- Vite React Build -->
    <script type="module" src="http://localhost:5173/@vite/client"></script>
    <script type="module" src="http://localhost:5173/src/main.jsx"></script>
</head>
<body>
    @inertia
</body>
</html>
```

### 2. Configure Middleware & Controller in Slim 4

```php
use LiteInertia\Inertia;
use LiteInertia\InertiaMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

$inertia = new Inertia(__DIR__ . '/views/app.html');
$inertia->version('v1.0.0');

// Global shared props
$inertia->share('appName', 'LitePlatform');
$inertia->share('auth', fn() => ['user' => 'Admin']);

// Add Inertia Middleware to app
$app->add(new InertiaMiddleware($inertia));

// Controller endpoint
$app->get('/users', function (Request $request, Response $response) use ($inertia, $em) {
    return $inertia->render($response, $request, 'Users/Index', [
        'users' => $em->findAll(User::class),
        // Lazy prop: computed only when requested via partial reload!
        'heavyStats' => $inertia->lazy(fn() => $em->computeStats()),
    ]);
});

$app->run();
```

### 3. Frontend React Component (`src/Pages/Users/Index.jsx`)

```jsx
import React from 'react';
import { Head } from '@inertiajs/react';

export default function Index({ users, appName, auth }) {
    return (
        <div>
            <Head title="Users List" />
            <h1>{appName} - Welcome {auth.user}</h1>
            <ul>
                {users.map(u => (
                    <li key={u.id}>{u.name} ({u.email})</li>
                ))}
            </ul>
        </div>
    );
}
```

---

## Testing

```bash
composer test
```

Runs test suite using PHPUnit 11 with 100% pass rate.

---

## License

MIT License — see [LICENSE](LICENSE) for details.


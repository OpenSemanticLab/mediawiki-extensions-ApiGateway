# ApiGateway

MediaWiki extension that transparently proxies external API endpoints through the MediaWiki REST API, gating all requests through MediaWiki's authentication and permission system. Per-endpoint authentication tokens are injected server-side so they are never exposed to the client.

## Installation

1. Place the `ApiGateway` folder in your `extensions/` directory.
2. Add the following to `LocalSettings.php`:

```php
wfLoadExtension( 'ApiGateway' );
```

## Configuration

### Defining endpoints

Configure external API endpoints in `LocalSettings.php` via `$wgApiGatewayEndpoints`:

```php
$wgApiGatewayEndpoints = [
    'my-api' => [
        'url' => 'https://api.example.com/v1',
        'token' => 'Bearer sk-abc123',
        'tokenHeader' => 'Authorization',      // optional, default: Authorization
        'allowedMethods' => [ 'GET', 'POST' ],  // optional, default: all
        'timeout' => 30,                        // optional, default: 30
    ],
];
```

#### Endpoint options

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `url` | yes | | Base URL of the external API. May include a path prefix. |
| `token` | no | | Auth token value injected into the outbound request header. |
| `tokenHeader` | no | `Authorization` | Header name used for token injection. |
| `allowedMethods` | no | `['GET','POST','PUT','DELETE','PATCH']` | HTTP methods permitted for this endpoint. |
| `timeout` | no | `$wgApiGatewayDefaultTimeout` (30) | Request timeout in seconds. |
| `permission` | no | | Custom permission required instead of the default `apigateway` right. |

### Predefined URL paths

The `url` value can include a fixed path prefix. The `path` query parameter appends to it:

```php
$wgApiGatewayEndpoints = [
    // Full base — caller specifies sub-path
    'dummyjson' => [
        'url' => 'https://dummyjson.com',
    ],

    // Fixed path — no sub-path needed
    'dummyjson-products' => [
        'url' => 'https://dummyjson.com/products',
        'allowedMethods' => [ 'GET' ],
    ],

    // Partial prefix — caller appends the rest
    'openai-chat' => [
        'url' => 'https://api.openai.com/v1/chat',
        'token' => 'Bearer sk-...',
        'allowedMethods' => [ 'POST' ],
    ],
];
```

| Config `url` | `?path=` | Final target |
|---|---|---|
| `https://dummyjson.com` | `/products/1` | `https://dummyjson.com/products/1` |
| `https://dummyjson.com/products` | _(none)_ | `https://dummyjson.com/products` |
| `https://dummyjson.com/products` | `/1` | `https://dummyjson.com/products/1` |
| `https://api.openai.com/v1/chat` | `/completions` | `https://api.openai.com/v1/chat/completions` |

### Permissions

By default, all logged-in users (`user` group) can use the gateway. To restrict access:

```php
// Restrict to sysops only
$wgGroupPermissions['user']['apigateway'] = false;
$wgGroupPermissions['sysop']['apigateway'] = true;
```

Per-endpoint custom permissions:

```php
$wgApiGatewayEndpoints['admin-api'] = [
    'url' => 'https://internal.example.com/api',
    'token' => 'secret-key',
    'tokenHeader' => 'X-API-Key',
    'permission' => 'apigateway-admin',
];

$wgAvailableRights[] = 'apigateway-admin';
$wgGroupPermissions['sysop']['apigateway-admin'] = true;
```

### Global settings

| Variable | Default | Description |
|----------|---------|-------------|
| `$wgApiGatewayEndpoints` | `{}` | Endpoint definitions (see above). |
| `$wgApiGatewayDefaultTimeout` | `30` | Default request timeout in seconds. |
| `$wgApiGatewayMaxBodySize` | `2097152` (2 MB) | Maximum request body size in bytes. |
| `$wgApiGatewayAllowedResponseHeaders` | `['content-type', 'content-length', 'content-disposition', 'etag', 'last-modified', 'cache-control', 'x-request-id']` | Upstream response headers forwarded to the client. |

## Usage

### REST API endpoint

```
{GET|POST|PUT|DELETE|PATCH} /rest.php/apigateway/v1/{endpoint}
```

The HTTP method of the incoming request is forwarded as-is to the external API.

#### Query parameters

| Parameter | Description |
|-----------|-------------|
| `path` | Sub-path appended to the endpoint base URL. |
| `query` | Additional query parameters (JSON object or encoded string). |
| `headers` | Extra request headers (JSON object). Cannot override the injected token header. |
| `token` | CSRF token, **required for POST/PUT/PATCH/DELETE**. Obtain via `action=query&meta=tokens`. |

For POST/PUT/PATCH, the raw request body is forwarded to the external API. The `Content-Type` header is passed through automatically.

The response returns the **actual upstream HTTP status code**, headers, and raw body — no JSON wrapping.

### Examples (curl)

```bash
# GET
curl 'https://wiki.example.com/w/rest.php/apigateway/v1/dummyjson?path=/products/1'

# GET with query parameters
curl 'https://wiki.example.com/w/rest.php/apigateway/v1/dummyjson?path=/products&query=%7B%22limit%22%3A%223%22%7D'

# POST (with CSRF token)
curl -X POST \
  'https://wiki.example.com/w/rest.php/apigateway/v1/dummyjson?path=/products/add&token=CSRF_TOKEN%2B%5C' \
  -H 'Content-Type: application/json' \
  -d '{"title":"New Product","price":9.99}'
```

### Examples (browser console)

Run these in the browser console while logged into the wiki:

```js
// GET request
fetch('/w/rest.php/apigateway/v1/dummyjson?path=/test')
  .then(r => r.json())
  .then(console.log)

// GET with query params
fetch('/w/rest.php/apigateway/v1/dummyjson?path=/products&query=' +
  encodeURIComponent('{"limit":"3","select":"title,price"}'))
  .then(r => r.json())
  .then(console.log)

// POST (fetch CSRF token first, then send)
fetch('/w/api.php?action=query&meta=tokens&format=json', { credentials: 'same-origin' })
  .then(r => r.json())
  .then(data => {
    const token = data.query.tokens.csrftoken;
    return fetch('/w/rest.php/apigateway/v1/dummyjson?path=/products/add&token=' +
      encodeURIComponent(token), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: 'Wiki Product', price: 42.00 })
    });
  })
  .then(r => r.json())
  .then(console.log)
```

#### Reusable helper function

```js
async function apiGateway(endpoint, path, { method = 'GET', body, query } = {}) {
  let url = `/w/rest.php/apigateway/v1/${endpoint}?path=${encodeURIComponent(path)}`;
  if (query) url += '&query=' + encodeURIComponent(JSON.stringify(query));

  const opts = { method, credentials: 'same-origin' };

  if (method !== 'GET') {
    const resp = await fetch('/w/api.php?action=query&meta=tokens&format=json',
      { credentials: 'same-origin' });
    const data = await resp.json();
    url += '&token=' + encodeURIComponent(data.query.tokens.csrftoken);
  }

  if (body) {
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify(body);
  }

  const resp = await fetch(url, opts);
  const text = await resp.text();
  try { return { status: resp.status, data: JSON.parse(text) }; }
  catch { return { status: resp.status, data: text }; }
}

// Usage:
await apiGateway('dummyjson', '/test')
await apiGateway('dummyjson', '/products', { query: { limit: 2 } })
await apiGateway('dummyjson', '/products/add', { method: 'POST', body: { title: 'Test', price: 9.99 } })
```

## Security

- **Authentication**: All requests require a valid MediaWiki session (logged-in user with `apigateway` permission).
- **CSRF protection**: POST, PUT, PATCH, and DELETE require a valid CSRF token.
- **SSRF protection**: Target URLs are validated against private/reserved IP ranges after DNS resolution (127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, etc.).
- **Path traversal prevention**: `..` and `.` segments are stripped from the `path` parameter.
- **Token header protection**: Callers cannot override the injected auth header or the `Host` header via the `headers` parameter.
- **Body size limit**: Configurable maximum request body size (default 2 MB).
- **Method restriction**: Per-endpoint `allowedMethods` limits which HTTP methods are permitted.

## License

GPL-2.0-or-later

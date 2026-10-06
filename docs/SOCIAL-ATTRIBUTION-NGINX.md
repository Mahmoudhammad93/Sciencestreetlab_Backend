# Social Attribution — nginx `/go/` routing (Phase 1B)

Cookie name: `ssl_attr_sid` (no dots — PHP `$_COOKIE` converts `.` to `_`).
Cookie is excluded from Laravel cookie encryption (opaque visitor UUID).

## Why

Production nginx currently proxies `location /` to the React SPA (`science-street-frontend:4173`).
Laravel implements `GET /go/{code}` for first-party tracking redirects. Without an explicit
nginx location, `/go/*` never reaches PHP.

## Production change (applied with Social Attribution Phase 1B deploy)

In `backend/nginx/default.conf`, **before** the frontend `location /` block, add:

```nginx
    # Social Attribution tracking redirects (Laravel) — must precede SPA proxy
    location ^~ /go/ {
        root /var/www/public;
        try_files $uri /index.php?$query_string;

        # Personalized Set-Cookie — do not cache at CDN/edge
        add_header Cache-Control "no-store, private" always;
    }
```

The existing `location ~ \.php$` FastCGI block continues to execute `index.php`.

## Cloudflare

Ensure `/go/*` is not cached as a static SPA asset. Prefer bypass cache for `/go/*` or respect `Cache-Control: no-store`.

## Authorization

Do **not** apply this on production until Phase 1B pre-deploy gate is approved.

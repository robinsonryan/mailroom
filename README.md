# Mailroom

A Laravel package.

## Installation

```bash
composer require robinsonryan/mailroom
```

Publish the config if you need to change it:

```bash
php artisan vendor:publish --tag=mailroom-config
```

## Development

```bash
ddev start
ddev composer quality     # lint:check -> analyze -> refactor:check -> test
ddev composer lint        # fix style
ddev composer refactor    # apply Rector
```

The gate is verify-only — it never rewrites your files.

<h1 align="center">Total Import</h1>

<p align="center">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/LICENSE-GPLv2-5C9E31?style=for-the-badge"></a>
  <a href="https://wpmayor.com"><img alt="Built by" src="https://img.shields.io/badge/BUILT%20BY-WP%20MAYOR-8A2BE2?style=for-the-badge"></a>
</p>

<p align="center"><b>Import CSV, Excel, XML and JSON into WordPress, and keep it in sync when you run the import again.</b></p>

## What it does

- Imports into posts, pages, custom post types, terms, users and WooCommerce products.
- Matches records on a key you choose, so running the import again updates what's there instead of duplicating it.
- Dry runs show what would change before anything is written.
- Every run can be undone.
- No limits on rows, imports or runs.

Developers can extend it through actions and filters, documented in [docs/hooks.md](docs/hooks.md).

## Requirements

WordPress 6.2 or later, PHP 8.0 or later.

## Development

```
composer install
composer test
composer lint
```

## License

GPLv2 or later.

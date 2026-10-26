# Carve Chat Exporters

Render Carve documents as WhatsApp, Slack, Telegram, Discord, or Signal messages. The package uses `markup-carve/carve-php` 0.1.14 or later. See the [Carve documentation](https://markup-carve.github.io/carve/).

## Install

```sh
composer require markup-carve/carve-php-chat
```

## Usage

```php
$flavor = (new \MarkupCarve\Chat\FlavorRegistry())->get("slack");
$converter = \MarkupCarve\Carve\CarveConverter::create(
    renderer: new \MarkupCarve\Chat\ChatRenderer($flavor)
);
echo $converter->convert("# Hello");
```

## Documentation

- [Carve documentation](https://markup-carve.github.io/carve/)
- [Guide](docs/guide.md)
- [Documentation index](docs/README.md)

## License

[MIT](LICENSE).

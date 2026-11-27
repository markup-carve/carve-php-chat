# Carve Chat Exporters

Render Carve 0.2 documents as WhatsApp, Slack, Telegram, Discord, or Signal messages. See the [Carve documentation](https://markup-carve.github.io/carve/).

## Install

Carve 0.1 users stay on the `0.1` branch.

Install it with Composer:

```sh
composer require markup-carve/carve-php-chat:^0.2
```

## Usage

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;

$tree = Carve::parse("= Hello\n");
$writer = new ChatRenderer((new FlavorRegistry())->get('slack'));
echo $writer->render($tree);

$writers = Carve::writers()->register($writer);
echo Carve::writeTo($writer->target(), $tree, [], $writers);
```

For a loss report, call `$writer->renderResult($tree)`. Its result includes the message, losses, and style ranges. The [guide](docs/guide.md) covers flavor choices, includes, and secure previews.

The 0.1 `ChatFlavor::toProfile()` method was removed in 0.2. Flavor definitions now configure the chat writer directly; choose a flavor through `FlavorRegistry` as above.

## License

[MIT](LICENSE).

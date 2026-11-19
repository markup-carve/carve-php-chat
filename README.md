# Carve Chat Exporters

Render Carve 0.2 documents as WhatsApp, Slack, Telegram, Discord, or Signal messages. See the [Carve documentation](https://markup-carve.github.io/carve/).

## Install from the 0.2 branches

The 0.2 packages are not published yet. This branch pins the PHP engine's `next2` commit through Composer's VCS repository. Install this package from its `next2` branch and run `composer update` to fetch the pinned engine.

## Usage

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;

$tree = Carve::parse("# Hello\n");
$writer = new ChatRenderer((new FlavorRegistry())->get('slack'));
echo $writer->render($tree);

$writers = Carve::writers()->register($writer);
echo Carve::writeTo($writer->target(), $tree, [], $writers);
```

For a loss report, call `$writer->renderResult($tree)`. Its result includes the message, losses, and style ranges. The [guide](docs/guide.md) covers flavor choices, includes, and secure previews.

## License

[MIT](LICENSE).

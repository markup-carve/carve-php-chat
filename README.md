# Carve Chat Exporters

Render Carve 0.2 documents as WhatsApp, Slack, Telegram, Discord, or Signal messages. See the [Carve documentation](https://markup-carve.github.io/carve/).

## Install from main

Carve 0.1 users stay on the `0.1` branch.

The 0.2 packages are not published yet. This branch pins a PHP engine commit on `main` through Composer's VCS repository. Composer reads repositories from the root project, so add both repositories there before requiring this package:

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.carve-php vcs https://github.com/markup-carve/carve-php.git
composer config repositories.carve-php-chat vcs https://github.com/markup-carve/carve-php-chat.git
composer require 'markup-carve/carve-php-chat:dev-main'
```

The chat package's dependency constraint selects the pinned engine commit. No registry release is needed.

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

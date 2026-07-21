# Carve Chat Exporters

Render [Carve](https://github.com/markup-carve/carve) documents to chat-platform markup.

WhatsApp, Slack, Telegram and Discord each accept a small, mutually incompatible
subset of Markdown-like markup, with different link syntax, different escaping
rules and different length caps. This package renders a Carve document into any
of them, and tells you what could not survive the trip.

Every platform is a JSON file, not a class. Adding one costs no PHP.

## Install

```bash
composer require markup-carve/carve-php-chat
```

## Usage

```php
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\FlavorRegistry;

$document = CarveConverter::create()->parse($carveSource);
$flavor = (new FlavorRegistry())->get('slack');

echo (new ChatRenderer($flavor))->render($document);
```

`ChatRenderer` implements the core `RendererInterface`, so it also drops straight
into `CarveConverter`:

```php
$converter = CarveConverter::create(renderer: new ChatRenderer($flavor));
echo $converter->convert($carveSource);
```

### Knowing what was lost

Chat formats cannot express most of Carve. `renderResult()` returns the text plus
a record of every degradation, so a UI can warn rather than silently mangle:

```php
$result = (new ChatRenderer($flavor))->renderResult($document);

echo $result->text;

foreach ($result->losses as $loss) {
    printf("line %s: %s\n", $loss->sourceLine ?? '?', $loss->reason);
}
```

## Bundled flavors

| id | notes |
|----|-------|
| `whatsapp` | `*bold*`, `_italic_`, `~strike~`. No link markup at all, so links degrade to `text (url)`. |
| `slack` | mrkdwn. Links are `<url\|text>`. No heading syntax and no list syntax. |
| `telegram-html` | HTML parse mode: `<b> <i> <u> <s> <code> <pre> <a> <blockquote> <tg-spoiler>`. |
| `discord` | Headings and lists are native. Masked links are **not**, in user-typed messages. |
| `discord-bot` | `extends: discord`, with masked links enabled. |
| `signal` | Plain text only - Signal has no text markup at all. See below. |

### Why Discord has two flavors

`[text](url)` does not render in a message a human types into Discord. It renders
in bot API messages, webhook content, embeds, and DMs from a bot. Discord made
that trade-off deliberately, to stop malicious URLs hiding behind innocent text.

So pick `discord` when the output is pasted by a person, and `discord-bot` when
your bot posts it. The two files differ by one key.

### Why Signal emits no markup

Signal does not parse markup in message bodies. Its documentation states that
Markdown "is not supported at this time and is not planned" - formatting is
applied by selecting text in the UI and travels as out-of-band style metadata,
not as delimiters. A typed `*bold*` stays literally `*bold*`.

So the `signal` flavor emits clean plain text and reports every mark it dropped.
The loss report is the point: it tells you exactly which spans to re-apply by
hand after pasting.

This also marks the edge of the current model. Chat targets split into two
families: **delimiter-based** (WhatsApp, Slack, Telegram `parse_mode`, Discord),
where formatting lives in the string, and **range-based** (Signal, Telegram's
`entities` API, Slack Block Kit), where it is plain text plus style offsets.
This package handles the first. Supporting the second would mean an
`"output": "markup" | "ranges"` mode in the schema.

## Custom flavors

A flavor is data. To add Matrix, Zulip, Google Chat or an internal tool, write a
JSON file - no PHP:

```json
{
  "id": "zulip",
  "label": "Zulip",
  "verified": "2026-07-21",
  "limits": { "message": 10000 },
  "link": { "style": "markdown" },
  "escape": { "mechanism": "backslash", "chars": "*_`" },
  "nodes": {
    "paragraph": { "support": "native" },
    "text":      { "support": "native" },
    "strong":    { "support": "native", "open": "**", "close": "**" },
    "emphasis":  { "support": "native", "open": "*",  "close": "*" },
    "code":      { "support": "native", "open": "`",  "close": "`" },
    "link":      { "support": "native" }
  }
}
```

```php
$flavor = (new FlavorRegistry())->fromJsonFile('/path/to/zulip.json');
```

Node types not listed are treated as unsupported and degrade via their fallback.

### Deriving from an existing flavor

`extends` merges a parent, with the child's keys winning, so a tweak is a few
lines rather than a full restatement:

```json
{
  "id": "slack-internal",
  "label": "Slack (internal)",
  "extends": "slack",
  "nodes": {
    "heading": { "support": "none", "fallback": "inline", "template": "*{content}*" }
  }
}
```

## Schema

Keyed by `MarkupCarve\Carve\NodeType` constants.

| field | values |
|-------|--------|
| `support` | `native`, `none` |
| `fallback` | `unwrap`, `inline`, `codeblock`, `appendix`, `drop` |
| `link.style` | `none`, `markdown`, `slackPipe`, `html` |
| `escape.mechanism` | `backslash`, `entities`, `none` |

Template placeholders: `{content}`, `{url}`, `{alt}`, `{title}`, `{hashes}`.

Fallback meanings:

- `unwrap` - emit the children, drop the markup
- `inline` - emit via `template`, e.g. `{alt} ({url})`
- `codeblock` - flatten to a column-aligned monospace block (tables)
- `appendix` - collect and emit at the end, numbered (footnotes)
- `drop` - omit entirely

Each flavor carries a `verified` date, and individual nodes may carry `since`,
because platform syntax changes. Discord added headings and lists in 2023;
Telegram added expandable blockquote in Bot API 7.3.

## Message length

Flavors declare `limits.message`. Exceeding it records a loss; the text is
returned whole and is **not** split. Splitting formatted text correctly (never
inside a fence, never orphaning a `**`) is a separate problem.

## License

MIT

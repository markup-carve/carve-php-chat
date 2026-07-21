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
| `signal` | Range-based: plain text plus style offsets. See below. |
| `telegram-entities` | Range-based variant of Telegram, for the Bot API `entities` field. |

### Why Discord has two flavors

`[text](url)` does not render in a message a human types into Discord. It renders
in bot API messages, webhook content, embeds, and DMs from a bot. Discord made
that trade-off deliberately, to stop malicious URLs hiding behind innocent text.

So pick `discord` when the output is pasted by a person, and `discord-bot` when
your bot posts it. The two files differ by one key.

## Two families of target

Chat platforms carry formatting in one of two ways, and a flavor declares which
with `output`:

- **`markup`** (the default) - formatting lives in the message string as
  delimiters. WhatsApp, Slack, Telegram `parse_mode`, Discord.
- **`ranges`** - the body is plain text and the formatting travels beside it as
  offsets. Signal, Telegram's `entities` field, Slack Block Kit.

Signal is the clearest case: its documentation states that Markdown "is not
supported at this time and is not planned". Formatting is applied by selecting
text in the UI, so a typed `*bold*` stays literally `*bold*` - but the message
still *displays* as bold, because the style rides along separately.

A range-based flavor names a `style` per node instead of delimiters:

```json
"strong": { "support": "native", "style": "BOLD" }
```

A node the target cannot represent natively may still declare a `style`, so it
degrades *and* carries the styling. Signal has no heading syntax, but a heading
should not flatten to unremarkable text:

```json
"heading": { "support": "none", "fallback": "inline", "template": "{content}", "style": "BOLD" }
```

`ChatResult` then carries the spans:

```php
$result = (new ChatRenderer($registry->get('signal')))->renderResult($document);

$result->text;             // "Shipped bold and em." - no delimiters
$result->rangesToArray();  // [['start' => 8, 'length' => 4, 'style' => 'BOLD'], ...]
```

### Offsets are counted in a declared unit

`offsets` is `utf16` (default), `utf8` or `codepoints`. This is not a detail to
guess at - Telegram documents its entity offsets in UTF-16 code units, so a
character outside the BMP counts as two:

| unit | `start` of the bold span in `👍 *bold*` |
|------|------|
| `utf16` | 3 |
| `utf8` | 5 |
| `codepoints` | 2 |

Measuring in the wrong unit shifts every range after the first such character.

### Styles that carry more than a name

Once the delimiters are gone, the body has no room for a URL or a language, so
the range carries them:

```php
$result->rangesToArray();
// [
//   ['start' => 0,  'length' => 4, 'style' => 'text_link', 'url' => 'https://e.com/a'],
//   ['start' => 6,  'length' => 6, 'style' => 'blockquote'],
//   ['start' => 14, 'length' => 7, 'style' => 'pre', 'language' => 'php'],
// ]
```

Ranges are not limited to inline marks: `blockquote` and `pre` cover whole
blocks, so a range-based body needs no `> ` prefix and no fence.

Style names are the target's own, so `rangesToArray()` is close to what its API
wants. Telegram calls the fields `type`, `offset` and `length`, so a caller
renames three keys rather than deriving anything.

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

### Extensions

An extension is addressed by a qualified key, so a flavor maps that one
extension rather than claiming every extension at once. Carve's spoiler is an
extension, and several targets have a real spoiler of their own:

```json
"inline_extension:spoiler": { "support": "native", "open": "||", "close": "||" }
```

Note that `highlight` (`{=mark=}`) is **not** a spoiler. Highlight emphasizes,
a spoiler conceals; mapping one onto the other inverts what the author meant.
No target has a highlight, so it keeps its Carve markup.

### Deriving from an existing flavor

`extends` merges a parent, with the child's keys winning, so a tweak is a few
lines rather than a full restatement. A node entry you declare **replaces** the
inherited one outright, so state it fully - otherwise a key you did not restate
(a parent's `template`, say) would stay in charge:

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
| `fallback` | `unwrap`, `carve`, `inline`, `codeblock`, `appendix`, `drop` |
| `link.style` | `none`, `markdown`, `slackPipe`, `html` |
| `escape.mechanism` | `backslash`, `entities`, `none` |
| `output` | `markup` (default), `ranges` |
| `offsets` | `utf16` (default), `utf8`, `codepoints` - range-based only |
| `style` | style name per node, range-based only (e.g. `BOLD`) |

Template placeholders: `{content}`, `{url}`, `{alt}`, `{title}`, `{hashes}`.

Fallback meanings:

- `unwrap` - emit the children, drop the markup
- `carve` - keep Carve's own delimiters, so an inexpressible mark stays visible
  (`{=highlighted=}`, `{^sup^}`) instead of flattening into ordinary text
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

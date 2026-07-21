# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Added

- Initial release: `ChatRenderer`, `ChatFlavor`, `FlavorRegistry`, `ChatResult`, `Loss`, `Escaper`.
- Bundled flavors: `whatsapp`, `slack`, `telegram-html`, `discord`, `discord-bot`, `signal`, `telegram-entities`.
- `ChatPreviewRenderer`, rendering what a client shows on screen rather than the markup sent to it.
- Range-based output: `output: markup | ranges` and `offsets: utf16 | utf8 | codepoints` in the flavor schema, with `ChatResult::$ranges` carrying `StyleRange` spans, including block ranges (`blockquote`, `pre`) and payloads (`text_link` url, `pre` language).
- Data-driven flavor definitions in `resources/flavors/*.json`, keyed by Carve `NodeType`.
- `carve` fallback, keeping Carve's own delimiters where a target can express nothing.
- Extension-qualified node keys (`inline_extension:spoiler`), mapping Carve's spoiler extension to each target's own spoiler.
- Custom and derived flavors via `extends` and `FlavorRegistry::fromJsonFile()`.

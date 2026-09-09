# Changelog

All notable changes to this project are documented in this file.

## Unreleased

## 0.1.0 - 2026-09-09

### Added

- Initial release: `ChatRenderer`, `ChatFlavor`, `FlavorRegistry`, `ChatResult`, `Loss`, `Escaper`.
- Bundled flavors: `whatsapp`, `slack`, `telegram-html`, `discord`, `discord-bot`, `signal`, `telegram-entities`.
- `ChatPreviewRenderer`, rendering what a client shows on screen rather than the markup sent to it.
- Range-based output: `output: markup | ranges` and `offsets: utf16 | utf8 | codepoints` in the flavor schema, with `ChatResult::$ranges` carrying `StyleRange` spans, including block ranges (`blockquote`, `pre`) and payloads (`text_link` url, `pre` language).
- Data-driven flavor definitions in `resources/flavors/*.json`, keyed by Carve `NodeType`.
- `carve` fallback, keeping Carve's own delimiters where a target can express nothing.
- Extension-qualified node keys (`inline_extension:spoiler`), mapping Carve's spoiler extension to each target's own spoiler.
- Labels for self-naming divs (admonition kinds, `details`/`spoiler` titles, tab `label`), which chat cannot convey as a box.
- Custom and derived flavors via `extends` and `FlavorRegistry::fromJsonFile()`.

### Fixed

- **The bundled flavors cover `citation` and `citation_definition`.** A newer
  carve-php core added these node types, which the flavors did not name, so the
  completeness gate failed against the tracked dev-main core. Both are carried
  now (support `none`, fallback `unwrap`, the same reading `citation_group`
  has).
- **The bundled flavors cover `figure_group`, and a flavor loads on a core
  that predates one of its entries.** carve-php's composite figures added a
  node type the flavors did not name, which failed the completeness test on
  dev-main - while naming it failed validation on the released core, which
  rejects unknown node types. Every flavor carries the entry now
  (`unwrap`, like `figure`), and validation skips a snake_case node type the
  installed core does not know: that is a newer core's type, not a typo -
  the completeness test still fails the moment an entry is genuinely
  missing, and a malformed name still throws.
- **The unwrap fallback keeps block boundaries** (carve-php-chat#1). A
  figure's image, its caption and the next block's text were concatenated
  bare - "HamletLogo (...)The logoRoses are red" - so the words survived but
  the boundaries did not. An unwrapped block node's children are joined with
  blank lines now and the node ends one.
- **A substitution renders as struck old text plus its replacement**
  (carve-php-chat#1). `{~old~>new~}` was concatenated to "oldnew" with no
  separator and no styling. It renders `~old~ new` through the flavor's own
  strike path, so a target without native strike falls back the way an
  authored `~old~` would.

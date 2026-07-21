# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Added

- Initial release: `ChatRenderer`, `ChatFlavor`, `FlavorRegistry`, `ChatResult`, `Loss`, `Escaper`.
- Bundled flavors: `whatsapp`, `slack`, `telegram-html`, `discord`, `discord-bot`, `signal`.
- Data-driven flavor definitions in `resources/flavors/*.json`, keyed by Carve `NodeType`.
- Custom and derived flavors via `extends` and `FlavorRegistry::fromJsonFile()`.

# Changelog

## 1.0.2 - 2026-04-02

### Fixed
- Bundled Alpine.js with the plugin so the built-in booking wizard works out of the box without requiring the site theme to include Alpine separately ([#3](https://github.com/anvildevxyz/craft-booked/issues/3))
- Alpine.js is loaded at `POS_END` to ensure proper initialization order with wizard components
- Added detection to skip loading Alpine.js if the site already includes it

## 1.0.0 - Unreleased

### Added
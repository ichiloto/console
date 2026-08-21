# Changelog

## 0.5.0

Ichiloto Console 0.5.0 brings the Engine 0.5 runtime and Editor 0.5 authoring
toolkit together behind the `ichiloto` command.

### Highlights

- Added `ichiloto validate` for project-wide runtime and authored-data checks.
- Added `ichiloto battle` with arena simulation, deterministic scenarios, and
  human-readable battle reports.
- Added `ichiloto upgrade` for installing save-compatibility metadata in
  projects created before versioned saves.
- Updated new-project scaffolding with stable project identity, save metadata,
  the supported input vocabulary, and Engine 0.5.
- Expanded actor and map generators with safer overwrite handling and richer
  authored output.
- Added FIGlet title-art generation through `ichiloto generate:figlet`.
- Declared the Editor and Engine package chain as runtime dependencies so a
  standard Composer install provides the complete toolchain.

### Requirements

- PHP 8.4 or newer within the PHP 8 release line.
- `ichiloto/editor` 0.5 and its compatible Engine 0.5 runtime.

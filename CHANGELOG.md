# Changelog

## Unreleased

### Added

- `ichiloto upgrade` to format 4: each skit background the dialogue catalogue
  bound (`skits.<id>.background`) moves to that skit's own `backdrop`, found
  by the skit's id, and the catalogue loses its `skits` key, each file in its
  own source (`SkitBackdropStep`). A skit that already names another
  backdrop is refused; the report names a variable only the skits read and
  asks for a place's backdrop to move to its map or region.
- `ichiloto map:render MAP[@X,Y,WIDTH,HEIGHT] ... --out DIR` renders maps for
  review the way the game draws them, both ways: the Terminal field's
  characters and colours as `.ans` and a page, the graphical field as a
  native PNG (and a scaled one with `--scale`), and one `review.html` with
  them side by side. `--player X,Y[,HEADING]` and `--actor ID` stand someone
  on a cell, `--views FILE` gives each view its own region, subject and
  label, and `--changes BATCH` renders a batch before it is applied, writing
  nothing.
### Changed

- `ichiloto play`, `ichiloto battle` and `ichiloto edit` choose what to open
  the same way (`LaunchTargetSelector`). Without a choice flag, an
  interactive terminal lists the choices: the renderers for `play` and
  `battle`, the Terminal editor and the GPUI editor for `edit`. A choice that
  cannot open stays in the list marked unavailable, its whole reason printed
  above it, from the project Engine's renderer report or the GPUI editor's
  build (`GuiEditorLocator`). `--gui` opens the graphical choice directly in
  all three, `--renderer=<id>` (with `auto`) now also works for `edit` with the
  same IDs, `--gpui-renderer` stays as an alias of `--gui` and is accepted by
  `edit` too, and `--gui` with `--renderer=terminal` is refused. A run
  without a terminal, or with `--no-interaction`, still opens the terminal
  choice, and `battle --runs` refuses `--gui` as it refuses `--renderer`.
  An interactive `ichiloto edit` with no flag no longer opens the Terminal
  editor straight away: it asks first. `RendererSelector` is replaced by
  `Launch\LaunchTargetSelector`.

### Fixed

- Commands that open a whole project in their own process (`map:apply`,
  `map:convert-kinds`, `validate`, `edit`, `edit:host`) raise PHP's memory
  limit to the 512M every Ichiloto project process runs with, keeping a
  higher one. Converting Last Legend's 181 x 61 overworld needs about 230 MB
  and ran out of memory under PHP's default 128M.

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

- PHP 8.4.1 or newer within the PHP 8 release line.
- `ichiloto/editor` 0.5 and its compatible Engine 0.5 runtime.

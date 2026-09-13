<div align="center">
    <img src="/public/images/screenshots/ichiloto-logo.png" alt="Ichiloto Logo" width="256" height="256" />
</div>

# Ichiloto Console

The official command-line toolkit for the Ichiloto Engine.

This package powers the `ichiloto` executable: it forges new projects, opens the editor, launches games, and carries a few early scaffolding utilities for day-to-day engine work. The command surface is intentionally small and geared toward the core Ichiloto workflow:

1. Install the CLI
2. Create a project
3. Open the editor
4. Play and test

## Stack

- PHP `^8.4.1`
- Symfony Console
- [Laravel Prompts](https://laravel.com/docs/prompts)
- League CLImate
- `amasiye/figlet` for title art and terminal wordmarks
- `ichiloto/editor` for the project editor and validation commands

## Commands

The CLI currently ships these commands:

- `ichiloto new` for guided project creation with a quest-like interactive flow
- `ichiloto edit` for opening an Ichiloto project in the terminal editor
- `ichiloto play` for running a project's main entrypoint
- `ichiloto upgrade` for adding mandatory save metadata to projects created by older Console versions
- `ichiloto validate` for checking a project's content and save metadata
- `ichiloto generate:figlet` for forging terminal title art, menu banners, and wordmarks
- `ichiloto generate:map` for complete Engine 0.5 map scaffolding
- `ichiloto generate:actor` for lightweight actor scaffolding
- `ichiloto battle` for playing a fight from the arena, or simulating it to balance it

## Getting Started

Install the CLI globally:

```bash
composer global require ichiloto/console
```

Create a new project:

```bash
ichiloto new my-rpg
```

Open the editor:

```bash
cd my-rpg
ichiloto edit
```

Play the project:

```bash
ichiloto play
```

## Renderer Selection

In an interactive terminal, `ichiloto play` asks which renderer to use:

```text
Select renderer

❯ Native Terminal
  GPUI
```

The same choice can be made explicitly for scripts or repeatable launch
commands:

```bash
ichiloto play --renderer=terminal
ichiloto play --renderer=gpui
```

`--gpui-renderer` is a convenience alias for `--renderer=gpui`:

```bash
ichiloto play --gpui-renderer
```

Non-interactive launches default to the native terminal renderer unless a
renderer is selected explicitly:

```bash
ichiloto play --no-interaction
ichiloto play --no-interaction --renderer=gpui
```

Renderer implementation discovery is managed internally. The command-line
interface selects the stable `terminal` or `gpui` identity; it does not accept
an executable location.

This Console change communicates renderer launch intent only. GPUI rendering
also requires companion Engine support that consumes `ICHILOTO_RENDERER` and
resolves the registered implementation; selecting `gpui` does not provide that
runtime integration by itself.

When `play` finds an existing project tmux session, it attaches to the game
that is already running. A renderer option applies when a new game process is
launched and cannot change the renderer of an existing session.

## Project Scaffolding

`ichiloto new` creates a valid Ichiloto project structure, including:

- `ichiloto.json`
- `composer.json`
- a main PHP entrypoint
- `assets/Data` starter files
- a starter actor
- a starter map
- save and log directories

Useful options:

```bash
ichiloto new my-rpg --hero "Arin"
ichiloto new my-rpg --battle-engine active_time
ichiloto new my-rpg --title-font slant
ichiloto new my-rpg --install
ichiloto new my-rpg --no-install
ichiloto new my-rpg --directory /path/to/projects/my-rpg
```

Every new project also gets a generated `assets/Graphics/System/title.txt`, so the title scene starts with a real banner instead of a blank placeholder.

### Balancing a fight

`ichiloto battle` opens the arena so you can play a troop. Give it a run
count instead and it simulates the fight repeatedly and reports what the
fight *is*:

```bash
ichiloto battle --troop "Bat x 2" --runs 100
```

The report opens with the party as fought — each member's level, what each
slot holds by display name and stable id, the permanent growth it carries
with the provenance of each entry, and every canonical stat with its layers,
its cap, and the room left under that cap or what the cap threw away. Then,
per troop: raw wins, losses and unfinished runs beside their shares, average
turns, party health left on a win, and per battler damage, healing, HP lost,
mitigation and how often they fell. The seed is printed because it is what
makes a run repeatable.

Everything the report prints is the engine's own projection. Nothing is
recalculated here, and where the engine does not aggregate something across a
run — miss, critical, Guard and elemental-outcome rates — the report says so
and names what would supply it, rather than inferring a number. One seeded
attack per battler is shown from the simulator's preview seam, labelled as
the single resolved action it is.

The report is a reading of the project, not a rehearsal on it. Everything a
party or a troop holds is recorded before anything runs and put back before
each troop is simulated, before each attacker previews — so every attacker
swings at the same target from the same state — and once more when the
report ends, whether it ends in the last line or in an error. Nothing is
written to the project.

Every line is cut to the terminal it is printed to, measured in the columns
a glyph actually occupies rather than in characters, so a name written in
CJK or carrying an emoji cannot push a line off the side. The report stays
readable at forty columns.

### Upgrading an existing project

Projects created before versioned saves were introduced need a permanent
project id and `assets/Data/save-compatibility.php`. From the project root, run:

```bash
ichiloto upgrade
ichiloto validate
```

The upgrader preserves metadata that already exists. When the id is missing it
uses a canonical Composer package name when available, otherwise it derives
`ichiloto/<project-name>`. Preview the result with `--dry-run`, or choose the
identity explicitly with `--id=vendor/project`. Never change that id after save
files exist.

## FIGlet Generation

Use `ichiloto generate:figlet` whenever you want to reforge title art or create new terminal banners by hand:

```bash
ichiloto generate:figlet "Moonfall Legend"
ichiloto generate:figlet "Moonfall Legend" --style epic
ichiloto generate:figlet "Moonfall Legend" --font slant --output assets/Graphics/System/title.txt
ichiloto generate:figlet --list-fonts
```

The curated `--style` options are tuned for Ichiloto's house look, while `--font` gives you direct access to the installed FIGlet fonts when you want exact control.

## Runtime Notes

- `ichiloto edit` and `ichiloto play` expect to be run inside a valid Ichiloto project directory containing `ichiloto.json`.
- Both commands prefer `tmux` when it is available and the session is interactive, then fall back to direct launch when it is not.
- `ichiloto battle` is not a full battle runner yet; it is still a placeholder command.

## Architecture

The executable entrypoint lives in `bin/ichiloto`.

Key source areas:

- `src/Commands` contains the Symfony Console commands
- `src/Support/NewProjectScaffolder.php` builds fresh project skeletons
- `src/AppConfig.php` reads `ichiloto.json`
- `src/Util` contains working-directory and path helpers

## Local Development

Install the Console dependencies:

```bash
composer install
```

Run `./bin/ichiloto list` as a quick smoke test after dependency changes.

### Sibling checkouts cascade automatically

The published dependencies resolve remotely — `composer.json` declares no
repositories, and `tests/portable-composer-dependencies.php` fails the build
if a local path ever reaches the committed manifest or lock. Working on
local changes needs no configuration at all, because the development
autoload cascades: `autoload-dev` maps `Ichiloto\Editor\`,
`Ichiloto\Engine\`, and `Amasiye\Figlet\` onto the sibling checkouts
(`../editor/src`, `../engine/src`, `../figlet/src/Figlet`), and Composer
consults the root package's paths before a dependency's paths for the same
namespace. A sibling that exists wins; a sibling that does not exist falls
through to the vendored release. A public clone without siblings behaves
exactly like a release install, and `tests/local-sibling-autoload.php`
asserts whichever branch the environment is in.

Two edges the cascade does not cover, because only classes can shadow:

- helper **files** (`Helpers.php`, `Constants.php`) always load from the
  vendored release — a dev sibling's new or changed helper functions are
  not picked up;
- a dev sibling's **new third-party dependency** is not installed here.

To run the released combination while siblings are present, regenerate the
autoloader without the dev cascade: `composer dump-autoload --no-dev`
(plain `composer dump-autoload` restores it).

### Full symlink installs, when the cascade is not enough

For deep dependency work that hits the edges above, use a Composer path
repository in an **uncommitted overlay manifest** so it can never ship.
Copy `composer.json` to `composer.dev.json` (gitignored, as is its lock),
add the local packages, and run Composer against the overlay:

```json
{
    "repositories": [
        { "type": "path", "url": "../editor", "options": { "symlink": true } }
    ],
    "require": {
        "ichiloto/editor": "dev-develop"
    }
}
```

```bash
COMPOSER=composer.dev.json composer update
```

This reinstalls `vendor/` with the symlinked package; run a plain
`composer install` to return to the released dependencies. A path
repository resolves at update time and fails when the directory is absent,
which is why it lives only in the ignored overlay — the committed manifest
stays installable everywhere.

## Project Links

- Engine repository: [github.com/ichiloto/engine](https://github.com/ichiloto/engine)
- Website repository: [github.com/ichiloto/website-v2](https://github.com/ichiloto/website-v2)
- Console issues: [github.com/ichiloto/console/issues](https://github.com/ichiloto/console/issues)

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
- `ichiloto upgrade` for converting a project made for an older engine to the current project format
- `ichiloto validate` for checking a project's content and save metadata
- `ichiloto generate:figlet` for forging terminal title art, menu banners, and wordmarks
- `ichiloto generate:map` for complete Engine 0.5 map scaffolding, created with its kind (`--kind`, one of the project's tilesets; asked when omitted)
- `ichiloto generate:actor` for lightweight actor scaffolding
- `ichiloto battle` for playing a fight from the arena, or simulating it to balance it
- `ichiloto renderer:install` for installing a verified renderer package into a project's Engine (no Rust or build tools required)

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

The Engine consumes `ICHILOTO_RENDERER` and launches the registered platform
implementation. WSL uses the Linux renderer, not a Windows executable.

When `play` finds an existing project tmux session, it attaches to the game
that is already running. A renderer option applies when a new game process is
launched and cannot change the renderer of an existing session.

### Renderer source development

For Engine source checkouts with `resources/renderers/development.json`, a new
graphical `ichiloto play` launch checks whether the declared renderer source has
changed. The check does not build anything. In an interactive terminal, an
available update offers Update now, Continue this launch, or Skip this version.
Continue offers the update again next time; Skip suppresses it until the source
fingerprint changes. Non-interactive launches report the update and continue.
Game PHP and artwork changes do not invalidate the renderer source fingerprint.

Run `ichiloto renderer:update` (or `ichiloto renderer:update <renderer>`) to
build an optimized release package and install it on request. Console verifies
the package before installation and preserves the previous installation if a
build or verification fails. A failed update check or requested update warns but
does not prevent `play` from attempting the selected renderer; the Engine may
still reject a missing or incompatible installed renderer at startup. It never
silently switches to terminal.

This update path is limited to a non-vendored Engine checkout and its declared
renderer checkout. Composer packages, including `--prefer-source` installs inside
the project's `vendor` directory, do not search for source, run Cargo or download
packages. Terminal launches and tmux reattachments skip the check. Direct PHP
entrypoints use the installed renderer without an update check.

`renderer:install` remains available for installing a previously built package.
Automatic delivery of published platform packages is not implemented yet.

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

`ichiloto battle` is a battle test, as in RPG Maker: it opens the arena so you
can set up a party and play a troop. Every fight starts fresh from the setup,
with a new party holding 99 of each item and a new troop. In the arena, go down
from the troop list into the party to change each member's actor, level and
equipment.

The party can also be set up on the command line, one `--member` per member
(up to four), as `Actor[:level][,Slot=item...]`; actors and items go by id or
name. Without `--member` it is the starting party. Anything the party cannot be
built from is refused, every problem named, before a battle starts:

```bash
ichiloto battle --member "Kaelion:20,Weapon=Iron Sword" --member Liora:18 --troop "Great Wolf"
```

A member can also carry a test loadout, so a command, skill or summon can be
tried before the game makes it available:

- `Commands=` replaces the member's command menu, by command id (`attack`,
  `skill`, `magic`, `summon`, `item`, `guard`, `escape`) or the label the
  project shows for it;
- `Skills=` grants abilities or spells from the project's skill catalogue, on
  top of what the member already knows;
- `Summons=` grants summons by id.

Each is a list separated by `|`. The grants exist only in the fresh test
party; they change no campaign progress. Costs, targets and the summon's own
rules still apply, and a summon the member cannot hold is refused like any
other problem. Loadouts are for playing a fight: `--runs` refuses them, because
its simulator has every battler attack and would never use them.

```bash
ichiloto battle --member "Kaelion:20,Commands=attack|skill|summon|item,Summons=djin" --member "Liora:20,Skills=Burn I|Heal I" --troop "Loch Ness"
```

Choose the renderer as `ichiloto play` does, with `--renderer` or
`--gpui-renderer`. Give it a run count instead and it simulates the fight
repeatedly, with the same party, and reports what the fight *is*:

```bash
ichiloto battle --member Kaelion:20 --troop "Bat x 2" --runs 100
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

A project records its format version as `"format"` in `ichiloto.json`; a
project without one is format 0. The game and the editor refuse a project
whose format is not the engine's, and `ichiloto play` and `ichiloto validate`
stop before launching or reading it, printing the engine's explanation. From
the project root, run:

```bash
ichiloto upgrade
ichiloto validate
```

The upgrade needs no arguments. It first lists, one line per pending format,
what will change and how much, then asks before writing anything. It runs
every step from the project's format to the engine's, in order, and records
the new format after each one, so an interrupted upgrade continues where it
stopped. A project that is already current is left untouched.

- `--dry-run` prints the same list and changes nothing.
- `--yes` (`-y`) upgrades without the prompt; without a terminal it is
  required, so a script never converts a project by accident.
- In a Git working tree the upgrade refuses to run over uncommitted changes,
  so it is one reviewable, reversible change. `--allow-dirty` overrides that.
- `--directory` names another project directory, and `--id=vendor/project`
  chooses the save identity when format 1 has to add one.

It ends by printing the follow-up items and writing them to
`ichiloto-upgrade-report.md` in the project. The chain has one step:

1. **Format 1: save metadata.** Adds a permanent project id and
   `assets/Data/save-compatibility.php`, preserving metadata that already
   exists. A missing id comes from a canonical Composer package name when
   there is one, otherwise `ichiloto/<project-name>`. Never change that id
   after save files exist.

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

The `validate --migrate-actor-ids` work on `develop` uses
`Ichiloto\Editor\Actors\ActorIdentityMigration`, which is not in Editor
0.5.1. Before a future Console release includes that command, release a matching
Editor version and verify Console's dependency constraint and a clean install
against it. No new release is implied by the local source checkout.

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

## Contributing and Git workflow

Read [GIT_WORKFLOW.md](GIT_WORKFLOW.md) and install the Git guards with
`sh scripts/install-git-guards.sh` before contributing. All changes integrate
into `develop`; `main` is updated only by a PR from this repository's `develop`.

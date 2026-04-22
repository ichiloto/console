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

- PHP `^8.4`
- Symfony Console
- [Laravel Prompts](https://laravel.com/docs/prompts)
- League CLImate
- `amasiye/figlet` for title art and terminal wordmarks
- `ichiloto/editor` as a local Composer dependency during source development

## Commands

The CLI currently ships these commands:

- `ichiloto new` for guided project creation with a quest-like interactive flow
- `ichiloto edit` for opening an Ichiloto project in the terminal editor
- `ichiloto play` for running a project's main entrypoint
- `ichiloto generate:figlet` for forging terminal title art, menu banners, and wordmarks
- `ichiloto generate:map` for lightweight map scaffolding
- `ichiloto generate:actor` for lightweight actor scaffolding
- `ichiloto battle` as an early development command placeholder

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

If you are working from source, the current setup expects sibling checkouts:

```text
ichiloto/
  engine/
  editor/
  console/
```

Install dependencies from the `console` repo:

```bash
composer install
```

Notes for source development:

- `composer.json` currently resolves `ichiloto/editor` through a local path repository at `../editor`
- the `edit` command also benefits from a sibling `../engine` checkout so editor previews can resolve engine classes cleanly
- FIGlet-backed commands are powered directly by the `amasiye/figlet` package that is now part of this repo's Composer dependencies
- running `./bin/ichiloto list` is the fastest smoke test after dependency changes

## Project Links

- Engine repository: [github.com/ichiloto/engine](https://github.com/ichiloto/engine)
- Website repository: [github.com/ichiloto/website-v2](https://github.com/ichiloto/website-v2)
- Console issues: [github.com/ichiloto/console/issues](https://github.com/ichiloto/console/issues)

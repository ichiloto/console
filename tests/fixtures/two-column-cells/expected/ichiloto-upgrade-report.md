# Ichiloto upgrade report

This project was upgraded from format 1 to format 2. Work through the items below, then run `ichiloto validate`.

## Format 2: square map cells, two terminal columns wide

Changed:

- Regroup 3 maps into two-column cells: 5 of 8 grid files change (29 rows padded to an even width, 1 two-column glyph moved right).
- Halve 22 field x coordinates in 5 files.
- Halve 3 horizontal move route steps (each is listed for review).
- Remove retired tiles2d crops from 1 map.
- Add save migration 2 to 3 (TwoColumnCellsMigration), so saved games reopen in the cell that holds their column.
- Report 2 items that block loading and 18 for review or hand conversion.

### Maps that will not load until fixed

- grove: Event map assets/Maps/grove/grove.event.php row 1 must be 4 cells wide.
- assets/Maps/village/village.event.php row 2, cell 1 holds two different markers, B and C. Keep one marker per cell.

### Two-column glyphs moved right

- assets/Maps/grove/grove.map.php row 1, column 1: a space was inserted before 🌲 so it starts a cell; the rest of the row moved one column right, so check it against the map's other layers.

### NPCs, events and spawn points now on solid cells

- village: event E covers newly solid cells (row 4: cell 0).
- assets/Cutscenes/Cinematics/arrival/arrival.data.php:17 finalizer[0] (transfer) now stands on solid cell (0, 1) of village.

### Values to convert by hand

- assets/Maps/village/village.data.php:39 npcs[?]: x is not an integer literal; halve it by hand (rounding down).
- assets/Maps/village/village.data.php:88 decorations.banner: an x the upgrade could not identify as a field coordinate was left unchanged; halve it if it is one.
- assets/Cutscenes/Cinematics/arrival/arrival.script.php:9 [2].steps[0]: the right step count is not an integer literal; halve it by hand.

### Move route steps to check

- assets/Maps/village/village.data.php:77 events.E.data.script[1].steps[0]: left 3 steps are now 1 cell; the same distance is 1 or 2 cells, depending on the starting column.
- assets/Maps/village/village.data.php:79 events.E.data.script[1].steps[2]: right 1 step is now 1 cell; the same distance is 0 or 1 cells, depending on the starting column.
- assets/Events/meet-elder.php:10 [4][0]: right 4 steps are now 2 cells; the same distance is 2 cells from any starting column.

### Maps shown with terminal glyphs

- village: tiles2d was removed; the map shows its terminal glyphs in graphical renderers until it has a tileset.

### Cells that became solid

- cave: 5 cells became solid; reachability was compared from 2 entry points.
- village: 6 cells became solid; reachability was compared from 3 entry points.

### No longer reachable

- cave: the arrival at assets/Maps/village/village.data.php:56 events.A.data.spawnPoint no longer connects to 1 other entry point it reached before, such as assets/Events/meet-elder.php:14 [5].then[0] (transfer).
- village: NPC at assets/Maps/village/village.data.php:28 npcs[1] can no longer be reached from the map's entry points.
- village: trigger at assets/Maps/village/village.data.php:46 triggers[0].trigger_area can no longer be reached from the map's entry points.
- village: event E can no longer be reached from the map's entry points.

### Walkable regions cut off

- village: 7 walkable cells around cell (1, 4) can no longer be reached; likely closed by cell (2, 3), ".#", which became solid.

### NPCs now sharing a cell

- village cell (2, 1): assets/Maps/village/village.data.php:20 npcs[0] and assets/Maps/village/village.data.php:35 npcs[2] now share one cell; the game finds only the first NPC in a cell, so move one unless their conditions never overlap.

### Next

- Re-proportion furniture and rooms for square cells where they look stretched; that is the author's work after the conversion.
- Run `ichiloto validate`, then play through the converted maps and cinematics.

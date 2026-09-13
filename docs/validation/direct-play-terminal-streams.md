# Direct Play Terminal Stream Validation

Validated on macOS with PHP 8.5 against Console base commit
`4c45f3428214ec3737c9f24644c8c87813e9f4e9`.

## Failure

Direct `ichiloto play --renderer=terminal --no-tmux` launched the game through
`passthru()`. That introduced an extra forwarding pipe between the CLI and the
game: although the Console process owned the pseudo-terminal, the game did not
inherit it. The final forwarded bytes then remained behind that CLI boundary
until shutdown. This was not a generic failure of the Engine's intentional
non-TTY output path.

A windowless pseudo-terminal capture used a legitimate Garden of Roads
checkpoint at `(8, 3)`, opened and closed the main menu, rendered eight idle
frames, and sampled the screen while the game remained alive:

| Launch | Stationary bytes at 500 ms | Canonical rows |
| --- | ---: | ---: |
| Direct PHP control | 87,570 | 36/36 |
| Console before the fix | 84,939 | 30/36 |
| Console after the fix | 87,570 | 36/36 |
| Console after the fix, parent stdout redirected | 87,467 | 36/36 |

Before the fix, rows 31 through 36 retained the Location panel, help text and
`c: Cancel` footer. The missing bytes arrived only during process shutdown;
the final 87,563-byte capture then decoded correctly. Final-only comparison
would therefore have hidden the live rendering failure.

After the fix, the stationary Console capture is byte-for-byte identical to
the direct PHP control. Its SHA-256 is
`9bc89af1575da0141418029a583c351b28666326037ad7c7b499f67105021e2b`.
An independent redirected-output run also delivered the complete live frame
while the game remained alive, with stderr empty and exit code zero. The game
correctly observed non-TTY input and output in that case.

## Fix and regression coverage

Direct play now starts PHP without an intermediate shell and gives the child
the Console process's stdin and stdout descriptors. Stderr still appends to
the project error log, the project directory remains the child working
directory, exit failures keep their existing user guidance, and
`ICHILOTO_RENDERER` remains child-scoped.

The automated renderer-selection test launches the real CLI inside a private
pseudo-terminal. While the fixture game is deliberately blocked from exiting,
the test verifies that:

- the child sees both stdin and stdout as TTYs;
- its output marker is observable before process exit;
- the same marker remains observable before exit when parent output is a pipe;
- renderer identity and working directory remain correct; and
- shutdown is bounded even if the fixture does not exit normally.

The tmux launch path is unchanged. Renderer selection, GPUI terminal-output
suppression and the Engine/renderer protocol are also unchanged. No Engine,
renderer or game source was modified for this fix.

The windowless Garden capture and the Console regression suite passed on
macOS. Linux and WSL terminal behavior were not validated in this pass.

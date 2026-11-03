<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\ChangeOutcomeReport;
use Ichiloto\Console\Support\EditorProjectBootstrap;
use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Converts a glyph-keyed map to its tileset's terrain kinds, explicitly: the
 * author maps each old glyph to a kind (`--kind ';=grassland'`), the command
 * shows what that does, cell counts and collision changes included, and then
 * converts through the editor's change set ({@see ChangeBatch}), which writes
 * the map only when the project it leaves is valid, reachability included,
 * and moves saves standing where the map becomes solid only when allowed.
 * Without `--kind` it lists the map's glyphs and the tileset's kinds, for
 * writing the mapping. `--check` converts and validates without writing.
 */
#[AsCommand(
    name: 'map:convert-kinds',
    description: 'Convert a map to its tileset\'s terrain kinds, mapping each old glyph to a kind.',
)]
final class MapConvertKindsCommand extends Command
{
    public function configure(): void
    {
        $this
            ->addArgument('map', InputArgument::REQUIRED, 'The map id, such as overworld.')
            ->addOption('kind', 'k', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'An old glyph and the terrain kind it becomes, as glyph=kind, such as ";=grassland". Repeat for each glyph.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Convert and report what committing would find, and write nothing.')
            ->addOption('relocate-saves', null, InputOption::VALUE_NONE, 'Move saves standing where the map is now solid to open floor.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the preview and outcome as JSON.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = (string) ($input->getOption('directory') ?? getcwd() ?: '.');
        if (is_not_valid_working_dir($directory)) {
            $output->writeln('<error>The working directory is not valid: ' . OutputFormatter::escape($directory) . '</error>');

            return Command::FAILURE;
        }
        $directory = realpath($directory) ?: $directory;
        $mapId = (string) $input->getArgument('map');
        $mapping = self::readMapping((array) $input->getOption('kind'));
        if (is_string($mapping)) {
            $output->writeln('<error>' . OutputFormatter::escape($mapping) . '</error>');

            return Command::FAILURE;
        }

        EditorProjectBootstrap::prepare($directory);
        $session = EditorSession::open($directory);
        try {
            $kinds = $session->listKinds($mapId);
            $preview = $session->previewKindConversion($mapId, $mapping);
        } catch (SessionRefusal $refusal) {
            $output->writeln('<error>' . OutputFormatter::escape($refusal->getMessage()) . '</error>');

            return Command::FAILURE;
        } finally {
            $session->close();
        }
        if ($kinds['kinds'] === []) {
            $output->writeln('<error>' . OutputFormatter::escape((string) $kinds['issue']) . '</error>');

            return Command::FAILURE;
        }

        $outcome = $mapping === [] ? null : ChangeBatch::apply($directory,
            [['method' => 'kinds.convert', 'params' => ['map' => $mapId, 'mapping' => $mapping]]],
            (bool) $input->getOption('relocate-saves'), (bool) $input->getOption('check'));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['preview' => $preview, 'kinds' => $kinds['kinds'], 'outcome' => $outcome],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            self::writePreview($output, $preview, $kinds['kinds']);
            if ($outcome !== null) {
                ChangeOutcomeReport::write($output, $outcome);
            }
        }

        return match (true) {
            $outcome === null => Command::SUCCESS,
            $outcome['status'] === 'saved', $outcome['status'] === 'checked' && $outcome['blocking'] === 0 => Command::SUCCESS,
            default => Command::FAILURE,
        };
    }

    /**
     * The mapping from the `--kind` options, or why one cannot be read.
     *
     * @param list<string> $options
     * @return array<string, string>|string
     */
    private static function readMapping(array $options): array|string
    {
        $mapping = [];
        foreach ($options as $option) {
            $separator = mb_strrpos($option, '=');
            $glyph = $separator === false ? '' : mb_substr($option, 0, $separator);
            $kind = $separator === false ? '' : mb_substr($option, $separator + 1);
            if (mb_strlen($glyph) !== 1 || $kind === '') {
                return sprintf('--kind "%s" must be one glyph, "=" and a kind id, such as ";=grassland".', $option);
            }
            if (isset($mapping[$glyph]) && $mapping[$glyph] !== $kind) {
                return sprintf('--kind maps "%s" to both %s and %s; map each glyph once.', $glyph, $mapping[$glyph], $kind);
            }
            $mapping[$glyph] = $kind;
        }

        return $mapping;
    }

    /**
     * Prints what the conversion maps and leaves, cell counts and collision
     * changes included, and the kinds to map to.
     *
     * @param array<string, mixed> $preview
     * @param list<array<string, mixed>> $kinds
     */
    private static function writePreview(OutputInterface $output, array $preview, array $kinds): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<info>%s</info>%s', OutputFormatter::escape($preview['map']),
            $preview['declares'] ? ' is not painted in terrain kinds yet; converting opts it in.' : ' is painted in terrain kinds.'));
        foreach ($preview['mappings'] as $mapped) {
            $output->writeln(sprintf("  '%s' -> %s: %d %s%s", OutputFormatter::escape($mapped['glyph']), OutputFormatter::escape($mapped['name']),
                $mapped['cells'], $mapped['cells'] === 1 ? 'cell' : 'cells', $mapped['awaitingArt'] ? ' (awaiting art: shows its glyph graphically)' : ''));
        }
        $reread = array_column(array_map(static fn(array $glyph): array => [$glyph['layer'] . "\0" . $glyph['glyph'], true], $preview['reread']), 1, 0);
        foreach ($preview['unmapped'] as $left) {
            $output->writeln(sprintf("  '%s' on %s: %d %s left as %s", OutputFormatter::escape($left['glyph']), OutputFormatter::escape($left['layer']),
                $left['cells'], $left['cells'] === 1 ? 'cell' : 'cells',
                isset($reread[$left['layer'] . "\0" . $left['glyph']]) ? 'it is, <comment>though it names a kind: map it</comment>' : 'it is'));
        }
        $collision = $preview['collision'];
        $output->writeln(sprintf('  Collision changes in %d %s: %d become solid, %d become open.', $collision['changed'],
            $collision['changed'] === 1 ? 'cell' : 'cells', $collision['blocked'], $collision['opened']));
        $replaced = $preview['atlasBindings'] ?? [];
        if ($replaced !== []) {
            $output->writeln('  The kinds take over the map\'s atlas classifications of ' . implode(', ', array_map(static fn(array $binding): string =>
                sprintf("'%s' on %s (%s)", OutputFormatter::escape($binding['glyph']), OutputFormatter::escape($binding['layer']),
                    OutputFormatter::escape($binding['role'])), $replaced)) . '.');
        }
        if ($preview['mappings'] === []) {
            $output->writeln('');
            $output->writeln('  Kinds to map to:');
            foreach ($kinds as $kind) {
                $output->writeln(sprintf('    %s  %s (%s) %s%s', OutputFormatter::escape($kind['glyph']), OutputFormatter::escape($kind['id']),
                    OutputFormatter::escape($kind['name']), $kind['passage'], $kind['awaitingArt'] ? ', awaiting art' : ''));
            }
        }
        $output->writeln('');
    }
}

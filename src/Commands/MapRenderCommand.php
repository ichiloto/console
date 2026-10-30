<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\EditorProjectBootstrap;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\MapRenderSubject;
use Ichiloto\Editor\Review\MapRenderView;
use Ichiloto\Editor\Review\MapReviewWriter;
use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\InvalidRequest;
use Ichiloto\Editor\Session\SessionRefusal;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders maps for review the way the game draws them, both ways: the
 * Terminal field's characters and colours as `.ans` and as a page, and a
 * still of the graphical field as a PNG, side by side on one review page.
 * It reads the project through the Editor, saved, or as a batch of changes
 * would leave it before anything is written.
 */
#[AsCommand(
    name: 'map:render',
    description: 'Render maps or regions of them as the Terminal and graphical fields draw them, for review.',
)]
final class MapRenderCommand extends Command
{
    public function configure(): void
    {
        $this
            ->addArgument('views', InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Each view: a map id, or MAP@X,Y,WIDTH,HEIGHT for a region of it.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory.')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'The directory the pictures and review page are written to.', 'map-review')
            ->addOption('views', null, InputOption::VALUE_REQUIRED, 'A JSON file of views, each with its own region, subject and label.')
            ->addOption('player', null, InputOption::VALUE_REQUIRED, 'Stand the player on X,Y[,HEADING] in every view given as an argument.')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Show this actor, by id, where --player stands.')
            ->addOption('changes', null, InputOption::VALUE_REQUIRED, 'Render as this batch of editor changes would leave the project; nothing is written to it.')
            ->addOption('scale', null, InputOption::VALUE_REQUIRED, 'Also write each graphical still at this scale, such as 0.5 or 0.25, for an overview.')
            ->addOption('no-native', null, InputOption::VALUE_NONE, 'Write only the scaled graphical still, for a map too large to draw at native size.')
            ->addOption('font', null, InputOption::VALUE_REQUIRED, 'The monospaced font glyph cells are drawn in on the graphical still.')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The review page\'s title.', 'Map review')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print what was written as JSON.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = (string) ($input->getOption('directory') ?? getcwd() ?: '.');
        if (is_not_valid_working_dir($directory)) {
            $output->writeln('<error>The working directory is not valid: ' . OutputFormatter::escape($directory) . '</error>');

            return Command::FAILURE;
        }
        $directory = realpath($directory) ?: $directory;

        try {
            $views = $this->readViews($input);
            $scale = $input->getOption('scale') === null ? null : self::readScale((string) $input->getOption('scale'));
            if ($input->getOption('no-native') && $scale === null) {
                throw new InvalidArgumentException('--no-native writes only the scaled still; give --scale too.');
            }
            $font = $input->getOption('font');
            if (is_string($font) && ! is_file($font)) {
                throw new InvalidArgumentException(sprintf('There is no font at %s.', $font));
            }
            $changes = is_string($input->getOption('changes')) ? ChangeBatch::readFile((string) $input->getOption('changes')) : null;
        } catch (InvalidArgumentException|InvalidRequest|JsonException $invalid) {
            $output->writeln('<error>' . OutputFormatter::escape($invalid->getMessage()) . '</error>');

            return Command::FAILURE;
        }

        EditorProjectBootstrap::prepare($directory);
        $out = (string) $input->getOption('out');
        $render = function (EditorSession $session) use ($views, $out, $directory, $scale, $font, $input): array {
            $writer = new MapReviewWriter($out, new FieldStillRaster($directory . DIRECTORY_SEPARATOR . 'assets', is_string($font) ? $font : null),
                $scale, ! $input->getOption('no-native'));
            $written = [];
            foreach ($views as $view) {
                $rendering = $session->renderMapReview($view);
                $written[] = ['view' => $view->label, ...$writer->writeRendering($rendering)];
            }

            return ['review' => $writer->writeReviewPage((string) $input->getOption('title')), 'views' => $written];
        };

        try {
            if ($changes === null) {
                $session = EditorSession::open($directory);
                try {
                    $outcome = ['status' => 'rendered', ...$render($session)];
                } finally {
                    $session->close();
                }
            } else {
                $outcome = ChangeBatch::preview($directory, $changes, $render);
                $outcome = $outcome['status'] === 'previewed' ? ['status' => 'rendered', 'changes' => 'previewed, not written', ...$outcome['result']] : $outcome;
            }
        } catch (SessionRefusal|InvalidArgumentException|RuntimeException $failure) {
            $outcome = ['status' => 'failed', 'message' => $failure->getMessage()];
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($outcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->writeOutcome($output, $outcome);
        }

        return $outcome['status'] === 'rendered' ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The views to render: those of the views file, then each argument with
     * the subject --player and --actor name.
     *
     * @return list<MapRenderView>
     * @throws InvalidArgumentException|JsonException When a view cannot be read.
     */
    private function readViews(InputInterface $input): array
    {
        $views = [];
        if (is_string($file = $input->getOption('views'))) {
            $json = is_file($file) ? file_get_contents($file) : false;
            if ($json === false) {
                throw new InvalidArgumentException(sprintf('%s cannot be read.', $file));
            }
            $entries = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            $entries = is_array($entries) && array_key_exists('views', $entries) ? $entries['views'] : $entries;
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new InvalidArgumentException(sprintf('%s must hold a list of views, or {"views": [...]}.', $file));
            }
            foreach ($entries as $index => $entry) {
                $views[] = self::readViewEntry($entry, sprintf('%s view %d', $file, $index + 1));
            }
        }
        $player = $input->getOption('player');
        $actor = $input->getOption('actor');
        if (is_string($actor) && ! is_string($player)) {
            throw new InvalidArgumentException('--actor shows an actor where --player stands; give --player X,Y too.');
        }
        $subject = is_string($player) ? self::readSubject($player, is_string($actor) ? $actor : null) : null;
        foreach ((array) $input->getArgument('views') as $specification) {
            $views[] = MapRenderView::readSpecification((string) $specification, $subject);
        }
        if ($views === []) {
            throw new InvalidArgumentException('Name a map to render, as an argument or in a --views file.');
        }

        return $views;
    }

    /** A view of a views file: `{"map", "region": "X,Y,W,H" or {x, y, width, height}, "player": "X,Y[,HEADING]", "actor", "label"}`. */
    private static function readViewEntry(mixed $entry, string $where): MapRenderView
    {
        if (! is_array($entry) || ! is_string($entry['map'] ?? null)) {
            throw new InvalidArgumentException(sprintf('%s names no map.', $where));
        }
        $region = $entry['region'] ?? null;
        $region = is_string($region) ? MapRenderView::readRegion($region) : $region;
        if ($region !== null && ! is_array($region)) {
            throw new InvalidArgumentException(sprintf('%s gives a region as "X,Y,WIDTH,HEIGHT" or {x, y, width, height}.', $where));
        }
        $player = $entry['player'] ?? null;
        $actor = $entry['actor'] ?? null;
        if (($player !== null && ! is_string($player)) || ($actor !== null && ! is_string($actor)) || ($actor !== null && $player === null)) {
            throw new InvalidArgumentException(sprintf('%s gives a player as "X,Y[,HEADING]", and an actor only with a player.', $where));
        }
        $label = $entry['label'] ?? null;

        return new MapRenderView($entry['map'], $region, $player === null ? null : self::readSubject($player, $actor),
            is_string($label) ? $label : null);
    }

    /** A subject written `X,Y[,HEADING]`. */
    private static function readSubject(string $player, ?string $actorId): MapRenderSubject
    {
        $parts = array_map(trim(...), explode(',', $player));
        if (count($parts) < 2 || count($parts) > 3 || preg_match('/\A\d+\z/', $parts[0]) !== 1 || preg_match('/\A\d+\z/', $parts[1]) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is no cell: write X,Y or X,Y,HEADING.', $player));
        }
        $heading = isset($parts[2]) ? MapRenderSubject::readHeading($parts[2]) : MapRenderSubject::readHeading('south');

        return new MapRenderSubject((int) $parts[0], (int) $parts[1], $heading, $actorId);
    }

    private static function readScale(string $scale): float
    {
        if (! is_numeric($scale) || (float) $scale <= 0 || (float) $scale >= 1) {
            throw new InvalidArgumentException(sprintf('--scale is above 0 and below 1, such as 0.5 or 0.25, not %s.', $scale));
        }

        return (float) $scale;
    }

    /** @param array<string, mixed> $outcome */
    private function writeOutcome(OutputInterface $output, array $outcome): void
    {
        if ($outcome['status'] !== 'rendered') {
            $step = isset($outcome['step']) ? sprintf('Change %d (%s): ', $outcome['step'], $outcome['method'] ?? 'unknown') : '';
            $output->writeln('<error>' . OutputFormatter::escape($step . ($outcome['message'] ?? 'Rendering failed.')) . '</error>');

            return;
        }
        if (isset($outcome['changes'])) {
            $output->writeln('Rendered as the batch would leave the project; nothing was written to it.');
        }
        foreach ($outcome['views'] as $view) {
            $output->writeln('<info>' . OutputFormatter::escape($view['view']) . '</info>');
            foreach ($view['files'] as $file) {
                $output->writeln('  ' . OutputFormatter::escape($file));
            }
            foreach ($view['diagnostics'] as $note) {
                $output->writeln('  <comment>' . OutputFormatter::escape($note) . '</comment>');
            }
        }
        $output->writeln('Review page: ' . OutputFormatter::escape($outcome['review']));
    }
}

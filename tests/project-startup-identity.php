<?php

declare(strict_types=1);

use Ichiloto\Console\Support\NewProjectScaffolder;
use Ichiloto\Engine\Audio\AudioMutePreflight;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

if (($argv[1] ?? null) === '--child') {
    require dirname(__DIR__, 2) . '/engine/vendor/autoload.php';
} else {
    require dirname(__DIR__) . '/vendor/autoload.php';
}

/** Real title, loader, field and Engine loop; stops immediately after field startup. */
final class StartupIdentityProbe extends Game
{
    protected function start(): void
    {
        parent::start();
        $config = GameLoader::getInstance($this)->loadNewGame();
        $scene = $this->sceneManager->loadScene(GameScene::class)->currentScene;
        if (! $scene instanceof GameScene) {
            throw new RuntimeException('The new game did not enter the field scene.');
        }
        $scene->configure($config);
        if ($scene->party->members->count() !== 1) {
            throw new RuntimeException('The starting actor was not loaded.');
        }
        fwrite(STDERR, "FIELD_STARTUP_OK\n");
        $this->quit();
    }
}

/** @return array{code: int, output: string} */
function runStartupChild(string $projectRoot): array
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--child', $projectRoot],
        [0 => ['pipe', 'r'], 1 => ['file', $projectRoot . '/startup.stdout', 'w'], 2 => ['file', $projectRoot . '/startup.stderr', 'w']],
        $pipes,
        $projectRoot,
        array_replace(getenv(), [
            'ICHILOTO_RENDERER' => 'terminal',
            'ICHILOTO_TEST_TERMINAL_SIZE' => '36 135',
            'TERM' => 'dumb',
            'COLUMNS' => '135',
            'LINES' => '36',
        ]),
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start bounded Game process.');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 30;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) { break; }
        usleep(50000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Bounded Game startup exceeded 30 seconds.');
    }
    $code = $status['exitcode'] >= 0 ? $status['exitcode'] : proc_close($process);
    if ($status['exitcode'] >= 0) { proc_close($process); }
    $output = (string) file_get_contents($projectRoot . '/startup.stderr');
    if (is_file($projectRoot . '/logs/error.log')) {
        $output .= (string) file_get_contents($projectRoot . '/logs/error.log');
    }
    return ['code' => $code, 'output' => $output];
}

function copySmallProjectTree(string $source, string $destination): void
{
    if (! is_dir($destination) && ! mkdir($destination, 0700, true)) {
        throw new RuntimeException("Cannot create {$destination}.");
    }
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $from = $source . '/' . $entry;
        $to = $destination . '/' . $entry;
        if (is_dir($from)) {
            copySmallProjectTree($from, $to);
        } elseif (! copy($from, $to)) {
            throw new RuntimeException("Cannot copy {$from}.");
        }
    }
}

function removeStartupFixture(string $root): void
{
    if (! is_dir($root)) { return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

function makeSilentConfig(string $projectRoot, array $config): void
{
    $config['audio'] = array_replace($config['audio'] ?? [], [
        'master_volume' => 0,
        'music' => false,
        'sfx' => false,
        'voice' => false,
    ]);
    file_put_contents($projectRoot . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
}

/** This must pass before opening any automated Game process. */
function assertSilentProject(string $projectRoot): void
{
    $previous = getcwd() ?: dirname(__DIR__);
    chdir($projectRoot);
    try {
        ConfigStore::put(PlayerSettings::class, new PlayerSettings($projectRoot));
        AudioMutePreflight::assertMuted(new ProjectConfig());
    } finally {
        ConfigStore::remove(PlayerSettings::class);
        chdir($previous);
    }
}

if (($argv[1] ?? null) === '--child') {
    $projectRoot = $argv[2] ?? '';
    chdir($projectRoot);
    ConfigStore::put(PlayerSettings::class, new PlayerSettings($projectRoot));
    AudioMutePreflight::assertMuted(new ProjectConfig());
    $game = new StartupIdentityProbe('Identity startup check');
    AudioMutePreflight::assertMuted(ConfigStore::get(ProjectConfig::class));
    $game->run();
    fwrite(STDERR, "GAME_SHUTDOWN_OK\n");
    exit(0);
}

$temporaryRoot = sys_get_temp_dir() . '/ichiloto-startup-identity-' . bin2hex(random_bytes(8));
$freshRoot = $temporaryRoot . '/fresh';
$epicRoot = $temporaryRoot . '/epic-quest';
$epicSource = dirname(__DIR__) . '/../examples/epic-quest';

try {
    new NewProjectScaffolder()->scaffold([
        'displayName' => 'Startup Identity',
        'directoryName' => 'startup-identity',
        'targetDirectory' => $freshRoot,
        'heroName' => 'Aria Vale',
        'heroId' => 'AriaVale',
        'battleEngine' => 'traditional',
        'titleArt' => "STARTUP IDENTITY\n",
    ]);
    makeSilentConfig($freshRoot, require $freshRoot . '/config.php');
    assertSilentProject($freshRoot);
    $fresh = runStartupChild($freshRoot);
    if ($fresh['code'] !== 0 || ! str_contains($fresh['output'], 'FIELD_STARTUP_OK')
        || ! str_contains($fresh['output'], 'GAME_SHUTDOWN_OK') || str_contains($fresh['output'], '[ERROR]')) {
        throw new RuntimeException('Fresh project did not reach field startup: ' . $fresh['output']);
    }

    mkdir($epicRoot, 0700, true);
    copySmallProjectTree($epicSource . '/assets', $epicRoot . '/assets');
    copy($epicSource . '/input.php', $epicRoot . '/input.php');
    copy($epicSource . '/ichiloto.json', $epicRoot . '/ichiloto.json');
    makeSilentConfig($epicRoot, require $epicSource . '/config.php');
    assertSilentProject($epicRoot);
    $epic = runStartupChild($epicRoot);
    if ($epic['code'] !== 0 || ! str_contains($epic['output'], 'FIELD_STARTUP_OK')
        || ! str_contains($epic['output'], 'GAME_SHUTDOWN_OK') || str_contains($epic['output'], '[ERROR]')) {
        throw new RuntimeException('EpicQuest copy did not reach field startup: ' . $epic['output']);
    }
} finally {
    removeStartupFixture($temporaryRoot);
}

fwrite(STDOUT, "PASS: fresh and EpicQuest projects reach real field startup with effective audio muted.\n");

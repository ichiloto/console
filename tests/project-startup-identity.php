<?php

declare(strict_types=1);

use Ichiloto\Console\Support\NewProjectScaffolder;
use Ichiloto\Engine\Audio\AudioMutePreflight;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Commands\NewGameCommand;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__) . '/vendor/autoload.php';

const STARTUP_TIMEOUT_SECONDS = 30;
const STARTUP_POLL_MICROSECONDS = 50_000;

/** Capture the rendered alert while it is still visible, then dismiss it. */
final class CapturedAlertInputSource implements InputSourceInterface
{
    private ?string $renderedAlert = null;
    private bool $dismissed = false;

    public function poll(): ?KeyCode
    {
        if ($this->dismissed) { return null; }
        $this->renderedAlert = implode("\n", Console::getBuffer());
        $this->dismissed = true;
        return KeyCode::ENTER;
    }

    public function reset(bool $drainBufferedInput = false): void {}

    public function getRenderedAlert(): ?string
    {
        return $this->renderedAlert;
    }
}

/** Real title, loader, field and Engine loop; stops immediately after field startup. */
final class StartupIdentityProbe extends Game
{
    public string $probeMode = 'field';

    protected function start(): void
    {
        parent::start();
        if ($this->probeMode === 'title-recovery') {
            $this->verifyNewGameFailureRecoversTitle();
            $this->quit();
            return;
        }
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

    private function verifyNewGameFailureRecoversTitle(): void
    {
        $title = $this->sceneManager->currentScene;
        if (! $title instanceof TitleScene) {
            throw new RuntimeException('New Game did not begin at the title scene.');
        }
        $menu = (new ReflectionProperty(TitleScene::class, 'menu'))->getValue($title);
        if (! $menu instanceof TitleMenu) {
            throw new RuntimeException('The title scene has no active menu.');
        }
        $command = array_find($menu->getItems()->toArray(), static fn($item): bool => $item instanceof NewGameCommand);
        if (! $command instanceof NewGameCommand) {
            throw new RuntimeException('The title menu has no New Game command.');
        }
        $capture = new CapturedAlertInputSource();
        InputManager::setInputSource($capture);
        $result = $command->execute(new MenuCommandExecutionContext([], new BufferedOutput(), $menu, $title));
        $alert = preg_replace('/\x1b\[[0-9;]*m/', '', $capture->getRenderedAlert() ?? '') ?? '';
        $alert = preg_replace('/[║╔╗╚╝═]/u', ' ', $alert) ?? '';
        $alert = preg_replace('/\s+/u', ' ', $alert) ?? '';
        if ($result !== NewGameCommand::FAILURE
            || ! $this->sceneManager->currentScene instanceof TitleScene
            || ! $this->isRunning || $this->hasStopped()
            || ! str_contains($alert, 'New Game Unavailable')
            || ! str_contains($alert, 'assets/Data/system.php')
            || ! str_contains($alert, 'AriaVale')
            || ! str_contains($alert, 'ichiloto validate --migrate-actor-ids')) {
            throw new RuntimeException('New Game did not show a recoverable title alert: ' . $alert);
        }
        fwrite(STDERR, "TITLE_FAILURE_RECOVERED\n");
    }
}

/** @return array{code: int, output: string} */
function runStartupChild(string $projectRoot, string $probeMode = 'field'): array
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--child', $projectRoot, $probeMode],
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
    $deadline = microtime(true) + STARTUP_TIMEOUT_SECONDS;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) { break; }
        usleep(STARTUP_POLL_MICROSECONDS);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException(sprintf('Bounded Game startup exceeded %d seconds.', STARTUP_TIMEOUT_SECONDS));
    }
    $code = $status['exitcode'] >= 0 ? $status['exitcode'] : proc_close($process);
    if ($status['exitcode'] >= 0) { proc_close($process); }
    $output = (string) file_get_contents($projectRoot . '/startup.stderr');
    if (is_file($projectRoot . '/logs/error.log')) {
        $output .= (string) file_get_contents($projectRoot . '/logs/error.log');
    }
    return ['code' => $code, 'output' => $output];
}

/** @return array{code: int, output: string} */
function runValidationChild(string $projectRoot, array $options = []): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/bin/ichiloto', 'validate', '--no-ansi', '--no-interaction', '--directory', $projectRoot, ...$options],
        [0 => ['pipe', 'r'], 1 => ['file', $projectRoot . '/validate.stdout', 'w'], 2 => ['file', $projectRoot . '/validate.stderr', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start bounded project validation.');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + STARTUP_TIMEOUT_SECONDS;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) { break; }
        usleep(STARTUP_POLL_MICROSECONDS);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Bounded project validation exceeded its time limit.');
    }
    $code = $status['exitcode'] >= 0 ? $status['exitcode'] : proc_close($process);
    if ($status['exitcode'] >= 0) { proc_close($process); }
    return [
        'code' => $code,
        'output' => (string) file_get_contents($projectRoot . '/validate.stdout')
            . (string) file_get_contents($projectRoot . '/validate.stderr'),
    ];
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

/** Accept the older vendored Engine only when no local source checkout exists. */
function assertUpdatedLocalEngineIsSelected(): void
{
    $localSource = realpath(dirname(__DIR__, 2) . '/engine/src');
    if ($localSource === false) { return; }

    foreach ([AudioMutePreflight::class, PlayerSettings::class] as $class) {
        if (! class_exists($class)) {
            throw new RuntimeException("Local Engine API {$class} is unavailable through Console autoloading.");
        }
        $resolved = (string) (new ReflectionClass($class))->getFileName();
        if (! str_starts_with($resolved, $localSource . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Console did not resolve {$class} from the local Engine source.");
        }
    }
}

function assertMutedConfig(ProjectConfig $effective): void
{
    foreach (['audio.music', 'audio.sfx', 'audio.voice'] as $path) {
        if (boolval($effective->get($path, $path === 'audio.voice'))) {
            throw new RuntimeException("Automated startup requires {$path} to be off.");
        }
    }
    if (class_exists(AudioMutePreflight::class)) {
        AudioMutePreflight::assertMuted($effective);
    }
}

/** This must pass before opening any automated Game process. */
function assertSilentProject(string $projectRoot): void
{
    $previous = getcwd() ?: dirname(__DIR__);
    chdir($projectRoot);
    try {
        if (class_exists(PlayerSettings::class)) {
            ConfigStore::put(PlayerSettings::class, new PlayerSettings($projectRoot));
        } elseif (is_file($projectRoot . '/.data/player-settings.json')) {
            throw new RuntimeException('Cannot verify a saved player-settings override with this Engine version.');
        }
        assertMutedConfig(new ProjectConfig());
    } finally {
        if (class_exists(PlayerSettings::class)) { ConfigStore::remove(PlayerSettings::class); }
        chdir($previous);
    }
}

if (($argv[1] ?? null) === '--child') {
    $projectRoot = $argv[2] ?? '';
    assertUpdatedLocalEngineIsSelected();
    assertSilentProject($projectRoot);
    chdir($projectRoot);
    $game = new StartupIdentityProbe('Identity startup check');
    $game->probeMode = $argv[3] ?? 'field';
    $effectiveConfig = ConfigStore::get(ProjectConfig::class);
    if (! $effectiveConfig instanceof ProjectConfig) {
        throw new RuntimeException('Game did not load the effective project configuration.');
    }
    assertMutedConfig($effectiveConfig);
    $game->run();
    fwrite(STDERR, "GAME_SHUTDOWN_OK\n");
    exit(0);
}

$temporaryRoot = sys_get_temp_dir() . '/ichiloto-startup-identity-' . bin2hex(random_bytes(8));
$freshRoot = $temporaryRoot . '/fresh';
$epicRoot = $temporaryRoot . '/epic-quest';
$releasedRoot = $temporaryRoot . '/released-0.5.0';
$partialRoot = $temporaryRoot . '/partially-migrated-0.5.0';
$unsupportedRoot = $temporaryRoot . '/unsupported-released-0.5.0';
$epicFixture = getenv('ICHILOTO_EPIC_QUEST_SRC');
$epicSource = is_string($epicFixture) && $epicFixture !== '' ? realpath($epicFixture) : false;

assertUpdatedLocalEngineIsSelected();

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

    fwrite(STDOUT, "PASS: fresh project reaches real field startup with effective audio muted.\n");

    // Generated by the actual Console 0.5.0 tag a92ce3c, not the current scaffolder.
    $releasedSource = __DIR__ . '/fixtures/released-console-0.5.0-project';
    copySmallProjectTree($releasedSource, $releasedRoot);
    $legacyActorPath = $releasedRoot . '/assets/Data/Actors/AriaVale.php';
    $legacySystemPath = $releasedRoot . '/assets/Data/system.php';
    $legacyActor = require $legacyActorPath;
    $legacySystem = require $legacySystemPath;
    if (array_key_exists('id', $legacyActor['data'])
        || $legacyActor['data']['name'] !== 'Aria Vale'
        || $legacySystem['startingParty'] !== ['AriaVale']) {
        throw new RuntimeException('The committed fixture no longer matches the released Console 0.5.0 actor mismatch.');
    }
    makeSilentConfig($releasedRoot, require $releasedRoot . '/config.php');
    assertSilentProject($releasedRoot);
    $legacyStartup = runStartupChild($releasedRoot);
    if ($legacyStartup['code'] !== 0 || ! str_contains($legacyStartup['output'], 'FIELD_STARTUP_OK')
        || ! str_contains($legacyStartup['output'], 'GAME_SHUTDOWN_OK')) {
        throw new RuntimeException('The released project could not start a new game before migration: ' . $legacyStartup['output']);
    }
    $legacyValidation = runValidationChild($releasedRoot);
    if ($legacyValidation['code'] === 0
        || ! str_contains($legacyValidation['output'], 'AriaVale.php')
        || ! str_contains($legacyValidation['output'], 'startingParty')) {
        throw new RuntimeException('Validation did not identify the released actor file and stale starting party: ' . $legacyValidation['output']);
    }
    $migrated = runValidationChild($releasedRoot, ['--migrate-actor-ids']);
    $migratedActor = require $legacyActorPath;
    $migratedSystem = require $legacySystemPath;
    if ($migrated['code'] !== 0
        || ($migratedActor['data']['id'] ?? null) !== 'Aria Vale'
        || $migratedSystem['startingParty'] !== ['Aria Vale']
        || ! str_contains($migrated['output'], 'AriaVale.php')
        || ! str_contains($migrated['output'], 'system.php')) {
        throw new RuntimeException('Migration did not repair the released actor and starting party: ' . $migrated['output']);
    }
    $actorAfterMigration = (string) file_get_contents($legacyActorPath);
    $systemAfterMigration = (string) file_get_contents($legacySystemPath);
    $again = runValidationChild($releasedRoot, ['--migrate-actor-ids']);
    if ($again['code'] !== 0 || file_get_contents($legacyActorPath) !== $actorAfterMigration
        || file_get_contents($legacySystemPath) !== $systemAfterMigration) {
        throw new RuntimeException('The released project migration was not idempotent: ' . $again['output']);
    }
    assertSilentProject($releasedRoot);
    $migratedStartup = runStartupChild($releasedRoot);
    if ($migratedStartup['code'] !== 0 || ! str_contains($migratedStartup['output'], 'FIELD_STARTUP_OK')
        || ! str_contains($migratedStartup['output'], 'GAME_SHUTDOWN_OK')) {
        throw new RuntimeException('The released project could not start a new game after migration: ' . $migratedStartup['output']);
    }
    fwrite(STDOUT, "PASS: released Console 0.5.0 project starts before and after source-preserving actor migration.\n");

    copySmallProjectTree($releasedSource, $partialRoot);
    $partialActorPath = $partialRoot . '/assets/Data/Actors/AriaVale.php';
    $partialSystemPath = $partialRoot . '/assets/Data/system.php';
    $partialActorSource = (string) file_get_contents($partialActorPath);
    $partialActorSource = str_replace(
        "    'name' => 'Aria Vale',",
        "    'id' => 'Aria Vale',\n    'name' => 'Aria Vale',",
        $partialActorSource,
        $insertedIds,
    );
    if ($insertedIds !== 1) {
        throw new RuntimeException('Could not model a partially migrated released actor.');
    }
    file_put_contents($partialActorPath, $partialActorSource);
    makeSilentConfig($partialRoot, require $partialRoot . '/config.php');
    assertSilentProject($partialRoot);
    $partialBeforeRepair = runStartupChild($partialRoot);
    if ($partialBeforeRepair['code'] === 0
        || str_contains($partialBeforeRepair['output'], 'FIELD_STARTUP_OK')
        || ! str_contains($partialBeforeRepair['output'], 'AriaVale')) {
        throw new RuntimeException('A modern actor id unexpectedly accepted its stale file-stem reference: ' . $partialBeforeRepair['output']);
    }
    $recoveredTitle = runStartupChild($partialRoot, 'title-recovery');
    if ($recoveredTitle['code'] !== 0 || ! str_contains($recoveredTitle['output'], 'TITLE_FAILURE_RECOVERED')
        || str_contains($recoveredTitle['output'], 'FIELD_STARTUP_OK')) {
        throw new RuntimeException('The released project did not recover a failed New Game at its title: ' . $recoveredTitle['output']);
    }
    $partialValidation = runValidationChild($partialRoot);
    if ($partialValidation['code'] === 0 || ! str_contains($partialValidation['output'], 'startingParty')) {
        throw new RuntimeException('Validation missed an already identified actor with a stale party reference: ' . $partialValidation['output']);
    }
    $partialMigration = runValidationChild($partialRoot, ['--migrate-actor-ids']);
    $partialSystem = require $partialSystemPath;
    if ($partialMigration['code'] !== 0 || $partialSystem['startingParty'] !== ['Aria Vale']
        || file_get_contents($partialActorPath) !== $partialActorSource) {
        throw new RuntimeException('Migration did not repair a previously frozen actor reference: ' . $partialMigration['output']);
    }
    assertSilentProject($partialRoot);
    $partialStartup = runStartupChild($partialRoot);
    if ($partialStartup['code'] !== 0 || ! str_contains($partialStartup['output'], 'FIELD_STARTUP_OK')
        || ! str_contains($partialStartup['output'], 'GAME_SHUTDOWN_OK')) {
        throw new RuntimeException('The partially migrated project could not start after reference repair: ' . $partialStartup['output']);
    }
    fwrite(STDOUT, "PASS: stale released party references show a recoverable title alert, then migrate cleanly.\n");

    copySmallProjectTree($releasedSource, $unsupportedRoot);
    $unsupportedActorPath = $unsupportedRoot . '/assets/Data/Actors/AriaVale.php';
    $unsupportedSystemPath = $unsupportedRoot . '/assets/Data/system.php';
    $unsupportedSystemSource = (string) file_get_contents($unsupportedSystemPath);
    $unsupportedActorSource = (string) file_get_contents($unsupportedActorPath);
    $unsupportedActorSource = str_replace('return [', '$actor = [', $unsupportedActorSource, $convertedReturns);
    if ($convertedReturns !== 1) {
        throw new RuntimeException('Could not model a dynamically returned released actor.');
    }
    file_put_contents($unsupportedActorPath, $unsupportedActorSource . "\nreturn \$actor;\n");
    $unsupportedMigration = runValidationChild($unsupportedRoot, ['--migrate-actor-ids']);
    if ($unsupportedMigration['code'] === 0
        || ! str_contains($unsupportedMigration['output'], 'AriaVale.php')
        || file_get_contents($unsupportedActorPath) !== $unsupportedActorSource . "\nreturn \$actor;\n"
        || file_get_contents($unsupportedSystemPath) !== $unsupportedSystemSource) {
        throw new RuntimeException('The CLI did not refuse unsupported actor source with its filename: ' . $unsupportedMigration['output']);
    }
    fwrite(STDOUT, "PASS: migration refusal names the unsupported released actor file.\n");

    if ($epicFixture === false || $epicFixture === '') {
        fwrite(STDOUT, "SKIP: set ICHILOTO_EPIC_QUEST_SRC to check EpicQuest field startup.\n");
    } else {
        if ($epicSource === false || ! is_file($epicSource . '/ichiloto.json')
            || ! is_file($epicSource . '/config.php') || ! is_file($epicSource . '/input.php')
            || ! is_dir($epicSource . '/assets')) {
            throw new RuntimeException('ICHILOTO_EPIC_QUEST_SRC does not name a complete project.');
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
        fwrite(STDOUT, "PASS: EpicQuest project reaches real field startup with effective audio muted.\n");
    }
} finally {
    removeStartupFixture($temporaryRoot);
}

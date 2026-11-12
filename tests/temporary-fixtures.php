<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/temporary.php';

use Ichiloto\Console\Tests\Support\TemporaryFixtures;

function requireFixtureBehavior(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function startConsoleFixtureChild(string $base, string $body): array
{
    $source='require '.var_export(__DIR__.'/fixtures/TemporaryFixtures.php',true).';'
        .'$fixtures=\\Ichiloto\\Console\\Tests\\Support\\TemporaryFixtures::class;'.$body;
    $process=proc_open([PHP_BINARY,'-d','memory_limit=64M','-r',$source],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,
        array_replace(getenv(),['TMPDIR'=>$base,'TEMP'=>$base,'TMP'=>$base]));
    requireFixtureBehavior(is_resource($process),'Cannot start Console fixture child.');
    stream_set_timeout($pipes[1],5); stream_set_timeout($pipes[2],5);
    return [$process,$pipes];
}

function finishConsoleFixtureChild(array $child): array
{
    [$process,$pipes]=$child; fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process),$stdout,$stderr];
}

$base=getConsoleTestTemporaryDirectory().'/children';mkdir($base);
[$status,$stdout,$stderr]=finishConsoleFixtureChild(startConsoleFixtureChild($base, <<<'PHP'
    $shared=$fixtures::getDirectory();file_put_contents($shared.'/shared','process');
    $fixtures::beginTest('first');$first=$fixtures::createDirectory('with spaces ');
    file_put_contents($first.'/owned','first');$fixtures::endTest();
    $fixtures::beginTest('second');$second=$fixtures::getDirectory();
    $fixtures::clearCurrentTest();$secondAgain=$fixtures::getDirectory();$fixtures::endTest();
    echo json_encode([!file_exists($first),!file_exists($second),$second===$secondAgain,is_file($shared.'/shared')]);
    PHP));
requireFixtureBehavior($status===0 && $stderr==='' && json_decode($stdout,true)===[true,true,true,true],
    'Console fixtures must clear each test while retaining process-scoped helpers.');
requireFixtureBehavior(scandir($base)===['.','..'],'Normal Console shutdown left a run or lock.');

$external=getConsoleTestTemporaryDirectory().'/external';mkdir($external);file_put_contents($external.'/keep','original');
[$status,$stdout,$stderr]=finishConsoleFixtureChild(startConsoleFixtureChild($base,'$outside='.var_export($external,true).';'. <<<'PHP'
    $root=$fixtures::getDirectory();mkdir($root.'/readonly');file_put_contents($root.'/readonly/file','owned');chmod($root.'/readonly',0500);
    if (PHP_OS_FAMILY!=='Windows') symlink($outside,$root.'/shared-source');
    register_shutdown_function(static function () use ($root):void { file_put_contents($root.'/late','final flush'); });
    echo json_encode($root)."\n";throw new RuntimeException('intentional Console fixture exit');
    PHP));
requireFixtureBehavior($status!==0 && str_contains($stderr,'intentional Console fixture exit'), 'Fatal fixture exit was concealed.');
requireFixtureBehavior(scandir($base)===['.','..'] && file_get_contents($external.'/keep')==='original',
    'Fatal cleanup left its run or followed a symlink target.');

if (PHP_OS_FAMILY!=='Windows') {
    $child=startConsoleFixtureChild($base, <<<'PHP'
        $root=$fixtures::getDirectory();file_put_contents($root.'/owned','live');
        echo json_encode($root)."\n";fflush(STDOUT);fgets(STDIN);
        PHP);
    [$process,$pipes]=$child;
    try {
        $live=json_decode(fgets($pipes[1]),true,flags:JSON_THROW_ON_ERROR);
        [$status,,$stderr]=finishConsoleFixtureChild(startConsoleFixtureChild($base,'$fixtures::getDirectory();'));
        requireFixtureBehavior($status===0 && $stderr==='' && is_file($live.'/owned'),'A live Console fixture lock was ignored.');
        requireFixtureBehavior(proc_terminate($process,9),'Cannot stop the owned lifecycle child.');
        finishConsoleFixtureChild($child);$child=null;
        requireFixtureBehavior(is_file($live.'/owned'),'The killed child unexpectedly cleaned itself.');
        [$status,,$stderr]=finishConsoleFixtureChild(startConsoleFixtureChild($base,'$fixtures::getDirectory();'));
        requireFixtureBehavior($status===0 && $stderr==='' && !file_exists($live),'The next Console run did not recover the killed owner.');
    } finally {
        if ($child!==null) { proc_terminate($process,9);finishConsoleFixtureChild($child); }
    }
}

[$status,$stdout,$stderr]=finishConsoleFixtureChild(startConsoleFixtureChild($base, <<<'PHP'
    $root=$fixtures::getDirectory();
    $GLOBALS['lateConsoleDiagnostic']=new class($root) {
        public function __construct(private string $root) {}
        public function __destruct() {
            if (!is_dir($this->root)) mkdir($this->root,0700,true);
            file_put_contents($this->root.'/late','late destructor');
        }
    };
    echo json_encode($root);
    PHP));
$late=json_decode($stdout,true,flags:JSON_THROW_ON_ERROR);
requireFixtureBehavior($status===0 && $stderr==='' && is_file($late.'/late'),'Late destructor probe did not reproduce the old residue.');
[$status,,$stderr]=finishConsoleFixtureChild(startConsoleFixtureChild($base,'$fixtures::getDirectory();'));
requireFixtureBehavior($status===0 && $stderr==='' && !file_exists($late),'Late destructor diagnostics were not recovered.');

$unknown=$base.'/ichiloto-console-fixtures-unowned';mkdir($unknown);file_put_contents($unknown.'/keep','unowned');
file_put_contents($unknown.'.lock','another owner');
[$status,,$stderr]=finishConsoleFixtureChild(startConsoleFixtureChild($base,'$fixtures::getDirectory();'));
requireFixtureBehavior($status===0 && $stderr==='' && file_get_contents($unknown.'/keep')==='unowned'
    && file_get_contents($unknown.'.lock')==='another owner','Unknown Console lock ownership was discarded.');
echo "Console temporary fixture lifecycle passed: scope, shutdown, fatal exit, held lock, killed owner, late destructor and foreign data.\n";

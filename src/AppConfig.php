<?php

namespace Ichiloto\Console;

use Ichiloto\Console\Interfaces\ConfigInterface;
use Ichiloto\Console\Util\Path;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfig implements Interfaces\ConfigInterface
{
  /**
   * @var array<string, mixed> The configuration.
   */
  protected array $config = [];
  /**
   * @var string The filename.
   */
  protected string $filename = '';

  /**
   * Creates a new instance of the AppConfig class.
   *
   * @param string $workingDirectory The working directory.
   */
  public function __construct(
    protected InputInterface $input,
    protected OutputInterface $output,
    protected string $workingDirectory = ''
  )
  {
    $this->load();
  }

  /**
   * Loads the configuration.
   *
   * @return void
   */
  protected function load(): void
  {
    if (is_not_valid_working_dir($this->workingDirectory)) {
      throw new InvalidArgumentException('Invalid working directory.');
    }

    $this->filename = Path::join($this->workingDirectory, 'ichiloto.json');
    $this->config = json_decode(file_get_contents($this->filename), true);
  }

  /**
   * @inheritDoc
   */
  public function get(string $path): mixed
  {
    $config = $this->config;
    $keys = explode('.', $path);

    foreach ($keys as $key) {
      if (array_key_exists($key, $config)) {
        $config = $config[$key];
      } else {
        return null;
      }
    }

    return $config;
  }

  /**
   * @inheritDoc
   */
  public function set(string $path, mixed $value): void
  {
    $config = &$this->config;
    $keys = explode('.', $path);

    foreach ($keys as $key) {
      if (! array_key_exists($key, $config)) {
        $config[$key] = [];
      }

      $config = &$config[$key];
    }

    $config = $value;
  }

  /**
   * @inheritDoc
   */
  public function commit(): int
  {
    if (! file_exists($this->filename)) {
      throw new InvalidArgumentException('The configuration file does not exist.');
    }

    return file_put_contents($this->filename, json_encode($this->config, JSON_PRETTY_PRINT));
  }

  /**
   * @inheritDoc
   */
  public function __toString(): string
  {
    return json_encode($this->config, JSON_PRETTY_PRINT);
  }
}
<?php

namespace Ichiloto\Console\Interfaces;

use Stringable;

/**
 * ConfigInterface is an interface that defines the configuration functionality.
 *
 * @package Ichiloto\Console\Interfaces
 */
interface ConfigInterface extends Stringable
{
  /**
   * Get the value of the given path.
   *
   * @param string $path The path.
   * @return mixed The value.
   */
  public function get(string $path): mixed;

  /**
   * Set the value of the given path.
   *
   * @param string $path The path.
   * @param mixed $value The value.
   * @return void
   */
  public function set(string $path, mixed $value): void;

  /**
   * Save the configuration.
   *
   * @return int The number of bytes written to the file.
   */
  public function commit(): int;
}
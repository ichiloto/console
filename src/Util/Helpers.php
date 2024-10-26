<?php

use Ichiloto\Console\Util\Path;

if (! function_exists('is_valid_working_dir') ) {
  /**
   * Checks if the working directory is valid.
   *
   * @param string $workingDirectory The working directory.
   * @return bool
   */
  function is_valid_working_dir(string $workingDirectory): bool
  {
    $ichilotoConfigPath = Path::join($workingDirectory, 'ichiloto.json');
    return is_dir($workingDirectory) && is_readable($workingDirectory) && file_exists($ichilotoConfigPath);
  }
}

if (! function_exists('is_not_valid_working_dir') ) {
  /**
   * Checks if the working directory is not valid.
   *
   * @param string $workingDirectory The working directory.
   * @return bool
   */
  function is_not_valid_working_dir(string $workingDirectory): bool
  {
    return ! is_valid_working_dir($workingDirectory);
  }
}
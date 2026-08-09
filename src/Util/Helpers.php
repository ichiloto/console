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

/* String Manipulation */
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

if (! function_exists('strtokebab') ) {
  /**
   * Converts a string to kebab case.
   *
   * @param string $string The string.
   * @return string The kebab case string.
   */
  function strtokebab(string $string): string
  {
    $string = preg_replace('/(?<!^)[A-Z]/', '-$0', trim($string));
    $string = preg_replace('/[\s_]+/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);

    return strtolower($string);
  }
}

if (! function_exists('strtosnake') ) {
  /**
   * Converts a string to snake case.
   *
   * @param string $string The string.
   * @return string The snake case string.
   */
  function strtosnake(string $string): string
  {
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
  }
}

if (! function_exists('strtopascal') ) {
  /**
   * Converts a string to pascal case.
   *
   * @param string $string The string.
   * @return string The pascal case string.
   */
  function strtopascal(string $string): string
  {
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $string)));
  }
}

if (! function_exists('strtocamel') ) {
  /**
   * Converts a string to camel case.
   *
   * @param string $string The string.
   * @return string The camel case string.
   */
  function strtocamel(string $string): string
  {
    return lcfirst(strtopascal($string));
  }
}

if (! function_exists('strtoconst') ) {
  /**
   * Converts a string to constant case.
   *
   * @param string $string The string.
   * @return string The constant case string.
   */
  function strtoconst(string $string): string
  {
    return strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
  }
}

/* Paths */
if (! function_exists('get_data_path') ) {
  function get_data_path(string $subPath = ''): string
  {
    return Path::join(Path::getWorkingDirectory(), 'Data');
  }
}
if (! function_exists('load_engine_autoloader') ) {
  /**
   * Loads the engine's classes so a project's data files can be evaluated.
   *
   * Map and data files legitimately reference engine types (a heading, a
   * collision type, a key code), so reading a project means having the engine
   * loaded. A project installed the normal way carries it in its own vendor
   * directory; inside this workspace the engine sits beside the console
   * instead, which is the fallback.
   *
   * @param string $workingDirectory The project directory.
   * @return bool Whether the engine is loaded.
   */
  function load_engine_autoloader(string $workingDirectory): bool
  {
    if (class_exists(\Ichiloto\Engine\Events\Enumerations\LootType::class)) {
      return true;
    }

    $candidates = [
      rtrim($workingDirectory, DIRECTORY_SEPARATOR) . '/vendor/autoload.php',
      dirname(__DIR__, 3) . '/engine/vendor/autoload.php',
    ];

    foreach ($candidates as $autoloadPath) {
      if (! is_file($autoloadPath)) {
        continue;
      }

      require_once $autoloadPath;

      if (class_exists(\Ichiloto\Engine\Events\Enumerations\LootType::class)) {
        return true;
      }
    }

    return false;
  }
}

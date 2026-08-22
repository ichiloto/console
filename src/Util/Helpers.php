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
   * loaded. The Console carries the Engine through its Editor dependency, and
   * a project may provide its own compatible version through its autoloader.
   *
   * @param string $workingDirectory The project directory.
   * @return bool Whether the engine is loaded.
   */
  function load_engine_autoloader(string $workingDirectory): bool
  {
    $projectAutoloadPath = rtrim($workingDirectory, DIRECTORY_SEPARATOR) . '/vendor/autoload.php';

    if (is_file($projectAutoloadPath)) {
      require_once $projectAutoloadPath;
    } else {
      register_project_psr4_autoloader($workingDirectory);
    }

    if (class_exists(\Ichiloto\Engine\Events\Enumerations\LootType::class)) {
      return true;
    }

    return false;
  }
}

if (! function_exists('register_project_psr4_autoloader') ) {
  /**
   * Registers project-local PSR-4 namespaces when a disposable or source-only
   * project has no generated Composer autoloader yet.
   *
   * Validation evaluates authored PHP map and data files. Those files may use
   * project support classes, so their declared Composer namespace mapping is
   * part of the project-reading contract even when vendor/ is intentionally
   * absent from a copied validation fixture.
   */
  function register_project_psr4_autoloader(string $workingDirectory): bool
  {
    $root = rtrim($workingDirectory, DIRECTORY_SEPARATOR);
    $composerPath = $root . '/composer.json';

    if (! is_file($composerPath)) {
      return false;
    }

    $composer = json_decode((string) file_get_contents($composerPath), true);
    $mappings = is_array($composer) ? ($composer['autoload']['psr-4'] ?? null) : null;

    if (! is_array($mappings) || $mappings === []) {
      return false;
    }

    $normalized = [];
    foreach ($mappings as $prefix => $directories) {
      if (! is_string($prefix)) {
        continue;
      }

      foreach ((array) $directories as $directory) {
        if (is_string($directory) && trim($directory) !== '') {
          $normalized[$prefix][] = trim($directory, '/\\');
        }
      }
    }

    if ($normalized === []) {
      return false;
    }

    spl_autoload_register(static function (string $class) use ($root, $normalized): void {
      foreach ($normalized as $prefix => $directories) {
        if (! str_starts_with($class, $prefix)) {
          continue;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
        foreach ($directories as $directory) {
          $path = $root . ($directory !== '' ? DIRECTORY_SEPARATOR . $directory : '') . DIRECTORY_SEPARATOR . $relative;
          if (is_file($path)) {
            require_once $path;
            return;
          }
        }
      }
    });

    return true;
  }
}

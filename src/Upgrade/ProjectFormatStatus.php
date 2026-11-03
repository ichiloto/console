<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

use Ichiloto\Console\Support\ConsoleInstallation;
use Ichiloto\Console\Support\ProjectEngineFormat;

/**
 * A project's format judged by the Engine that reads it, and how far this
 * Console's upgrade chain reaches. `play`, `edit`, `validate` and `upgrade`
 * all read this one judgement, so they never disagree about a project.
 */
final class ProjectFormatStatus
{
    /** Whether the project's Engine reads the project as it is. */
    public bool $isSupported {
        get => $this->engineVersion === null || $this->projectVersion === $this->engineVersion;
    }

    /** Whether this Console has every step up to the format the project's Engine reads. */
    public bool $canUpgrade {
        get => $this->engineVersion === null || $this->engineVersion <= $this->upgradableVersion;
    }

    /** Why the project cannot be read as it is and what fixes it; null when it can. */
    public ?string $problem {
        get {
            if ($this->isSupported) {
                return null;
            }

            if ($this->projectVersion > $this->engineVersion) {
                return sprintf(
                    "This project's format (%d) is newer than its Engine's (%d). Update the project's Engine: run `composer update ichiloto/engine --with-all-dependencies` in the project directory.",
                    $this->projectVersion,
                    $this->engineVersion,
                );
            }

            if (! $this->canUpgrade) {
                return sprintf(
                    "This project's format (%d) is older than its Engine's (%d), and this Ichiloto Console only has the upgrade steps up to format %d. To update the Console, %s. Then run `ichiloto upgrade` in the project directory.",
                    $this->projectVersion,
                    $this->engineVersion,
                    $this->upgradableVersion,
                    $this->consoleUpdateInstruction,
                );
            }

            return sprintf(
                "This project's format (%d) is older than its Engine's (%d). Run `ichiloto upgrade` in the project directory; it lists what will change before changing anything.",
                $this->projectVersion,
                $this->engineVersion,
            );
        }
    }

    /**
     * @param int $projectVersion The format recorded in the project's ichiloto.json.
     * @param int|null $engineVersion The format the project's Engine reads; null when it predates formats.
     * @param int $upgradableVersion The highest format this Console's upgrade chain reaches.
     * @param string $consoleUpdateInstruction How to update this Console, completing a sentence.
     */
    public function __construct(
        public readonly int $projectVersion,
        public readonly ?int $engineVersion,
        public readonly int $upgradableVersion,
        public readonly string $consoleUpdateInstruction,
    ) {
    }

    public static function read(
        string $projectRoot,
        ?ProjectUpgradeChain $chain = null,
        ProjectEngineFormat $engineFormat = new ProjectEngineFormat(),
        ?ConsoleInstallation $installation = null,
    ): self {
        $configPath = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . ProjectUpgradeContext::CONFIG_FILENAME;

        return new self(
            ProjectConfigFile::getFormatVersion($configPath),
            $engineFormat->getFormat($projectRoot),
            ($chain ?? ProjectUpgradeChain::createDefault())->latestVersion,
            ($installation ?? ConsoleInstallation::detect())->getUpdateInstruction(),
        );
    }
}

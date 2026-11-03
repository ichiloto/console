<?php

declare(strict_types=1);

namespace Ichiloto\Console\Launch;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

/**
 * Decides what a launching command opens, the same way for `play`, `battle`
 * and `edit`: `--renderer=<id>` or `--gui` opens that choice directly,
 * `--renderer=auto` the preferred available one, an interactive terminal
 * without a choice flag is asked from a list that shows why any choice is
 * unavailable, and anything else opens the terminal choice.
 */
final class LaunchTargetSelector
{
    /** @var Closure(string, array<string, string>, Closure(mixed): ?string, string): (int|string) */
    private readonly Closure $prompt;

    /**
     * @param (callable(string $label, array<string, string> $options, Closure(mixed): ?string $validate, string $notice): (int|string))|null $prompt
     *   Asks for one of the options; `$validate` returns why an option cannot be chosen, and `$notice`
     *   (empty when there is nothing to say) gives every unavailable option's full reason, shown above the list.
     */
    public function __construct(?callable $prompt = null)
    {
        $this->prompt = $prompt === null
            ? static function (string $label, array $options, Closure $validate, string $notice): int|string {
                // The list cuts long lines to the terminal's width; the notice is printed whole.
                if ($notice !== '') {
                    warning($notice);
                }

                return select(label: $label, options: $options, validate: $validate);
            }
            : Closure::fromCallable($prompt);
    }

    /**
     * @param bool $canPrompt Whether the run is interactive in a real terminal.
     * @return string The chosen target's ID.
     * @throws InvalidArgumentException When the request names an unknown target or conflicts with itself.
     * @throws RuntimeException When availability is needed and cannot be read, or nothing can be chosen.
     */
    public function selectTarget(
        LaunchRequest $request,
        LaunchTargetSource $targets,
        string $projectDirectory,
        bool $canPrompt,
    ): string {
        $names = $targets->getTargetNames();

        if ($request->targetId === LaunchRequest::AUTO) {
            $preferredId = $targets->readAvailability($projectDirectory)->preferredId
                ?? throw new UnexpectedValueException('No renderer is available for this project.');

            return $this->requireGraphicalAgreement($request, $this->requireKnown($preferredId, $names));
        }

        if ($request->targetId !== null) {
            return $this->requireGraphicalAgreement($request, $this->requireKnown($request->targetId, $names));
        }

        if ($request->graphicalFlags !== []) {
            return $this->requireKnown(LaunchRequest::GRAPHICAL_ID, $names);
        }

        if (! $canPrompt) {
            return $this->requireKnown(LaunchRequest::TERMINAL_ID, $names);
        }

        return $this->askForTarget($targets, $names, $projectDirectory);
    }

    /** @param array<string, string> $names */
    private function askForTarget(LaunchTargetSource $targets, array $names, string $projectDirectory): string
    {
        $notice = [];

        try {
            $availability = $targets->readAvailability($projectDirectory);
        } catch (RuntimeException $exception) {
            // Without a report the list still opens; the notice says availability is unknown and why.
            $availability = new LaunchAvailability([], null);
            $notice[] = 'Availability could not be checked: ' . $exception->getMessage();
        }

        $options = [];

        foreach ($names as $id => $name) {
            $reason = $availability->getUnavailableReason($id);
            $options[$id] = $reason === null ? $name : $name . ' (unavailable)';

            if ($reason !== null) {
                $notice[] = sprintf('%s is unavailable: %s', $name, $reason);
            }
        }

        // An unavailable choice stays in the list; choosing it keeps the list open, its reason printed above.
        $validate = static fn (mixed $id): ?string => is_string($id) && $availability->getUnavailableReason($id) !== null
            ? sprintf('%s is unavailable.', $names[$id] ?? $id)
            : null;

        $selected = ($this->prompt)($targets->promptLabel, $options, $validate, implode(PHP_EOL, $notice));

        if (! is_string($selected)) {
            throw new UnexpectedValueException('The selection did not return a renderer ID.');
        }

        $id = $this->requireKnown($selected, $names);
        $reason = $availability->getUnavailableReason($id);

        if ($reason !== null) {
            throw new UnexpectedValueException(sprintf('%s is unavailable: %s', $names[$id], $reason));
        }

        return $id;
    }

    /** @param array<string, string> $names */
    private function requireKnown(string $id, array $names): string
    {
        $id = strtolower(trim($id));

        if (isset($names[$id])) {
            return $id;
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown renderer "%s". Valid renderer IDs: %s.',
            $id,
            implode(', ', array_keys($names)),
        ));
    }

    private function requireGraphicalAgreement(LaunchRequest $request, string $id): string
    {
        if ($request->graphicalFlags !== [] && $id !== LaunchRequest::GRAPHICAL_ID) {
            throw new InvalidArgumentException($request->describeConflict($id));
        }

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Ichiloto\Console\Battle;

use Closure;
use ReflectionObject;
use ReflectionProperty;
use UnitEnum;

/**
 * Everything a set of battle participants hold, taken once and put back
 * exactly.
 *
 * A simulated battle and a seeded preview run the engine's own actions on
 * the engine's own battlers, and those actions write: health and resources,
 * states and the turns left on them, stat stages, guarding, the critical and
 * elemental feedback a resolver leaves on its target, and anything the
 * classes involved may add later. A report is a reading of the project, not
 * a rehearsal on it, so the whole of what a participant holds is recorded
 * before the reading and restored after it, whether the reading finishes or
 * fails.
 *
 * The recording is exhaustive rather than enumerated. Starting from the
 * roots -- a party, a troop -- every object reachable through a stored
 * property is visited once, and every stored property of it is kept: the
 * scalars as they are, arrays as arrays holding the very same objects, and
 * objects as references to the very same instances, which are themselves
 * visited. Membership and order are the arrays a group stores its members
 * in; a nested value object's contents are its own properties. Properties
 * computed on every read hold nothing and are left alone; static ones are
 * not the participants' to have; enums and closures cannot change.
 * Restoring writes each stored value straight back into its backing storage,
 * past any hook, and returns a property that was uninitialised to that. A
 * read-only property cannot have moved, and its contents are restored by
 * visiting what it holds.
 *
 * @package Ichiloto\Console\Battle
 */
final class ParticipantSnapshot
{
  /**
   * @var array<int, array{object: object, properties: array<int, array{property: ReflectionProperty, initialized: bool, value: mixed}>}>
   *   Every visited object -- held, so its identity stays its own for as long
   *   as the snapshot does -- with each stored property's value as found.
   */
  private array $objects = [];

  private function __construct()
  {
  }

  /**
   * Records everything the given roots hold, directly and through every
   * object they reach.
   *
   * @param object ...$roots The participants: a party, a troop, a battler.
   * @return self The snapshot.
   */
  public static function capture(object ...$roots): self
  {
    $snapshot = new self();
    $queue = array_values($roots);

    while ($queue !== []) {
      $object = array_shift($queue);
      $id = spl_object_id($object);

      if (isset($snapshot->objects[$id]) || $object instanceof UnitEnum || $object instanceof Closure) {
        continue;
      }

      $properties = [];

      foreach (self::storedProperties($object) as $property) {
        $initialized = $property->isInitialized($object);
        $value = $initialized ? $property->getRawValue($object) : null;
        $properties[] = ['property' => $property, 'initialized' => $initialized, 'value' => $value];

        foreach (self::objectsIn($value) as $reached) {
          $queue[] = $reached;
        }
      }

      $snapshot->objects[$id] = ['object' => $object, 'properties' => $properties];
    }

    return $snapshot;
  }

  /**
   * Puts every recorded object back exactly as it was recorded.
   *
   * @return void
   */
  public function restore(): void
  {
    foreach ($this->objects as ['object' => $object, 'properties' => $properties]) {
      foreach ($properties as ['property' => $property, 'initialized' => $initialized, 'value' => $value]) {
        if ($property->isReadOnly()) {
          // Cannot have been reassigned; what it holds was visited and is
          // restored on its own.
          continue;
        }

        if (! $initialized) {
          if ($property->isInitialized($object)) {
            self::uninitialize($object, $property);
          }

          continue;
        }

        $property->setRawValue($object, $value);
      }
    }
  }

  /**
   * Returns the properties an object actually stores, its own and those of
   * every class it extends -- private ones included, which the object's own
   * reflection does not list.
   *
   * @param object $object The object.
   * @return ReflectionProperty[] The stored properties.
   */
  private static function storedProperties(object $object): array
  {
    $properties = [];
    $seen = [];
    $class = new ReflectionObject($object);

    while ($class !== false) {
      foreach ($class->getProperties() as $property) {
        $key = $property->getDeclaringClass()->getName() . '::' . $property->getName();

        if (isset($seen[$key]) || $property->isStatic() || $property->isVirtual()) {
          continue;
        }

        $seen[$key] = true;
        $properties[] = $property;
      }

      $class = $class->getParentClass();
    }

    return $properties;
  }

  /**
   * Returns the objects a stored value reaches: the value itself, or every
   * object anywhere inside an array.
   *
   * @param mixed $value The value.
   * @return object[] The objects reached.
   */
  private static function objectsIn(mixed $value): array
  {
    if (is_object($value)) {
      return [$value];
    }

    if (! is_array($value)) {
      return [];
    }

    $objects = [];

    array_walk_recursive($value, static function (mixed $item) use (&$objects): void {
      if (is_object($item)) {
        $objects[] = $item;
      }
    });

    return $objects;
  }

  /**
   * Returns a typed property to the uninitialised state it was found in.
   *
   * @param object $object The object.
   * @param ReflectionProperty $property The property.
   * @return void
   */
  private static function uninitialize(object $object, ReflectionProperty $property): void
  {
    $name = $property->getName();

    Closure::bind(
      static function (object $subject) use ($name): void {
        unset($subject->{$name});
      },
      null,
      $property->getDeclaringClass()->getName(),
    )($object);
  }
}

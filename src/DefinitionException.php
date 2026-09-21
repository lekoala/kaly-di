<?php

declare(strict_types=1);

namespace Kaly\Di;

use LogicException;
use Psr\Container\ContainerExceptionInterface;

/**
 * Thrown when the container configuration itself is invalid.
 *
 * DefinitionException protects unconditional configuration invariants: facts
 * that are already known and cheap to check, requiring no autoloading or
 * reflection. Examples: mutating locked definitions, defining the same id
 * twice, a merge collision, rebinding an unknown id, a rebind() precondition
 * mismatch, or a factory returning an illegal value once it has been executed.
 *
 * Checks that may autoload or reflect otherwise-unused code are deliberately
 * left to development assertions instead, so that production never visits the
 * services it does not use.
 *
 * It extends LogicException (a programming error) while also implementing the
 * PSR-11 marker interface, because it can surface directly from
 * Container::get() - for instance when a factory returns something other than
 * an object or a class-string. Generic PSR-11 consumers can therefore still
 * catch it, while the concrete type keeps telling exactly what went wrong.
 */
final class DefinitionException extends LogicException implements ContainerExceptionInterface {}

<?php

declare(strict_types=1);

namespace Kaly\Di;

/**
 * A single declarative naming convention: `from` template => `to` template.
 *
 * Grammar is deliberately small: only `{module}` (one or more namespace
 * segments) and `{name}` (a single segment, no backslash). `{name}` is
 * mandatory in the source template, each variable is captured at most once,
 * the target only reuses captured variables, and two placeholders cannot be
 * adjacent without a literal separator in the source template.
 *
 * Matching is a pure string transformation: no autoload, no scan, no cache.
 *
 * @internal
 */
final class ConventionRule
{
    private const ALLOWED = ['module', 'name'];

    /**
     * A namespace segment, following PHP identifier rules (Unicode-aware).
     */
    private const SEGMENT_PATTERN = '[\p{L}_\x80-\xff][\p{L}\p{N}_\x80-\xff]*';

    private const MODULE_PATTERN = self::SEGMENT_PATTERN . '(?:\\\\' . self::SEGMENT_PATTERN . ')*';

    private const NAME_PATTERN = self::SEGMENT_PATTERN;

    public readonly string $from;

    public readonly string $to;

    public readonly ?string $source;

    private readonly string $regex;

    /** @var list<string> */
    private readonly array $fromVars;

    /**
     * @param list<string> $fromVars
     */
    private function __construct(string $from, string $to, ?string $source, string $regex, array $fromVars)
    {
        $this->from = $from;
        $this->to = $to;
        $this->source = $source;
        $this->regex = $regex;
        $this->fromVars = $fromVars;
    }

    public static function parse(string $from, string $to, ?string $source = null): self
    {
        $from = DefinitionGuard::normalizeId($from);
        $to = DefinitionGuard::normalizeId($to);

        if ($from === '' || $to === '') {
            throw new DefinitionException('Convention templates must not be empty.');
        }

        $fromVars = self::placeholders($from, 'source');
        $toVars = self::placeholders($to, 'target');

        $nameCount = count(array_keys($fromVars, 'name', true));
        if ($nameCount !== 1) {
            throw new DefinitionException(
                "Invalid convention `{$from}`: the source template must capture `{name}` exactly once.",
            );
        }
        if (count(array_keys($fromVars, 'module', true)) > 1) {
            throw new DefinitionException(
                "Invalid convention `{$from}`: the source template must capture `{module}` at most once.",
            );
        }
        foreach (array_unique($toVars) as $var) {
            if (!in_array($var, $fromVars, true)) {
                throw new DefinitionException(
                    "Invalid convention `{$from}` => `{$to}`: the target uses `{{$var}}`, which is not captured by the source.",
                );
            }
        }

        self::assertNoAdjacentPlaceholders($from);
        if (!str_contains($from, '\\')) {
            throw new DefinitionException(
                "Invalid convention `{$from}`: the source template must contain at least one namespace separator.",
            );
        }

        return new self($from, $to, $source, self::compile($from), array_values(array_unique($fromVars)));
    }

    /**
     * Compute the candidate class for an interface name, or null when the rule
     * does not apply. Pure string operation, never autoloads.
     */
    public function candidateFor(string $canonical): ?string
    {
        if (preg_match($this->regex, $canonical, $matches) !== 1) {
            return null;
        }
        $candidate = $this->to;
        foreach ($this->fromVars as $var) {
            $candidate = str_replace('{' . $var . '}', (string) ($matches[$var] ?? ''), $candidate);
        }
        /** @var class-string */
        return $candidate;
    }

    public function describe(): string
    {
        $base = "`{$this->from}` => `{$this->to}`";
        return $this->source !== null ? "{$base} (declared by `{$this->source}`)" : $base;
    }

    /**
     * Whether an existing interface is covered by a convention.
     *
     * Pure check on top of Definitions: no autoload of candidates, no cache.
     */
    public static function matches(Definitions $definitions, string $class): bool
    {
        return interface_exists($class, false) && $definitions->conventionCandidates($class) !== [];
    }

    /**
     * Resolve the concrete class for an interface via conventions, or null
     * when no convention applies.
     *
     * Explicit definitions are the caller's business: only call this when the
     * id has none. Conventions never apply to concrete classes.
     *
     * @throws DefinitionException When several conventions match, or the
     *                             candidate cannot serve as implementation.
     * @throws ContainerException When the computed class does not exist.
     */
    public static function classFor(Definitions $definitions, string $id): ?string
    {
        if (!interface_exists($id, false)) {
            return null;
        }
        $candidates = $definitions->conventionCandidates($id);
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) > 1) {
            $lines = array_map(static function (array $candidate): string {
                $line = "- `{$candidate['from']}` => `{$candidate['to']}`";
                return $candidate['source'] !== null ? "{$line} (declared by `{$candidate['source']}`)" : $line;
            }, $candidates);
            throw new DefinitionException(
                "Cannot resolve `{$id}`: several conventions match:\n"
                . implode("\n", $lines)
                . "\nExplicitly bind `{$id}` to choose.",
            );
        }
        $match = $candidates[0];
        $class = $match['candidate'];
        if (!class_exists($class)) {
            $source = $match['source'] !== null ? " (declared by `{$match['source']}`)" : '';
            throw new ContainerException(
                "Class `{$class}` does not exist (via convention `{$match['from']}` => `{$match['to']}` for `{$id}`{$source}).",
            );
        }
        self::assertCandidate($id, $class, $match);
        return $class;
    }

    /**
     * A convention candidate must be a usable implementation, like an explicit
     * binding: compatible, instantiable, and user-defined.
     *
     * @param array{candidate:string,from:string,to:string,source:?string} $match
     * @throws DefinitionException
     */
    private static function assertCandidate(string $id, string $class, array $match): void
    {
        $via = "`{$match['from']}` => `{$match['to']}`";
        $source = $match['source'] !== null ? " (declared by `{$match['source']}`)" : '';
        if (!is_a($class, $id, true)) {
            throw new DefinitionException(
                "The convention {$via} for `{$id}` produced `{$class}`, which does not implement `{$id}`{$source}.",
            );
        }
        if (!ReflectionCache::isInstantiable($class)) {
            throw new DefinitionException(
                "The convention {$via} for `{$id}` produced `{$class}`, which is not instantiable{$source}.",
            );
        }
        if (ReflectionCache::reflection($class)[0]->isInternal()) {
            throw new DefinitionException(
                "The convention {$via} for `{$id}` produced `{$class}`, which is an internal PHP class and must be defined explicitly{$source}.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $template, string $side): array
    {
        if (preg_match_all('/\{([^}]*)\}/', $template, $matches) === false) {
            throw new DefinitionException("Invalid convention {$side} template `{$template}`.");
        }
        $vars = [];
        foreach ($matches[1] as $var) {
            if (!in_array($var, self::ALLOWED, true)) {
                throw new DefinitionException(
                    "Invalid convention {$side} template `{$template}`: unknown placeholder `{{$var}}` (allowed: `{module}`, `{name}`).",
                );
            }
            $vars[] = $var;
        }
        // Anything left over is a stray brace: a template that can never
        // match must fail here, not resolve silently to nothing later.
        $stripped = str_replace(['{module}', '{name}'], '', $template);
        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            throw new DefinitionException(
                "Invalid convention {$side} template `{$template}`: stray braces are only allowed inside `{module}` and `{name}`.",
            );
        }
        return $vars;
    }

    private static function assertNoAdjacentPlaceholders(string $from): void
    {
        $offset = 0;
        $previousEnd = null;
        while (preg_match('/\{(?:module|name)\}/', $from, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $matches[0][1];
            $end = $start + strlen($matches[0][0]);
            if ($previousEnd !== null && $start === $previousEnd) {
                throw new DefinitionException(
                    "Invalid convention `{$from}`: placeholders must be separated by a literal (e.g. `\\`).",
                );
            }
            $previousEnd = $end;
            $offset = $end;
        }
    }

    private static function compile(string $from): string
    {
        $parts = preg_split('/(\{(?:module|name)\})/', $from, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new DefinitionException("Invalid convention source template `{$from}`.");
        }
        $regex = '';
        foreach ($parts as $part) {
            if ($part === '{module}') {
                $regex .= '(?P<module>' . self::MODULE_PATTERN . ')';
            } elseif ($part === '{name}') {
                $regex .= '(?P<name>' . self::NAME_PATTERN . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#u';
    }
}

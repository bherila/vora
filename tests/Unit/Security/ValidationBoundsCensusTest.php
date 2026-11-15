<?php

namespace Tests\Unit\Security;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Validation bounds census: unbounded input is the most common finding of the
 * cross-repository security sweep, so every validation rule set under app/ is read
 * statically and held to two rules.
 *
 * 1. **An `array` (or `list`) rule must have a `max:`, `size:` or `between:` on the
 *    same key.** Without one a client can send a million elements and the app will
 *    validate, loop over and often query for each of them. `array:a,b` (allowed
 *    keys) is bounded by its key list and passes.
 * 2. **A date must have an absolute or relative bound, and a year or month a
 *    range.** A `date`/`date_format` rule needs `after`, `after_or_equal`,
 *    `before`, `before_or_equal` or `date_equals`; a key whose last segment is
 *    `year` or `month` (`year`, `tax_year`, `items.*.month`) needs `between:`,
 *    `in:`, or both `min:` and `max:`. Open-ended windows let one request ask for
 *    every year since 0001. This check is syntactic: it does not prove the *span*
 *    of a from/to window is capped (`to => after_or_equal:from` only orders the
 *    two). Cap the span in code where it drives a loop or a query.
 *
 * Rule sets are found by parsing, not by booting: the `rules()` method of any class
 * (Form Requests, Livewire components, MCP tools), and the rules argument of
 * `->validate()`, `->validateWithBag()`, `Validator::make()` and `validator()`.
 * String rules (`'required|array|max:50'`), arrays of strings, and concatenations
 * starting with a literal (`'max:'.self::LIMIT`) are understood; rule objects and
 * `Rule::...` calls are opaque and neither satisfy nor violate a check. Keys built
 * at runtime are invisible to it.
 *
 * Two ways to accept a finding, both keyed "path/to/File.php::key" and both
 * failing on an entry that matches nothing:
 *
 * - ARRAY_ALLOWLIST / DATE_ALLOWLIST: unbounded by design, with the reason.
 * - BASELINE_FILE: known debt, frozen. A finding that is not in it fails the
 *   census; an entry the code no longer produces fails until it is removed.
 *   Fix an entry rather than adding one; add one only with the reason it
 *   cannot be fixed yet.
 *
 * Ported from x-template's census; the allowlists and baseline are this
 * repository's. It needs nikic/php-parser (through laravel/tinker).
 */
final class ValidationBoundsCensusTest extends TestCase
{
    /** Directories, relative to the repository root, whose PHP files are scanned. */
    private const SCAN_ROOTS = ['app'];

    /**
     * `array` rules that may stay unbounded, keyed "path/to/File.php::key". Give a
     * reason; an entry that matches nothing fails the census.
     *
     * @var array<string, string>
     */
    private const ARRAY_ALLOWLIST = [];

    /**
     * Date, year and month rules that may stay unbounded, keyed like ARRAY_ALLOWLIST.
     *
     * @var array<string, string>
     */
    private const DATE_ALLOWLIST = [];

    /** Frozen findings, relative to this file: {"array": {key: reason}, "date": {key: reason}}. */
    private const BASELINE_FILE = 'ValidationBoundsCensusBaseline.json';

    private const DATE_BOUNDS = ['after', 'after_or_equal', 'before', 'before_or_equal', 'date_equals'];

    /** @var array{array: list<string>, date: list<string>}|null */
    private static ?array $repositoryScan = null;

    public function test_array_rules_declare_a_maximum_size(): void
    {
        $findings = $this->filterAllowlisted($this->scanRepository()['array'], [...self::ARRAY_ALLOWLIST, ...$this->baseline()['array']]);

        self::assertSame([], $findings, $this->fixMessage(
            'These validation keys accept an array of any length:',
            $findings,
            "Fix: add a maximum on the same key, sized to the largest legitimate request:\n"
            ."    'items' => ['required', 'array', 'max:100'],\n"
            ."If the keys are fixed, list them instead: 'array:name,email'. If the size is bounded elsewhere\n"
            .'and cannot be expressed as a rule, add "<file>::<key>" to ARRAY_ALLOWLIST in '.self::class
            .' with the reason. Do not add new findings to '.self::BASELINE_FILE.'.',
        ));
    }

    public function test_date_year_and_month_rules_are_bounded(): void
    {
        $findings = $this->filterAllowlisted($this->scanRepository()['date'], [...self::DATE_ALLOWLIST, ...$this->baseline()['date']]);

        self::assertSame([], $findings, $this->fixMessage(
            'These validation keys accept an open-ended date, year or month:',
            $findings,
            "Fix: bound it to what the app can actually serve:\n"
            ."    'from' => ['required', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:today'],\n"
            ."    'year' => ['required', 'integer', 'between:2000,2100'],\n"
            ."    'month' => ['required', 'integer', 'between:1,12'],\n"
            ."and cap the span of a from/to window in code before it drives a loop or query. If the value is\n"
            .'genuinely unbounded (a birth date stored verbatim), add "<file>::<key>" to DATE_ALLOWLIST in '
            .self::class.' with the reason. Do not add new findings to '.self::BASELINE_FILE.'.',
        ));
    }

    public function test_allowlist_entries_match_a_finding(): void
    {
        $scan = $this->scanRepository();
        $stale = [
            ...$this->staleEntries(self::ARRAY_ALLOWLIST, $scan['array']),
            ...$this->staleEntries(self::DATE_ALLOWLIST, $scan['date']),
        ];

        self::assertSame([], $stale, 'Allowlist entries in '.self::class.' match no unbounded rule any more. '
            .'Remove them: '.implode(', ', $stale));
    }

    public function test_baseline_entries_match_a_finding(): void
    {
        $scan = $this->scanRepository();
        $baseline = $this->baseline();
        $stale = [
            ...$this->staleEntries($baseline['array'], $scan['array']),
            ...$this->staleEntries($baseline['date'], $scan['date']),
        ];

        self::assertSame([], $stale, self::BASELINE_FILE.' lists findings the code no longer produces (fixed, '
            .'renamed or moved). Remove them so the baseline only shrinks: '.implode(', ', $stale));
    }

    public function test_baseline_entries_give_a_reason_and_are_not_also_allowlisted(): void
    {
        $baseline = $this->baseline();

        foreach (['array' => self::ARRAY_ALLOWLIST, 'date' => self::DATE_ALLOWLIST] as $kind => $allowlist) {
            foreach ($baseline[$kind] as $key => $reason) {
                self::assertNotSame('', trim($reason), self::BASELINE_FILE." entry $key needs a reason.");
            }
            self::assertSame([], array_keys(array_intersect_key($baseline[$kind], $allowlist)),
                "These $kind entries are both allowlisted and baselined; keep one.");
        }
    }

    /**
     * Prove the census on fixture source, so it cannot pass by recognising nothing.
     */
    public function test_census_detects_unbounded_rules_in_fixture_source(): void
    {
        $source = <<<'PHP'
        <?php
        class StoreThingRequest extends FormRequest
        {
            public function rules(): array
            {
                $rules = [
                    'tags' => 'required|array',
                    'ids' => ['required', 'list'],
                    'ok_items' => ['required', 'array', 'max:50'],
                    'ok_items.*' => ['integer'],
                    'ok_concat' => ['array', 'max:'.self::LIMIT],
                    'ok_between' => 'array|between:1,5',
                    'ok_keys' => 'array:name,email',
                    'opaque' => [new SomeRule, Rule::in(['a', 'b'])],
                    'items.*.labels' => ['array'],
                    'from' => ['required', 'date'],
                    'to' => ['required', 'date', 'after_or_equal:from'],
                    'issued_on' => 'date_format:Y-m-d',
                    'tax_year' => ['required', 'integer', 'min:2000'],
                    'ok_year' => ['required', 'integer', 'between:2000,2100'],
                    'items.*.month' => 'integer',
                    'ok_month' => 'integer|min:1|max:12',
                ];
                $rules['late'] = 'array';

                return $rules;
            }
        }

        class ThingController
        {
            public function store(Request $request)
            {
                $request->validate(['payload' => 'array', 'name' => 'string']);
                $this->validate($request, ['rows' => ['array', 'size:3']]);
                Validator::make(['ignored' => 'array'], ['bulk' => 'array']);
                validator($request->all(), ['period_year' => 'integer']);
            }
        }
        PHP;

        $findings = $this->scanSource($source, 'Fixture.php');

        self::assertSame([
            'Fixture.php::tags',
            'Fixture.php::ids',
            'Fixture.php::items.*.labels',
            'Fixture.php::late',
            'Fixture.php::payload',
            'Fixture.php::bulk',
        ], $this->keysOf($findings['array']));
        self::assertSame([
            'Fixture.php::from',
            'Fixture.php::issued_on',
            'Fixture.php::tax_year',
            'Fixture.php::items.*.month',
            'Fixture.php::period_year',
        ], $this->keysOf($findings['date']));
    }

    /**
     * @return array{array: array<string, string>, date: array<string, string>}
     */
    private function baseline(): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/'.self::BASELINE_FILE), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $baseline = ['array' => [], 'date' => []];
        foreach (array_keys($baseline) as $kind) {
            $entries = $decoded[$kind] ?? [];
            self::assertIsArray($entries, self::BASELINE_FILE." \"$kind\" must be an object of key => reason.");
            foreach ($entries as $key => $reason) {
                self::assertIsString($reason, self::BASELINE_FILE." entry $key needs a reason string.");
                $baseline[$kind][(string) $key] = $reason;
            }
        }

        return $baseline;
    }

    /**
     * @return array{array: list<string>, date: list<string>}
     */
    private function scanRepository(): array
    {
        if (self::$repositoryScan !== null) {
            return self::$repositoryScan;
        }

        $root = dirname(__DIR__, 3);
        $findings = ['array' => [], 'date' => []];

        foreach (self::SCAN_ROOTS as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/function\s+rules\b|validat/i', $source) !== 1) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);
                $scan = $this->scanSource($source, $relative);
                $findings['array'] = [...$findings['array'], ...$scan['array']];
                $findings['date'] = [...$findings['date'], ...$scan['date']];
            }
        }

        return self::$repositoryScan = $findings;
    }

    /**
     * @return array{array: list<string>, date: list<string>}
     */
    private function scanSource(string $source, string $label): array
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $finder = new NodeFinder;
        $findings = ['array' => [], 'date' => []];

        foreach ($this->ruleSetsIn($ast, $finder) as $ruleSet) {
            foreach ($ruleSet as $key => [$line, $rules]) {
                $location = $label.'::'.$key.'  (line '.$line.')';
                if ($this->isUnboundedArray($rules)) {
                    $findings['array'][] = $location;
                }
                if ($this->isUnboundedDate((string) $key, $rules)) {
                    $findings['date'][] = $location;
                }
            }
        }

        return $findings;
    }

    /**
     * Every rule set in the file, as key => [first line, rule names with parameters].
     *
     * @param  array<Node>  $ast
     * @return list<array<string, array{int, list<string>}>>
     */
    private function ruleSetsIn(array $ast, NodeFinder $finder): array
    {
        $ruleSets = [];

        /** @var list<ClassMethod> $methods */
        $methods = $finder->find($ast, fn (Node $node): bool => $node instanceof ClassMethod
            && $node->name->toLowerString() === 'rules');
        foreach ($methods as $method) {
            $ruleSet = [];
            foreach ($finder->findInstanceOf($method->stmts ?? [], Array_::class) as $array) {
                $this->collectKeyedRules($array, $ruleSet);
            }
            foreach ($finder->findInstanceOf($method->stmts ?? [], Assign::class) as $assign) {
                if ($assign->var instanceof ArrayDimFetch && $assign->var->dim instanceof String_) {
                    $this->addRules($ruleSet, $assign->var->dim->value, $assign->getStartLine(), $assign->expr);
                }
            }
            $ruleSets[] = $ruleSet;
        }

        foreach ($finder->find($ast, fn (Node $node): bool => $node instanceof MethodCall
            || $node instanceof StaticCall || $node instanceof FuncCall) as $call) {
            $rulesArgument = $this->rulesArgumentOf($call);
            if ($rulesArgument instanceof Array_) {
                $ruleSet = [];
                $this->collectKeyedRules($rulesArgument, $ruleSet);
                $ruleSets[] = $ruleSet;
            }
        }

        return $ruleSets;
    }

    private function rulesArgumentOf(Node $call): ?Expr
    {
        $arguments = ($call instanceof MethodCall || $call instanceof StaticCall || $call instanceof FuncCall)
            ? array_values(array_filter($call->args, fn ($argument): bool => $argument instanceof Arg))
            : [];
        $value = fn (int $index): ?Expr => isset($arguments[$index]) ? $arguments[$index]->value : null;

        if ($call instanceof MethodCall && $call->name instanceof Identifier) {
            return match ($call->name->toLowerString()) {
                'validate' => $value(0) instanceof Array_ ? $value(0) : $value(1),
                'validatewithbag' => $value(1),
                default => null,
            };
        }

        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier
            && $call->class->getLast() === 'Validator' && $call->name->toLowerString() === 'make') {
            return $value(1);
        }

        if ($call instanceof FuncCall && $call->name instanceof Name && $call->name->toLowerString() === 'validator') {
            return $value(1);
        }

        return null;
    }

    /**
     * @param  array<string, array{int, list<string>}>  $ruleSet
     */
    private function collectKeyedRules(Array_ $array, array &$ruleSet): void
    {
        foreach ($array->items as $item) {
            if ($item->key instanceof String_) {
                $this->addRules($ruleSet, $item->key->value, $item->getStartLine(), $item->value);
            }
        }
    }

    /**
     * @param  array<string, array{int, list<string>}>  $ruleSet
     */
    private function addRules(array &$ruleSet, string $key, int $line, Expr $value): void
    {
        $rules = [];
        foreach ($value instanceof Array_ ? array_map(fn ($item) => $item->value, $value->items) : [$value] as $rule) {
            $literal = $this->leadingLiteral($rule);
            if ($literal !== null) {
                $rules = [...$rules, ...array_map('trim', explode('|', strtolower($literal)))];
            }
        }

        $ruleSet[$key] = [$ruleSet[$key][0] ?? $line, [...($ruleSet[$key][1] ?? []), ...$rules]];
    }

    /**
     * The literal text a rule starts with: `'max:'.self::LIMIT` gives `max:`.
     */
    private function leadingLiteral(Expr $rule): ?string
    {
        if ($rule instanceof String_) {
            return $rule->value;
        }

        return $rule instanceof Concat ? $this->leadingLiteral($rule->left) : null;
    }

    /**
     * @param  list<string>  $rules
     */
    private function isUnboundedArray(array $rules): bool
    {
        $names = $this->ruleNames($rules);
        $isArray = in_array('list', $names, true) || in_array('array', $rules, true);

        return $isArray && array_intersect($names, ['max', 'size', 'between']) === [];
    }

    /**
     * @param  list<string>  $rules
     */
    private function isUnboundedDate(string $key, array $rules): bool
    {
        $names = $this->ruleNames($rules);

        if (array_intersect($names, ['date', 'date_format']) !== [] && array_intersect($names, self::DATE_BOUNDS) === []) {
            return true;
        }

        $segments = explode('.', $key);
        if (preg_match('/(^|_)(year|month)$/i', end($segments)) !== 1) {
            return false;
        }

        $hasRange = array_intersect($names, ['between', 'in', 'size']) !== []
            || (in_array('min', $names, true) && in_array('max', $names, true));

        return ! $hasRange && array_intersect($names, ['date', 'date_format']) === [];
    }

    /**
     * `max:50` gives `max`; `array:name,email` stays distinct from bare `array`.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    private function ruleNames(array $rules): array
    {
        return array_map(fn (string $rule): string => explode(':', $rule, 2)[0], $rules);
    }

    /**
     * @param  list<string>  $findings
     * @param  array<string, string>  $allowlist
     * @return list<string>
     */
    private function filterAllowlisted(array $findings, array $allowlist): array
    {
        return array_values(array_filter(
            $findings,
            fn (string $finding): bool => ! array_key_exists(explode('  ', $finding)[0], $allowlist),
        ));
    }

    /**
     * @param  array<string, string>  $allowlist
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function staleEntries(array $allowlist, array $findings): array
    {
        return array_values(array_diff(array_keys($allowlist), $this->keysOf($findings)));
    }

    /**
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function keysOf(array $findings): array
    {
        return array_map(fn (string $finding): string => explode('  ', $finding)[0], $findings);
    }

    /**
     * @param  list<string>  $findings
     */
    private function fixMessage(string $problem, array $findings, string $fix): string
    {
        return $problem."\n  - ".implode("\n  - ", $findings)."\n\n".$fix."\n";
    }
}

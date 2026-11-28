<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protects the repository-owned verifier for the PER Coding Style 3.1 rules
 * that the formatter does not implement. Every rule has accepting and rejecting cases.
 */
final class PerCodingStyleDeltaCheckTest extends TestCase
{
    /**
     * @return array{int, string} exit status and standard error output
     */
    private static function check(string $source, string $filename = 'Suit.php'): array
    {
        $directory = sys_get_temp_dir() . '/percs-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $file = $directory . '/' . $filename;

        try {
            file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $source);
            [$status, , $errors] = self::runVerifier(dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php', $file);

            return [$status, $errors];
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    /**
     * Runs the actual verifier and captures its status and both output streams.
     *
     * @return array{int, string, string}
     */
    private static function runVerifier(string $script, ?string $file = null): array
    {
        $command = [PHP_BINARY, $script];
        if ($file !== null) {
            $command[] = $file;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accepted(): array
    {
        return [
            'clone with parentheses' => ["\$a = clone(\$b);\n"],
            'closure with empty body' => ["\$a = function (): void {};\n"],
            'closure with use and empty body' => ["\$a = function () use (\$b): void {};\n"],
            'closure with a statement' => ["\$a = function (): int {\n    return 1;\n};\n"],
            'multi-line array with bracket on the assignment line' => ["\$e = [\n    1,\n];\n"],
            'single-line array as argument' => ["foo([1, 2]);\n"],
            'index access on the next line is not an array' => ["\$x = \$list\n    ['key'];\n"],
            'nested call and array items are separate constructs' => [
                "\$x = [\n    call(1, 2),\n    [3, 4],\n];\n",
            ],
            'nested multiline list matches the normative array example' => [
                "\$x = [\n    'outer',\n    [\n        'nested',\n        'array',\n    ],\n];\n",
            ],
            'nested ternary indentation follows its own condition' => [
                "\$x = \$outer\n    ? (\$inner\n        ? 'yes'\n        : 'no')\n    : 'fallback';\n",
            ],
            'multiline condition does not increase the ternary continuation indentation' => [
                "\$x = \$left\n    || \$right\n    ? 'yes'\n    : 'no';\n",
            ],
            'ternary with a multiline operand keeps its inline operators' => [
                "\$x = \$condition ? compute([\n    1,\n    2,\n]) : null;\n",
            ],
            'split ternary places both operators at the start of continuation lines' => [
                "\$x = \$condition\n    ? fn(): ?int => 5\n    : null;\n",
            ],
            'short ternary and coalescing chains align their own continuations' => [
                "\$a = \$left\n    ?: \$middle\n    ?: \$right;\n\$b = \$left\n    ?? \$middle\n    ?? \$right;\n",
            ],
            'direct and parenthesized logical control conditions share their own indentation' => [
                "if (\n    \$a\n    && (\n        \$b\n        || \$c\n    )\n) {\n    report();\n}\n",
            ],
            'nested named argument uses its own indentation' => [
                "report(\n    value: (\$a\n        + \$b)\n        ?? \$fallback,\n);\n",
            ],
            'nested call argument does not take indentation from the control keyword' => [
                "if (\n    test(\n        \$a\n            && \$b,\n    )\n    && \$c\n) {\n    report();\n}\n",
            ],
            'inline operator before multiline groups stays inline' => [
                "\$value = \$left + compute(\n    1,\n    2,\n) * (\n    \$right\n);\n",
            ],
            'unary signs following return, assignments, and a statement block' => [
                "if (\$a) {\n    \$a = -\$a;\n}\n+ \$a;\nreturn -(+\$a);\n",
            ],
            'expression closing braces and class literals can finish an operand' => [
                "\$x = match (1) {\n    default => 1,\n}\n    + 2;\n\$klass = SomeClass::class\n    ?? 'fallback';\n",
            ],
            'types and references do not act as bitwise operators' => [
                "function &sample(\n    (First&Second)|Third \$arg,\n    ?int &\$ref,\n): First|Second {\n    \$target = &\$ref;\n\n    return \$target;\n}\n",
            ],
            'split types retain their declaration syntax' => [
                "function sample(\n    (First&Second)\n        |Third \$arg,\n): First\n    |Second {\n    return \$arg;\n}\n",
            ],
            'quoted operators and comments do not act as executable operators' => [
                "\$text = 'first\n?? second\n+ third\n?: fourth';\n// + \$right\n/* \$left\n& \$right */\n",
            ],
            'low precedence logical operators can share a continuation level' => [
                "\$result = \$first\n    and \$second\n    or \$third;\n",
            ],
            'switch ending every case with a terminator' => [
                "switch (\$a) {\n    case 1:\n        return 1;\n    case 2:\n    case 3:\n        \$b = 2;\n        break;\n    default:\n        throw new Exception('x');\n}\n",
            ],
            'case terminator after an if block' => [
                "switch (\$a) {\n    case 1:\n        if (\$b) {\n            \$c = 1;\n        }\n        break;\n}\n",
            ],
            'case terminator with a match expression' => [
                "switch (\$a) {\n    case 1:\n        return match (\$b) {\n            1 => 'a',\n            default => 'b',\n        };\n}\n",
            ],
            'multi-line case condition wrapped in parentheses' => [
                "switch (\$a) {\n    case (\n        \$b\n        || \$c\n    ):\n        break;\n}\n",
            ],
            'deliberate fall-through marked with a comment' => [
                "switch (\$a) {\n    case 1:\n        \$b = 1;\n        // No break\n    case 2:\n        break;\n}\n",
            ],
            'deliberate fall-through with a trailing block comment' => [
                "switch (\$a) {\n    case 1:\n        \$b = 1; /* Deliberate fall-through */\n    default:\n        break;\n}\n",
            ],
            'enum whose method declares an anonymous class with a protected method' => [
                "namespace Fixture;\n\nenum Suit\n{\n    case Hearts;\n\n    public function f(): object\n    {\n        return new class {\n            protected function g(): void {}\n        };\n    }\n}\n",
            ],
            'PascalCase enum and uppercase constant' => [
                "namespace Fixture;\n\nenum Suit\n{\n    case DarkBlue;\n\n    private const DEFAULT_COLOR = self::DarkBlue;\n}\n",
            ],
            'named class and methods with magic and anonymous methods' => [
                "namespace Fixture;\n\nfinal class Suit\n{\n    public function __construct() {}\n\n    public function makeColor(): object\n    {\n        return new class {\n            public function colorCode(): int\n            {\n                return 1;\n            }\n        };\n    }\n}\n",
            ],
            'global helper function and embedded fixture are not method declarations' => [
                "function helper_name(): string\n{\n    return 'enum Test { case BAD_STYLE; }';\n}\n",
            ],
            'ternary inside a case label' => ["switch (\$a) {\n    case \$b ? 1 : 2:\n        break;\n}\n"],
            'enum with private constant and method' => [
                "namespace Fixture;\n\nenum Suit: string\n{\n    case Hearts = 'H';\n\n    private const X = 1;\n\n    private function f(): void {}\n}\n",
            ],
        ];
    }

    #[DataProvider('accepted')]
    public function testConformingSourcePasses(string $source): void
    {
        [$status, $errors] = self::check($source);

        self::assertSame(0, $status, $errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function violations(): array
    {
        return [
            'clone without parentheses' => ["\$a = clone \$b;\n", 'clone must be written with parentheses'],
            'closure with spaced empty body' => ["\$a = function () { };\n", 'empty body must be written'],
            'closure with multi-line empty body' => ["\$a = function (): void {\n};\n", 'empty body must be written'],
            'closure empty body without space before brace' => ["\$a = function (): void{};\n", 'empty body must be written'],
            'standalone array bracket as argument' => ["foo(\n    [\n        1,\n    ],\n);\n", 'opening bracket of a multi-line array'],
            'standalone array bracket after assignment' => ["\$a =\n    [\n        1,\n    ];\n", 'opening bracket of a multi-line array'],
            'multi-line array contains two values on the same line' => [
                "return [\n    1, 2,\n    3,\n];\n",
                'each value of a multi-line array must start on a separate line',
            ],
            'two values share a line across a multiline array operand' => [
                "\$x = [\n    call(\n        1,\n    ), 2,\n];\n",
                'each value of a multi-line array must start on a separate line',
            ],
            'split ternary packs both operators onto one line' => [
                "\$x = \$condition\n    ? 'result' : 'fallback';\n",
                'a split ternary must put each operator on its own continuation line',
            ],
            'split ternary keeps the first operator inline' => [
                "\$x = \$condition ? 'result'\n    : 'fallback';\n",
                'a split ternary must put each operator on its own continuation line',
            ],
            'multi-line array opens on the first item line' => [
                "return ['first',\n    'second',\n];\n",
                'the first value of a multi-line array must start on the line after its opening bracket',
            ],
            'multi-line array closes on the last item line' => [
                "return [\n    'first',\n    'second',];\n",
                'the closing bracket of a multi-line array must start on the line after its last value',
            ],
            'split ternary starts its middle operand after the operator line' => [
                "\$x = \$condition\n    ?\n        'yes'\n    : 'no';\n",
                'a split ternary must put each operator on its own continuation line',
            ],
            'overindented binary continuation' => [
                "\$x = \$left\n        + \$right;\n",
                'each split operator continuation must be indented once (+)',
            ],
            'nested arithmetic continuation follows its own operand' => [
                "\$x = \$first\n    ?: (\$left\n    * \$right);\n",
                'each split operator continuation must be indented once (*)',
            ],
            'binary addition remains binary after a match brace' => [
                "\$x = match (1) {\n    default => 1,\n}\n+ 2;\n",
                'each split operator continuation must be indented once (+)',
            ],
            'binary subtraction remains binary after a closure brace' => [
                "\$x = function () {}\n- 2;\n",
                'each split operator continuation must be indented once (-)',
            ],
            'operator after a comment does not start the continuation' => [
                "\$x = \$left\n    /* comment */ + \$right;\n",
                'a split operator must start its operand line (+)',
            ],
            'logical control continuation needs indentation from the keyword' => [
                "if (\n    \$a\n&& \$b\n) {\n    report();\n}\n",
                'each split operator continuation must be indented once (&&)',
            ],
            'split ternary continuations are unindented' => [
                "\$x = \$condition\n? 'yes'\n: 'no';\n",
                'each split ternary continuation must be indented once from its condition',
            ],
            'split ternary continuations have different indentation' => [
                "\$x = \$condition\n    ? 'yes'\n        : 'no';\n",
                'each split ternary continuation must be indented once from its condition',
            ],
            'nested ternary continuations need their own indentation' => [
                "\$x = \$outer\n    ? (\$inner\n    ? 'yes'\n    : 'no')\n    : 'fallback';\n",
                'each split ternary continuation must be indented once from its condition',
            ],
            'case body in braces' => ["switch (\$a) {\n    case 1: {\n        break;\n    }\n}\n", 'must not be wrapped in braces'],
            'last case without terminator' => ["switch (\$a) {\n    case 1:\n        \$b = 1;\n}\n", 'must end with a terminating statement'],
            'middle case without terminator' => ["switch (\$a) {\n    case 1:\n        \$b = 1;\n    case 2:\n        break;\n}\n", 'must end with a terminating statement'],
            'case ending with an if block' => ["switch (\$a) {\n    case 1:\n        if (\$b) {\n            return 1;\n        }\n}\n", 'must end with a terminating statement'],
            'multi-line case condition without parentheses' => [
                "switch (\$a) {\n    case \$b\n        || \$c:\n        break;\n}\n",
                'wrapped in parentheses',
            ],
            'multi-line case condition with closing parenthesis not alone' => [
                "switch (\$a) {\n    case (\n        \$b\n        || \$c):\n        break;\n}\n",
                'stand alone on the last line',
            ],
            'fall-through comment on the last case does not excuse it' => [
                "switch (\$a) {\n    case 1:\n        break;\n    default:\n        \$b = 1;\n        // No break\n}\n",
                'must end with a terminating statement',
            ],
            'unrelated comment does not excuse a fall-through' => [
                "switch (\$a) {\n    case 1:\n        \$b = 1;\n        // set b\n    case 2:\n        break;\n}\n",
                'must end with a terminating statement',
            ],
            'enum with protected constant' => ["namespace Fixture;\n\nenum Suit\n{\n    case Hearts;\n\n    protected const X = 1;\n}\n", 'private instead of protected'],
            'enum with protected method next to an anonymous class' => [
                "namespace Fixture;\n\nenum Suit\n{\n    case Hearts;\n\n    protected function f(): object\n    {\n        return new class {};\n    }\n}\n",
                'private instead of protected',
            ],
            'enum case with underscores' => [
                "namespace Fixture;\n\nenum Suit\n{\n    case DARK_BLUE;\n}\n",
                'enum case names must use PascalCase',
            ],
            'enum case in uppercase' => [
                "namespace Fixture;\n\nenum Suit\n{\n    case BLUE;\n}\n",
                'enum case names must use PascalCase',
            ],
            'snake case method' => [
                "namespace Fixture;\n\nfinal class Suit\n{\n    public function color_code(): void {}\n}\n",
                'method names must use camelCase',
            ],
            'snake case method in an anonymous class with constructor arguments' => [
                "\$v = new class(1) {\n    public function __construct(int \$n) {}\n\n    public function color_code(): void {}\n};\n",
                'method names must use camelCase',
            ],
            'named global class' => [
                "final class Suit {}\n",
                'require a formal namespace',
            ],
            'class declared in the explicit global namespace' => [
                "namespace {\n    final class Suit {}\n}\n",
                'require a formal namespace',
            ],
            'a declaration in the wrong file' => [
                "namespace Fixture;\n\nfinal class Color {}\n",
                'must match its filename',
            ],
            'two named declarations' => [
                "namespace Fixture;\n\nfinal class Suit {}\n\nfinal class Color {}\n",
                'must be in its own file',
            ],
            'switch with alternative syntax' => ["switch (\$a):\n    case 1:\n        break;\nendswitch;\n", 'alternative syntax'],
            'enum with protected member' => ["namespace Fixture;\n\nenum Suit\n{\n    case Hearts;\n\n    protected function f(): void {}\n}\n", 'private instead of protected'],
            'anonymous class attribute on the new line' => ["\$o = new #[Foo] class {};\n", 'start on the line after new'],
            'anonymous class attribute not indented' => ["\$o = new\n#[Foo]\nclass {};\n", 'indented once'],
            'anonymous class declaration not on a new line' => ["\$o = new\n    #[Foo] class {};\n", 'starts on a new line'],
        ];
    }

    #[DataProvider('violations')]
    public function testViolationIsReported(string $source, string $message): void
    {
        [$status, $errors] = self::check($source);

        self::assertSame(1, $status, 'expected a finding');
        self::assertStringContainsString($message, $errors);
    }

    /** @return iterable<string, array{string}> */
    public static function binaryOperators(): iterable
    {
        foreach ([
            '**',
            '*',
            '/',
            '%',
            '+',
            '-',
            '<',
            '<=',
            '>',
            '>=',
            '==',
            '!=',
            '===',
            '!==',
            '<>',
            '<=>',
            '=',
            '+=',
            '-=',
            '*=',
            '**=',
            '/=',
            '.=',
            '%=',
            '&=',
            '|=',
            '^=',
            '<<=',
            '>>=',
            '??=',
            '<<',
            '>>',
            '&',
            '^',
            '|',
            '&&',
            '||',
            'and',
            'xor',
            'or',
            '.',
            'instanceof',
            '??',
            '?:',
        ] as $operator) {
            yield $operator => [$operator];
        }
    }

    #[DataProvider('binaryOperators')]
    public function testBinaryOperatorsEnforceContinuationPlacement(string $operator): void
    {
        [$valid, $validErrors] = self::check("\$result = \$left\n    $operator \$right;\n");
        self::assertSame(0, $valid, $validErrors);

        [$unindented, $indentationErrors] = self::check("\$result = \$left\n$operator \$right;\n");
        self::assertSame(1, $unindented);
        self::assertStringContainsString("each split operator continuation must be indented once ($operator)", $indentationErrors);

        [$trailing, $placementErrors] = self::check("\$result = \$left $operator\n    \$right;\n");
        self::assertSame(1, $trailing);
        self::assertStringContainsString("a split operator must start its operand line ($operator)", $placementErrors);
    }

    /**
     * @return array<string, array{string, string}> source and the message expected on PHP 8.5 or later
     */
    public static function pipeViolations(): array
    {
        return [
            'no space around the operator' => ["\$a = \$b|>trim(...);\n", 'needs a space before and after'],
            'operator at the end of the line' => ["\$a = \$b |>\n    trim(...);\n", 'each line starts with the operator'],
            'first operator is inline in a later split chain' => [
                "\$a = \$b |> trim(...)\n    |> strtoupper(...);\n",
                'each line starts with the operator',
            ],
            'two operators share a line in a split chain' => [
                "\$a = \$b\n    |> trim(...) |> strtoupper(...);\n",
                'each line starts with the operator',
            ],
            'continuation line not indented' => ["\$a = \$b\n|> trim(...);\n", 'must be indented'],
        ];
    }

    #[DataProvider('pipeViolations')]
    public function testPipeOperatorViolations(string $source, string $message): void
    {
        [$status, $errors] = self::check($source);

        self::assertSame(1, $status, 'expected a finding');
        if (PHP_VERSION_ID >= 80500) {
            self::assertStringContainsString($message, $errors);

            return;
        }
        // PHP 8.4 cannot tokenize the operator; the verifier refuses the source instead of passing it.
        self::assertStringContainsString('pipe operator requires a PHP version', $errors);
    }

    public function testConformingPipeChainPassesOnPhp85AndIsRefusedOnEarlierVersions(): void
    {
        [$status, $errors] = self::check("\$a = \$b\n    |> trim(...)\n    |> strtoupper(...);\n\$c = \$d |> trim(...);\n");

        if (PHP_VERSION_ID >= 80500) {
            self::assertSame(0, $status, $errors);

            return;
        }
        self::assertSame(1, $status);
        self::assertStringContainsString('pipe operator requires a PHP version', $errors);
    }

    public function testIndependentPipeChainsKeepTheirOwnLayout(): void
    {
        [$status, $errors] = self::check(
            "pair(\n    \$first |> trim(...),\n    \$second\n        |> trim(...)\n        |> strtoupper(...),\n);\n"
                . "\$value = \$first |> trim(...)\n    ?? \$second\n        |> trim(...)\n        |> strtoupper(...);\n",
        );

        if (PHP_VERSION_ID >= 80500) {
            self::assertSame(0, $status, $errors);

            return;
        }

        self::assertSame(1, $status);
        self::assertStringContainsString('pipe operator requires a PHP version', $errors);
    }

    public function testConformingAnonymousClassWithAttributesPasses(): void
    {
        [$status, $errors] = self::check("\$o = new\n    #[Foo]\n    class {};\n");

        self::assertSame(0, $status, $errors);
    }

    public function testDefaultScopeCoversConfigurationAndScriptFiles(): void
    {
        [$status, $output, $errors] = self::runVerifier(dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php');

        self::assertSame(0, $status, $errors);
        $scanned = (int) preg_replace('/^.*check: (\d+) files.*$/s', '$1', $output);
        self::assertGreaterThan(0, $scanned, $output);
        $expected = 0;
        $root = dirname(__DIR__, 2);
        $skipped = ['vendor', '.git', 'node_modules', '.phpunit.cache', '.phpstan'];
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $entry): bool => !($entry->isDir() && in_array($entry->getFilename(), $skipped, true)),
        );
        foreach (new \RecursiveIteratorIterator($filter) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $expected++;
            }
        }
        self::assertGreaterThanOrEqual(7, $expected);
        self::assertSame($expected, $scanned);
        self::assertFileExists($root . '/.php-cs-fixer.php');
    }

    public function testDefaultScopeChecksHandwrittenPhpInDocs(): void
    {
        $root = sys_get_temp_dir() . '/percs-docs-' . bin2hex(random_bytes(8));
        $script = $root . '/scripts/check-per-cs-3-1.php';
        $example = $root . '/docs/examples/DocumentationProbe.php';

        self::assertTrue(mkdir($root . '/scripts', 0700, true));
        self::assertTrue(mkdir($root . '/docs/examples', 0700, true));
        self::assertTrue(copy(dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php', $script));
        try {
            $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class DocumentationProbe\n{\n    public function checkStyle(): void {}\n}\n";
            file_put_contents($example, $source);
            [$status, $output, $errors] = self::runVerifier($script);
            self::assertSame(0, $status, $errors);
            self::assertStringContainsString('2 files, no findings', $output);

            file_put_contents($example, str_replace('checkStyle', 'check_style', $source));
            [$status, , $errors] = self::runVerifier($script);
            self::assertSame(1, $status);
            self::assertStringContainsString('/docs/examples/DocumentationProbe.php:9: method names must use camelCase', $errors);
        } finally {
            unlink($example);
            unlink($script);
            rmdir($root . '/docs/examples');
            rmdir($root . '/docs');
            rmdir($root . '/scripts');
            rmdir($root);
        }
    }

}

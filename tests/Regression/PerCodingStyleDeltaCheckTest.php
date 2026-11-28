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
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php', $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $errors];
        } finally {
            unlink($file);
            rmdir($directory);
        }
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

    public function testIndependentPipeArgumentsKeepTheirOwnLayout(): void
    {
        [$status, $errors] = self::check(
            "pair(\n    \$first |> trim(...),\n    \$second\n        |> trim(...)\n        |> strtoupper(...),\n);\n",
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
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-per-cs-3-1.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $errors);
        $scanned = (int) preg_replace('/^.*check: (\d+) files.*$/s', '$1', $output);
        self::assertGreaterThan(0, $scanned, $output);
        $expected = 0;
        $root = dirname(__DIR__, 2);
        $skipped = ['vendor', '.git', 'docs', 'node_modules', '.phpunit.cache', '.phpstan'];
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
}

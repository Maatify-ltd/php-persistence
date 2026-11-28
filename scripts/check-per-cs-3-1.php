<?php

declare(strict_types=1);

/**
 * Verifies PHP-FIG PER Coding Style 3.1 requirements beyond the formatter's preset.
 *
 * The token checks cover the 3.1 changes and declaration rules inherited
 * from the baseline, including PSR-1 namespace, file, and method naming rules:
 *
 *  1. clone is written with parentheses;
 *  2. switch: a case body is not wrapped in braces, every non-empty case ends with a
 *     terminating statement (also the last one) unless a comment marks a deliberate
 *     fall-through into the next case, and a multi-line case condition is
 *     wrapped in parentheses whose closing parenthesis and colon stand alone on the
 *     last line;
 *  3. the pipe operator has a space on both sides;
 *  4. a split pipe chain starts each line with the operator and indents continuation lines;
 *  5. a closure with an empty body is written as " {}" on one line;
 *  6. attributes on an anonymous class start on the line after "new", are indented once,
 *     and the class declaration starts on its own line with the same indentation;
 *  7. enum cases use PascalCase and non-public enum members use private;
 *  8. multi-line arrays place each value and the closing bracket on separate
 *     lines, following the array declaration and nested-list rules;
 *  9. split operators start indented continuation lines.
 *
 * Usage: php scripts/check-per-cs-3-1.php [path ...]
 * With no paths it scans repository PHP, including maintained documentation
 * examples, and skips dependency, version-control, and tool cache directories.
 * Exit status 1 when any finding exists or no file was found.
 */

const TERMINATORS = [T_BREAK, T_CONTINUE, T_RETURN, T_THROW, T_GOTO, T_EXIT];
const EXPRESSION_PREVIOUS = [T_VARIABLE, ')', ']', T_STRING, T_CONSTANT_ENCAPSED_STRING, T_STATIC, T_NS_SEPARATOR];
const MAGIC_METHODS = [
    '__construct',
    '__destruct',
    '__call',
    '__callStatic',
    '__get',
    '__set',
    '__isset',
    '__unset',
    '__sleep',
    '__wakeup',
    '__serialize',
    '__unserialize',
    '__toString',
    '__invoke',
    '__set_state',
    '__clone',
    '__debugInfo',
];

/**
 * Delimiters and operators of lower precedence than a pipe in PHP 8.5.
 * Closing delimiters also end the innermost expression.
 */
const PIPE_CHAIN_BOUNDARIES = [
    ')',
    ']',
    '}',
    T_ATTRIBUTE,
    T_CURLY_OPEN,
    T_DOLLAR_OPEN_CURLY_BRACES,
    ',',
    ';',
    '?',
    ':',
    '=',
    '|',
    '^',
    '<',
    '>',
    T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
    T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
    T_BOOLEAN_AND,
    T_BOOLEAN_OR,
    T_COALESCE,
    T_COALESCE_EQUAL,
    T_DOUBLE_ARROW,
    T_IS_EQUAL,
    T_IS_NOT_EQUAL,
    T_IS_IDENTICAL,
    T_IS_NOT_IDENTICAL,
    T_IS_SMALLER_OR_EQUAL,
    T_IS_GREATER_OR_EQUAL,
    T_SPACESHIP,
    T_LOGICAL_AND,
    T_LOGICAL_OR,
    T_LOGICAL_XOR,
    T_PLUS_EQUAL,
    T_MINUS_EQUAL,
    T_MUL_EQUAL,
    T_DIV_EQUAL,
    T_CONCAT_EQUAL,
    T_MOD_EQUAL,
    T_AND_EQUAL,
    T_OR_EQUAL,
    T_XOR_EQUAL,
    T_SL_EQUAL,
    T_SR_EQUAL,
    T_POW_EQUAL,
    T_THROW,
    T_INCLUDE,
    T_INCLUDE_ONCE,
    T_REQUIRE,
    T_REQUIRE_ONCE,
    T_PRINT,
    T_YIELD,
    T_YIELD_FROM,
    T_CLOSE_TAG,
];

/**
 * @return list<array{id: int|string, text: string, line: int, startsLine: bool}> all tokens including whitespace and comments
 */
function tokenize(string $source): array
{
    $tokens = [];
    $line = 1;
    $startsLine = true;
    foreach (token_get_all($source) as $token) {
        [$id, $text, $line] = is_array($token) ? $token : [$token, $token, $line];
        $tokens[] = ['id' => $id, 'text' => $text, 'line' => $line, 'startsLine' => $startsLine];
        $lastBreak = strrpos($text, "\n");
        if ($lastBreak === false) {
            $startsLine = $startsLine && trim($text, " \t") === '';
        } else {
            $line += substr_count($text, "\n");
            $startsLine = trim(substr($text, $lastBreak + 1), " \t") === '';
        }
    }

    return $tokens;
}

/**
 * @param list<array{id: int|string, text: string, line: int, startsLine: bool}> $tokens
 * @return list<array{id: int|string, text: string, line: int, startsLine: bool}> significant tokens only
 */
function significant(array $tokens): array
{
    return array_values(array_filter(
        $tokens,
        static fn(array $token): bool => !in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
}

/**
 * Index of the token that closes the group opened at $open.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function matching(array $sig, int $open): ?int
{
    $depth = 0;
    $count = count($sig);
    for ($i = $open; $i < $count; $i++) {
        $id = $sig[$i]['id'];
        if (in_array($id, ['(', '[', '{', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        } elseif (in_array($id, [')', ']', '}'], true)) {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * Index of the matching opening token for the closing token at $close.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function matchingOpen(array $sig, int $close): ?int
{
    $depth = 0;
    for ($i = $close; $i >= 0; $i--) {
        $id = $sig[$i]['id'];
        if (in_array($id, [')', ']', '}'], true)) {
            $depth++;
        } elseif (in_array($id, ['(', '[', '{', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function firstOnLine(array $sig, int $index): bool
{
    if ($index === 0 || !isset($sig[$index - 1], $sig[$index])) {
        return $index === 0;
    }

    return endsLine($sig[$index - 1], $sig[$index]);
}

/**
 * True when the previous token ends on an earlier line than the current token starts.
 *
 * @param array{id: int|string, text: string, line: int} $previous
 * @param array{id: int|string, text: string, line: int} $current
 */
function endsLine(array $previous, array $current): bool
{
    return $previous['line'] + substr_count($previous['text'], "\n") < $current['line'];
}

/**
 * Leading whitespace width of a source line (1-based).
 *
 * @param list<string> $lines
 */
function indentOf(array $lines, int $line): int
{
    $text = $lines[$line - 1] ?? '';

    return strlen($text) - strlen(ltrim($text, " \t"));
}

/**
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @return list<string>
 */
function checkClone(string $file, array $sig): array
{
    $findings = [];
    foreach ($sig as $i => $token) {
        if ($token['id'] === T_CLONE && ($sig[$i + 1]['text'] ?? '') !== '(') {
            $findings[] = $file . ':' . $token['line'] . ': clone must be written with parentheses';
        }
    }

    return $findings;
}

/**
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @param list<array{line: int, text: string}> $comments
 * @return list<string>
 */
function checkSwitch(string $file, array $sig, array $comments): array
{
    $findings = [];
    foreach ($sig as $i => $token) {
        if ($token['id'] !== T_SWITCH) {
            continue;
        }
        $condition = ($sig[$i + 1]['id'] ?? null) === '(' ? matching($sig, $i + 1) : null;
        $open = $condition === null ? null : $condition + 1;
        if ($open === null || ($sig[$open]['id'] ?? null) !== '{') {
            $findings[] = $file . ':' . $token['line'] . ': switch must use braces (alternative syntax is not allowed)';

            continue;
        }
        $close = matching($sig, $open);
        if ($close === null) {
            continue;
        }

        /** @var list<array{start: int, colon: int}> $labels */
        $labels = [];
        for ($j = $open + 1; $j < $close; $j++) {
            $id = $sig[$j]['id'];
            if ($id === T_CASE || $id === T_DEFAULT) {
                $colon = labelColon($sig, $j, $close);
                if ($colon === null) {
                    $findings[] = $file . ':' . $sig[$j]['line'] . ': case label must end with a colon';

                    continue;
                }
                $labels[] = ['start' => $j, 'colon' => $colon];
                $j = $colon;

                continue;
            }
            if (in_array($id, ['(', '[', '{', T_ATTRIBUTE], true)) {
                $end = matching($sig, $j);
                $j = $end ?? $j;
            }
        }

        foreach ($labels as $position => $label) {
            $limit = isset($labels[$position + 1]) ? $labels[$position + 1]['start'] : $close;
            array_push($findings, ...checkCase($file, $sig, $comments, $label['start'], $label['colon'], $limit, isset($labels[$position + 1])));
        }
    }

    return $findings;
}

/**
 * Index of the colon that ends the label starting at $start, ignoring nested groups and ternaries.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function labelColon(array $sig, int $start, int $limit): ?int
{
    $ternary = 0;
    for ($i = $start + 1; $i < $limit; $i++) {
        $id = $sig[$i]['id'];
        if (in_array($id, ['(', '[', '{', T_ATTRIBUTE], true)) {
            $end = matching($sig, $i);
            if ($end === null) {
                return null;
            }
            $i = $end;

            continue;
        }
        if ($id === '?') {
            $ternary++;
        } elseif ($id === ':') {
            if ($ternary === 0) {
                return $i;
            }
            $ternary--;
        } elseif ($id === ';') {
            return null;
        }
    }

    return null;
}

/**
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @param list<array{line: int, text: string}> $comments
 * @return list<string>
 */
function checkCase(string $file, array $sig, array $comments, int $start, int $colon, int $limit, bool $hasNextCase): array
{
    $findings = [];
    $label = $sig[$start];

    if ($label['id'] === T_CASE && $label['line'] !== $sig[$colon]['line']) {
        $open = $start + 1;
        $close = ($sig[$open]['id'] ?? null) === '(' ? matching($sig, $open) : null;
        if ($close === null || $sig[$open]['line'] !== $label['line'] || $close + 1 !== $colon) {
            $findings[] = $file . ':' . $label['line'] . ': a multi-line case condition must be wrapped in parentheses followed directly by the colon';
        } elseif (!firstOnLine($sig, $close) || $sig[$close]['line'] !== $sig[$colon]['line']) {
            $findings[] = $file . ':' . $sig[$close]['line'] . ': the closing parenthesis and colon of a multi-line case condition must stand alone on the last line';
        } elseif (isset($sig[$colon + 1]) && $sig[$colon + 1]['line'] === $sig[$colon]['line']) {
            $findings[] = $file . ':' . $sig[$colon]['line'] . ': the closing parenthesis and colon of a multi-line case condition must stand alone on the last line';
        }
    }

    $first = $colon + 1;
    $last = $limit - 1;
    if ($first > $last) {
        return $findings;
    }

    if ($sig[$first]['id'] === '{') {
        $findings[] = $file . ':' . $sig[$first]['line'] . ': a case body must not be wrapped in braces';

        return $findings;
    }

    $terminated = $sig[$last]['id'] === ';' && endsWithTerminator($sig, $first, $last);
    if (!$terminated && $hasNextCase && hasFallThroughComment($comments, $sig[$last]['line'], $sig[$limit]['line'])) {
        return $findings;
    }

    if (!$terminated) {
        $findings[] = $file . ':' . $label['line'] . ': a non-empty case must end with a terminating statement (break, continue, return, throw, goto, exit)';
    }

    return $findings;
}

/**
 * True when a comment between the last statement of a case and the next case marks the
 * missing terminating statement as deliberate ("No break", "Deliberate fall-through").
 *
 * @param list<array{line: int, text: string}> $comments
 */
function hasFallThroughComment(array $comments, int $lastStatementLine, int $nextCaseLine): bool
{
    foreach ($comments as $comment) {
        if ($comment['line'] < $lastStatementLine || $comment['line'] > $nextCaseLine) {
            continue;
        }
        if (preg_match('/\b(no[ -]?break|fall[ -]?through|fell through)\b/i', $comment['text']) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function endsWithTerminator(array $sig, int $first, int $semicolon): bool
{
    $i = $semicolon - 1;
    while ($i >= $first) {
        $id = $sig[$i]['id'];
        if ($id === ';') {
            break;
        }
        if ($id === '}') {
            if (($sig[$i + 1]['id'] ?? null) !== ';' || $i + 1 !== $semicolon) {
                break;
            }
            $open = matchingOpen($sig, $i);
            if ($open === null) {
                return false;
            }
            $i = $open - 1;

            continue;
        }
        if ($id === ')' || $id === ']') {
            $open = matchingOpen($sig, $i);
            if ($open === null) {
                return false;
            }
            $i = $open - 1;

            continue;
        }
        $i--;
    }

    $start = $i + 1;

    return $start < $semicolon && in_array($sig[$start]['id'], TERMINATORS, true);
}

/**
 * Collects operator token positions by expression. A separator finishes the
 * current chain without combining separate arguments, branches, or statements.
 * Nested expressions have their own chains.
 *
 * @param list<array{id: int|string, text: string, line: int}> $tokens
 * @return list<non-empty-list<int>>
 */
function pipeChains(array $tokens, int $pipe): array
{
    $chains = [];
    $active = [];
    $depth = 0;
    foreach ($tokens as $i => $token) {
        $id = $token['id'];
        if ($id === $pipe) {
            $active[$depth][] = $i;
        } elseif (in_array($id, ['(', '[', '{', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        } elseif (in_array($id, PIPE_CHAIN_BOUNDARIES, true)) {
            if (isset($active[$depth])) {
                $chains[] = $active[$depth];
                unset($active[$depth]);
            }
            if (in_array($id, [')', ']', '}'], true)) {
                $depth--;
            }
        }
    }

    return [...$chains, ...array_values($active)];
}

/**
 * @param list<array{id: int|string, text: string, line: int, startsLine: bool}> $tokens
 * @param list<array{id: int|string, text: string, line: int, startsLine: bool}> $sig
 * @param list<string> $lines
 * @return list<string>
 */
function checkPipe(string $file, array $tokens, array $sig, array $lines): array
{
    $findings = [];
    $pipe = defined('T_PIPE') ? constant('T_PIPE') : null;
    if (!is_int($pipe)) {
        foreach ($tokens as $i => $token) {
            if ($token['id'] === '|' && ($tokens[$i + 1]['id'] ?? null) === '>') {
                $findings[] = $file . ':' . $token['line'] . ': pipe operator requires a PHP version that supports it';
            }
        }

        return $findings;
    }

    foreach ($tokens as $i => $token) {
        if ($token['id'] === $pipe && (($tokens[$i - 1]['id'] ?? null) !== T_WHITESPACE
            || ($tokens[$i + 1]['id'] ?? null) !== T_WHITESPACE)
        ) {
            $findings[] = $file . ':' . $token['line'] . ': the pipe operator needs a space before and after';
        }
    }

    foreach (pipeChains($sig, $pipe) as $chain) {
        $first = $chain[0];
        $last = $chain[count($chain) - 1];
        $split = $sig[$first]['line'] !== $sig[$last]['line'];
        foreach ($chain as $i) {
            if (firstOnLine($sig, $i) || (isset($sig[$i + 1]) && endsLine($sig[$i], $sig[$i + 1]))) {
                $split = true;
                break;
            }
        }
        if (!$split) {
            continue;
        }

        $start = operatorExpressionStart($sig, $first, extraBoundaries: PIPE_CHAIN_BOUNDARIES);
        $indent = indentOf($lines, $sig[$start]['line']) + 4;
        foreach ($chain as $i) {
            $token = $sig[$i];
            $line = $token['line'];
            if (!$token['startsLine'] || !isset($sig[$i + 1]) || endsLine($token, $sig[$i + 1])) {
                $findings[] = $file . ':' . $line . ': in a split pipe chain each line starts with the operator';
            } elseif (!isIndentation($lines, $line, $indent)) {
                $findings[] = $file . ':' . $line . ': continuation lines of a pipe chain must be indented once';
            }
        }
    }

    return $findings;
}

/**
 * @param list<array{id: int|string, text: string, line: int}> $tokens
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @return list<string>
 */
function checkEmptyClosure(string $file, array $tokens, array $sig): array
{
    $findings = [];
    foreach ($sig as $i => $token) {
        if ($token['id'] !== T_FUNCTION || ($sig[$i + 1]['id'] ?? null) !== '(') {
            continue;
        }
        $close = matching($sig, $i + 1);
        if ($close === null) {
            continue;
        }
        $brace = null;
        for ($k = $close + 1; $k < count($sig); $k++) {
            if ($sig[$k]['id'] === ';' || $sig[$k]['id'] === T_DOUBLE_ARROW) {
                break;
            }
            if ($sig[$k]['id'] === '(') {
                $end = matching($sig, $k);
                $k = $end ?? $k;

                continue;
            }
            if ($sig[$k]['id'] === '{') {
                $brace = $k;

                break;
            }
        }
        if ($brace === null || ($sig[$brace + 1]['id'] ?? null) !== '}') {
            continue;
        }
        $inner = '';
        $started = false;
        foreach ($tokens as $raw) {
            if ($raw === $sig[$brace]) {
                $started = true;

                continue;
            }
            if ($raw === $sig[$brace + 1]) {
                break;
            }
            if ($started) {
                $inner .= $raw['text'];
            }
        }
        $previous = $sig[$brace - 1];
        $gap = '';
        foreach ($tokens as $position => $raw) {
            if ($raw === $sig[$brace]) {
                $gap = $tokens[$position - 1]['id'] === T_WHITESPACE ? $tokens[$position - 1]['text'] : '';

                break;
            }
        }
        if ($inner !== '' || $gap !== ' ' || $previous['line'] !== $sig[$brace]['line']) {
            $findings[] = $file . ':' . $token['line'] . ': a closure with an empty body must be written as " {}" on the same line';
        }
    }

    return $findings;
}

/**
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @param list<string> $lines
 * @return list<string>
 */
function checkAnonymousClassAttributes(string $file, array $sig, array $lines): array
{
    $findings = [];
    foreach ($sig as $i => $token) {
        if ($token['id'] !== T_NEW || ($sig[$i + 1]['id'] ?? null) !== T_ATTRIBUTE) {
            continue;
        }
        $k = $i + 1;
        $firstLine = $sig[$k]['line'];
        $lastEnd = null;
        while (($sig[$k]['id'] ?? null) === T_ATTRIBUTE) {
            $end = matching($sig, $k);
            if ($end === null) {
                break;
            }
            if (!firstOnLine($sig, $k)) {
                $findings[] = $file . ':' . $sig[$k]['line'] . ': each attribute of an anonymous class starts on its own line';
            }
            if (indentOf($lines, $sig[$k]['line']) !== indentOf($lines, $token['line']) + 4) {
                $findings[] = $file . ':' . $sig[$k]['line'] . ': attributes of an anonymous class are indented once relative to the line with new';
            }
            $lastEnd = $end;
            $k = $end + 1;
        }
        if ($firstLine !== $token['line'] + 1) {
            $findings[] = $file . ':' . $firstLine . ': attributes of an anonymous class start on the line after new';
        }
        if ($lastEnd === null || !isset($sig[$lastEnd + 1])) {
            continue;
        }
        $declaration = $sig[$lastEnd + 1];
        if ($declaration['line'] <= $sig[$lastEnd]['line']
            || indentOf($lines, $declaration['line']) !== indentOf($lines, $firstLine)) {
            $findings[] = $file . ':' . $declaration['line'] . ': the anonymous class declaration starts on a new line with the indentation of its attributes';
        }
    }

    return $findings;
}

/** Checks a declared PascalCase name, allowing an intentional one-letter name. */
function usesPascalCase(string $name): bool
{
    return preg_match('/\A[A-Z][a-zA-Z0-9]*\z/', $name) === 1
        && (strlen($name) === 1 || preg_match('/[a-z]/', $name) === 1);
}

/**
 * Finds the opening class body brace, skipping anonymous constructor arguments.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function classBodyStart(array $sig, int $start): ?int
{
    $i = $start + 1;
    if (($sig[$i]['id'] ?? null) === '(') {
        $close = matching($sig, $i);
        if ($close === null) {
            return null;
        }
        $i = $close + 1;
    }
    for (; isset($sig[$i]) && !in_array($sig[$i]['id'], [';', '}'], true); $i++) {
        if ($sig[$i]['id'] === '{') {
            return $i;
        }
    }

    return null;
}

/**
 * Inspects direct declarations in one class body, excluding nested blocks,
 * arguments and attributes. The outer scan finds nested anonymous classes.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @return list<string>
 */
function checkClassBody(string $file, array $sig, int $open, int $close, bool $isEnum): array
{
    $findings = [];
    for ($i = $open + 1; $i < $close; $i++) {
        $token = $sig[$i];
        $id = $token['id'];
        if ($id === T_FUNCTION) {
            $nameIndex = ($sig[$i + 1]['text'] ?? '') === '&' ? $i + 2 : $i + 1;
            $name = $sig[$nameIndex] ?? null;
            if ($name !== null && $name['id'] !== '('
                && !in_array($name['text'], MAGIC_METHODS, true)
                && preg_match('/\A[a-z][a-zA-Z0-9]*\z/', $name['text']) !== 1
            ) {
                $findings[] = $file . ':' . $name['line'] . ': method names must use camelCase';
            }
        } elseif ($isEnum && $id === T_CASE && isset($sig[$i + 1])) {
            if (!usesPascalCase($sig[$i + 1]['text'])) {
                $findings[] = $file . ':' . $token['line'] . ': enum case names must use PascalCase';
            }
        } elseif ($isEnum && $id === T_PROTECTED) {
            $findings[] = $file . ':' . $token['line'] . ': enum members use private instead of protected';
        } elseif (in_array($id, ['(', '[', '{', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $i = matching($sig, $i) ?? $i;
        }
    }

    return $findings;
}

/**
 * Checks PSR-1 named class-like declarations and methods, and enum case style
 * from PER Coding Style 3.1. Standalone functions and anonymous class names
 * are outside the named declaration checks.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @return list<string>
 */
function checkDeclarations(string $file, array $sig): array
{
    $findings = [];
    $hasNamespace = false;
    $namedTypes = 0;
    foreach ($sig as $i => $token) {
        $id = $token['id'];
        $next = $sig[$i + 1]['id'] ?? null;
        if ($id === T_NAMESPACE && in_array($next, [T_STRING, T_NAME_QUALIFIED, '{'], true)) {
            $hasNamespace = $next !== '{';

            continue;
        }
        if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
            || ($sig[$i - 1]['id'] ?? null) === T_DOUBLE_COLON
            || ($next !== T_STRING && !($id === T_CLASS && in_array($next, ['{', '(', T_EXTENDS, T_IMPLEMENTS], true)))
        ) {
            continue;
        }
        $open = classBodyStart($sig, $i);
        $close = $open === null ? null : matching($sig, $open);
        if ($open === null || $close === null) {
            continue;
        }
        if ($next === T_STRING) {
            $name = $sig[$i + 1]['text'];
            $location = $file . ':' . $token['line'] . ': ';
            if (++$namedTypes > 1) {
                $findings[] = $location . 'each named class-like declaration must be in its own file';
            }
            if (!$hasNamespace) {
                $findings[] = $location . 'named class-like declarations require a formal namespace';
            }
            if (!usesPascalCase($name)) {
                $findings[] = $location . 'named class-like declarations must use PascalCase';
            }
            if (basename($file) !== $name . '.php') {
                $findings[] = $location . 'a named class-like declaration must match its filename';
            }
        }
        array_push($findings, ...checkClassBody($file, $sig, $open, $close, $id === T_ENUM));
    }

    return $findings;
}

/**
 * Recognizes the return-type colon after a closure variable or parameter list.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function isClosureReturnTypeColon(array $sig, int $colon): bool
{
    if (($sig[$colon - 1]['id'] ?? null) !== ')') {
        return false;
    }
    $open = matchingOpen($sig, $colon - 1);
    if ($open === null) {
        return false;
    }
    $declaration = $open - (($sig[$open - 1]['text'] ?? '') === '&' ? 2 : 1);

    return in_array($sig[$declaration]['id'] ?? null, [T_FN, T_FUNCTION, T_USE], true);
}

/**
 * Returns the ternary separator for a question mark at expression level, ignoring
 * nested groups and nullable types in closure signatures.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function ternaryColon(array $sig, int $question): ?int
{
    $nested = 0;
    $inReturnType = false;
    for ($i = $question + 1; isset($sig[$i]); $i++) {
        $id = $sig[$i]['id'];
        if (in_array($id, [')', ']', '}', ',', ';', T_CLOSE_TAG], true)) {
            return null;
        }
        if ($id === ':' && isClosureReturnTypeColon($sig, $i)) {
            $inReturnType = true;
        } elseif ($id === T_DOUBLE_ARROW || $id === '{') {
            $inReturnType = false;
        }
        if (in_array($id, ['(', '[', '{', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $end = matching($sig, $i);
            if ($end === null) {
                return null;
            }
            $i = $end;

            continue;
        }
        if ($inReturnType) {
            continue;
        }
        if ($id === '?') {
            $nested++;
        } elseif ($id === ':') {
            if ($nested === 0) {
                return $i;
            }
            $nested--;
        }
    }

    return null;
}

/** Identifies tokens that can finish a left operand of a source operator. */
function endsOperatorOperand(int|string|null $id): bool
{
    return in_array($id, [
        T_VARIABLE,
        T_STRING,
        T_LNUMBER,
        T_DNUMBER,
        T_CONSTANT_ENCAPSED_STRING,
        T_END_HEREDOC,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_LINE,
        T_FILE,
        T_DIR,
        T_CLASS_C,
        T_CLASS,
        T_METHOD_C,
        T_FUNC_C,
        T_NS_C,
        T_TRAIT_C,
        T_PROPERTY_C,
        T_INC,
        T_DEC,
        ')',
        ']',
        '}',
        '"',
        '`',
    ], true);
}

/**
 * A brace can close an operand (such as a match expression or a closure)
 * or a statement which can be followed by a standalone unary expression.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function endsBraceOperand(array $sig, int $close): bool
{
    $open = matchingOpen($sig, $close);
    if ($open === null) {
        return false;
    }
    if (in_array($sig[$open]['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)
        || in_array($sig[$open - 1]['id'] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, '$'], true)
    ) {
        return true;
    }
    for ($i = $open - 1; $i >= 0; $i--) {
        $id = $sig[$i]['id'];
        if ($id === T_MATCH) {
            return true;
        }
        if ($id === T_CLASS) {
            return in_array($sig[$i + 1]['id'], ['{', '(', T_EXTENDS, T_IMPLEMENTS], true);
        }
        if ($id === T_FUNCTION) {
            $parameters = $sig[$i + 1]['text'] === '&' ? $i + 2 : $i + 1;

            return $sig[$parameters]['id'] === '(';
        }
        if (in_array($id, [
            T_IF,
            T_ELSEIF,
            T_ELSE,
            T_WHILE,
            T_FOR,
            T_FOREACH,
            T_SWITCH,
            T_CATCH,
            T_TRY,
            T_FINALLY,
            T_DO,
            T_NAMESPACE,
            T_INTERFACE,
            T_TRAIT,
            T_ENUM,
            T_OPEN_TAG,
            T_OPEN_TAG_WITH_ECHO,
            ';',
            '{',
            '}',
            '[',
            '=',
            '(',
        ], true)) {
            return false;
        }
        if ($id === ')') {
            $i = matchingOpen($sig, $i) ?? $i;
        }
    }

    return false;
}

/**
 * Checks the required space-only indentation at a continuation line.
 *
 * @param list<string> $lines
 */
function isIndentation(array $lines, int $line, int $spaces): bool
{
    $text = $lines[$line - 1] ?? '';

    return substr($text, 0, indentOf($lines, $line)) === str_repeat(' ', $spaces);
}

/**
 * Finds the first token of an expression at its own nesting level.
 * Closing groups inside the expression are skipped while walking backward.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @param list<int|string> $extraBoundaries
 */
function operatorExpressionStart(
    array $sig,
    int $operator,
    bool $lowPrecedence = false,
    bool $shortTernary = false,
    array $extraBoundaries = [],
): int {
    $boundaries = [
        '(',
        '[',
        '{',
        ',',
        ';',
        '=',
        '?',
        ':',
        T_OPEN_TAG,
        T_OPEN_TAG_WITH_ECHO,
        T_ECHO,
        T_RETURN,
        T_THROW,
        T_YIELD,
        T_YIELD_FROM,
        T_DOUBLE_ARROW,
        T_COALESCE_EQUAL,
        T_PLUS_EQUAL,
        T_MINUS_EQUAL,
        T_MUL_EQUAL,
        T_DIV_EQUAL,
        T_CONCAT_EQUAL,
        T_MOD_EQUAL,
        T_AND_EQUAL,
        T_OR_EQUAL,
        T_XOR_EQUAL,
        T_SL_EQUAL,
        T_SR_EQUAL,
        T_POW_EQUAL,
    ];
    if (!$lowPrecedence) {
        array_push($boundaries, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR);
    }
    for ($i = $operator - 1; $i >= 0; $i--) {
        $id = $sig[$i]['id'];
        if ($shortTernary && $id === ':' && ($sig[$i - 1]['id'] ?? null) === '?') {
            $i--;

            continue;
        }
        if (in_array($id, [')', ']', '}'], true) && ($open = matchingOpen($sig, $i)) !== null) {
            $i = $open;

            continue;
        }
        if (in_array($id, $boundaries, true) || in_array($id, $extraBoundaries, true)) {
            return $i + 1;
        }
    }

    return 0;
}

/**
 * Marks operator-shaped tokens in parameter types and references, excluding
 * attributes and default value expressions.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @param array<int, true> $symbols
 */
function markParameterSymbols(array $sig, int $open, int $close, array &$symbols): void
{
    $beforeName = true;
    for ($i = $open + 1; $i < $close; $i++) {
        $id = $sig[$i]['id'];
        if ($id === T_ATTRIBUTE || (!$beforeName && in_array($id, ['(', '[', '{'], true))) {
            $i = matching($sig, $i) ?? $i;
        } elseif ($id === ',') {
            $beforeName = true;
        } elseif ($id === T_VARIABLE) {
            $beforeName = false;
        } elseif ($beforeName && ($id === '|' || $sig[$i]['text'] === '&')) {
            $symbols[$i] = true;
        }
    }
}

/**
 * Collects positions of union/intersection tokens used by PHP declarations.
 * PHP uses the same token IDs for bitwise expressions, type syntax and references.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @return array<int, true>
 */
function declarationSymbols(array $sig): array
{
    $symbols = [];
    $memberTypes = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_STATIC,
        T_ARRAY,
        T_CALLABLE,
        T_FINAL,
        T_READONLY,
        T_PRIVATE,
        T_PROTECTED,
        T_PUBLIC,
        T_PRIVATE_SET,
        T_PROTECTED_SET,
        T_PUBLIC_SET,
        '?',
        '|',
        '(',
        ')',
        T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
        T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
    ];
    foreach ($sig as $i => $token) {
        $kind = $token['id'];
        if (in_array($kind, [T_FUNCTION, T_FN, T_CATCH], true)) {
            $parameter = $i + 1;
            if (($sig[$parameter]['text'] ?? null) === '&') {
                $parameter++;
            }
            // Named declarations have one token between the keyword and the parameter list.
            if (($sig[$parameter]['id'] ?? null) !== '(') {
                $parameter++;
            }
            $end = ($sig[$parameter]['id'] ?? null) === '(' ? matching($sig, $parameter) : null;
            if ($end === null) {
                continue;
            }
            markParameterSymbols($sig, $parameter, $end, $symbols);
            if (($sig[$end + 1]['id'] ?? null) === T_USE && ($sig[$end + 2]['id'] ?? null) === '(') {
                $end = matching($sig, $end + 2) ?? $end;
            }
            if (($sig[$end + 1]['id'] ?? null) !== ':') {
                continue;
            }
            for ($type = $end + 2; isset($sig[$type]) && in_array($sig[$type]['id'], $memberTypes, true); $type++) {
                if ($sig[$type]['text'] === '|' || $sig[$type]['text'] === '&') {
                    $symbols[$type] = true;
                }
            }
        } elseif (in_array($kind, [T_PRIVATE, T_PROTECTED, T_PUBLIC, T_PRIVATE_SET, T_PROTECTED_SET, T_PUBLIC_SET, T_READONLY, T_VAR, T_CONST], true)) {
            $typePositions = [];
            for ($type = $i + 1; isset($sig[$type]) && in_array($sig[$type]['id'], $memberTypes, true); $type++) {
                if ($sig[$type]['text'] === '|' || $sig[$type]['text'] === '&') {
                    $typePositions[$type] = true;
                }
            }
            $afterType = $sig[$type]['id'] ?? null;
            if ($afterType === T_VARIABLE || ($kind === T_CONST && $afterType === '=')) {
                $symbols += $typePositions;
            }
        }
    }

    return $symbols;
}

/**
 * Returns the opening token to which a split logical condition is aligned.
 * Parenthesized subconditions can group their own lines. A call or an indexing
 * expression inside a control condition instead has its own expression rules.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function logicalControlStart(array $sig, int $index): ?int
{
    $anchor = null;
    for ($i = $index - 1; $i > 0; $i--) {
        $id = $sig[$i]['id'];
        if ($id === '(') {
            $before = $sig[$i - 1]['id'];
            if (in_array($before, [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_MATCH, T_CASE], true)) {
                return $anchor ?? $i - 1;
            }
            if (endsOperatorOperand($before)) {
                return null;
            }
            $anchor ??= $i;
        } elseif (in_array($id, ['[', '{', T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            return null;
        } elseif (in_array($id, [')', ']', '}'], true)) {
            $i = matchingOpen($sig, $i) ?? $i;
        }
    }

    return null;
}

/**
 * Checks operators whose placement actually breaks a line. Indentation is
 * relative to their expression, or the keyword for a direct control condition.
 * Inline operators inside otherwise multiline expressions stay inline.
 *
 * @param list<array{id: int|string, text: string, line: int, startsLine: bool}> $sig
 * @param list<string> $lines
 * @return list<string>
 */
function checkSplitBinaryOperators(string $file, array $sig, array $lines): array
{
    $findings = [];
    $operators = [
        T_POW,
        '*',
        '/',
        '%',
        '+',
        '-',
        '<',
        T_IS_SMALLER_OR_EQUAL,
        '>',
        T_IS_GREATER_OR_EQUAL,
        T_IS_EQUAL,
        T_IS_NOT_EQUAL,
        T_IS_IDENTICAL,
        T_IS_NOT_IDENTICAL,
        T_SPACESHIP,
        '=',
        T_PLUS_EQUAL,
        T_MINUS_EQUAL,
        T_MUL_EQUAL,
        T_POW_EQUAL,
        T_DIV_EQUAL,
        T_CONCAT_EQUAL,
        T_MOD_EQUAL,
        T_AND_EQUAL,
        T_OR_EQUAL,
        T_XOR_EQUAL,
        T_SL_EQUAL,
        T_SR_EQUAL,
        T_COALESCE_EQUAL,
        T_SL,
        T_SR,
        T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
        T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
        '^',
        '|',
        T_BOOLEAN_AND,
        T_BOOLEAN_OR,
        T_LOGICAL_AND,
        T_LOGICAL_XOR,
        T_LOGICAL_OR,
        T_COALESCE,
        '.',
        T_INSTANCEOF,
    ];
    $declarations = declarationSymbols($sig);
    foreach ($sig as $i => $token) {
        $shortTernary = $token['id'] === '?' && ($sig[$i + 1]['id'] ?? null) === ':';
        if ((!$shortTernary && !in_array($token['id'], $operators, true))
            || isset($declarations[$i]) || !endsOperatorOperand($sig[$i - 1]['id'] ?? null)
        ) {
            continue;
        }
        $last = $i + (int) $shortTernary;
        $next = $sig[$last + 1] ?? null;
        $first = firstOnLine($sig, $i);
        if ($next === null || (!$first && !endsLine($sig[$i], $next))) {
            continue;
        }
        $line = $token['line'];
        $name = $shortTernary ? '?:' : $token['text'];
        if (in_array($token['id'], ['+', '-'], true) && ($sig[$i - 1]['id'] ?? null) === '}'
            && !endsBraceOperand($sig, $i - 1)
        ) {
            // After a standalone block, a unary sign can begin a new statement.
            continue;
        }
        if (!$first || !$token['startsLine'] || endsLine($sig[$i], $next)) {
            $findings[] = $file . ':' . $line . ': a split operator must start its operand line (' . $name . ')';

            continue;
        }
        $lowPrecedence = in_array($token['id'], [T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR], true);
        $logical = $lowPrecedence || in_array($token['id'], [T_BOOLEAN_AND, T_BOOLEAN_OR], true);
        $control = $logical ? logicalControlStart($sig, $i) : null;
        $start = $control ?? operatorExpressionStart($sig, $i, $lowPrecedence, $shortTernary);
        if (!isIndentation($lines, $line, indentOf($lines, $sig[$start]['line']) + 4)) {
            $findings[] = $file . ':' . $line . ': each split operator continuation must be indented once (' . $name . ')';
        }
    }

    return $findings;
}

/**
 * Checks the two operators of a split ternary. A multiline call or array within
 * an operand does not split the ternary unless an operator itself breaks a line.
 *
 * @param list<array{id: int|string, text: string, line: int, startsLine: bool}> $sig
 * @param list<string> $lines
 * @return list<string>
 */
function checkSplitTernaries(string $file, array $sig, array $lines): array
{
    $findings = [];
    foreach ($sig as $i => $token) {
        if ($token['id'] !== '?' || !isset($sig[$i + 1]) || $sig[$i + 1]['id'] === ':'
            || !endsOperatorOperand($sig[$i - 1]['id'] ?? null)
        ) {
            continue;
        }
        $colon = ternaryColon($sig, $i);
        if ($colon === null || !isset($sig[$colon + 1])) {
            continue;
        }
        $split = firstOnLine($sig, $i) || firstOnLine($sig, $colon)
            || endsLine($token, $sig[$i + 1]) || endsLine($sig[$colon], $sig[$colon + 1]);
        if (!$split) {
            continue;
        }
        if (!$token['startsLine'] || !$sig[$colon]['startsLine']
            || endsLine($token, $sig[$i + 1]) || endsLine($sig[$colon], $sig[$colon + 1])
        ) {
            $findings[] = $file . ':' . $token['line'] . ': a split ternary must put each operator on its own continuation line';

            continue;
        }
        $conditionLine = $sig[operatorExpressionStart($sig, $i)]['line'];
        $indent = indentOf($lines, $conditionLine) + 4;
        foreach ([$token['line'], $sig[$colon]['line']] as $line) {
            if (!isIndentation($lines, $line, $indent)) {
                $findings[] = $file . ':' . $line . ': each split ternary continuation must be indented once from its condition';
            }
        }
    }

    return $findings;
}

/**
 * A multiline unkeyed list value may open on its own line, as shown by
 * the nested array in PER Coding Style 3.1 section 11.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 */
function isNestedListValue(array $sig, int $open): bool
{
    if (!in_array($sig[$open - 1]['id'] ?? null, ['[', ','], true)) {
        return false;
    }
    for ($i = $open - 1; $i > 0; $i--) {
        $id = $sig[$i]['id'];
        if ($id === '[') {
            return !in_array($sig[$i - 1]['id'], EXPRESSION_PREVIOUS, true);
        }
        if (in_array($id, ['(', '{', ';', T_OPEN_TAG], true)) {
            return false;
        }
        if (in_array($id, [')', ']', '}'], true)) {
            $i = matchingOpen($sig, $i) ?? $i;
        }
    }

    return false;
}

/**
 * Checks multiline short-array declarations without inspecting values inside
 * nested expressions, and leaves multiline index access to the base formatter.
 *
 * @param list<array{id: int|string, text: string, line: int}> $sig
 * @return list<string>
 */
function checkArrays(string $file, array $sig): array
{
    $findings = [];
    foreach ($sig as $i => $token) {
        if ($token['id'] !== '[' || $i === 0 || in_array($sig[$i - 1]['id'], EXPRESSION_PREVIOUS, true)) {
            continue;
        }
        $close = matching($sig, $i);
        if ($close === null || $sig[$close]['line'] <= $token['line']) {
            continue;
        }
        if (firstOnLine($sig, $i) && !isNestedListValue($sig, $i)) {
            $findings[] = $file . ':' . $token['line'] . ': the opening bracket of a multi-line array must not stand alone on its line';
        }
        if ($i + 1 === $close || ($sig[$close + 1]['id'] ?? null) === '=' || $sig[$i - 1]['id'] === T_AS) {
            // Empty arrays and short destructuring patterns have no declared values to check.
            continue;
        }
        if (!endsLine($token, $sig[$i + 1])) {
            $findings[] = $file . ':' . $sig[$i + 1]['line'] . ': the first value of a multi-line array must start on the line after its opening bracket';
        }
        $lastValue = $sig[$close - 1]['id'] === ',' ? $close - 2 : $close - 1;
        if (!endsLine($sig[$lastValue], $sig[$close])) {
            $findings[] = $file . ':' . $sig[$close]['line'] . ': the closing bracket of a multi-line array must start on the line after its last value';
        }
        for ($j = $i + 1; $j < $close; $j++) {
            $id = $sig[$j]['id'];
            if (in_array($id, ['(', '[', '{', T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $j = matching($sig, $j) ?? $j;
            } elseif ($id === ',' && $j + 1 < $close && !endsLine($sig[$j - 1], $sig[$j + 1])) {
                $findings[] = $file . ':' . $sig[$j + 1]['line'] . ': each value of a multi-line array must start on a separate line';
            }
        }
    }

    return $findings;
}

/**
 * @return list<string> findings as "file:line: message"
 */
function checkSource(string $file, string $source): array
{
    $tokens = tokenize($source);
    $sig = significant($tokens);
    $lines = explode("\n", $source);
    $comments = [];
    foreach ($tokens as $token) {
        if ($token['id'] === T_COMMENT || $token['id'] === T_DOC_COMMENT) {
            $comments[] = ['line' => $token['line'], 'text' => $token['text']];
        }
    }

    return [
        ...checkClone($file, $sig),
        ...checkSwitch($file, $sig, $comments),
        ...checkPipe($file, $tokens, $sig, $lines),
        ...checkEmptyClosure($file, $tokens, $sig),
        ...checkAnonymousClassAttributes($file, $sig, $lines),
        ...checkDeclarations($file, $sig),
        ...checkSplitTernaries($file, $sig, $lines),
        ...checkSplitBinaryOperators($file, $sig, $lines),
        ...checkArrays($file, $sig),
    ];
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function collectFiles(array $paths): array
{
    $skipped = ['vendor', '.git', 'node_modules', '.phpunit.cache', '.phpstan'];
    $files = [];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $files[] = $path;

            continue;
        }
        if (!is_dir($path)) {
            continue;
        }
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static fn(SplFileInfo $entry): bool => !($entry->isDir() && in_array($entry->getFilename(), $skipped, true)),
        );
        foreach (new RecursiveIteratorIterator($filter) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

/** @var list<string> $argv */
$arguments = array_slice($argv, 1);
$files = collectFiles($arguments !== [] ? $arguments : [dirname(__DIR__)]);

if ($files === []) {
    fwrite(STDERR, "No PHP files found to verify.\n");
    exit(1);
}

$all = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        fwrite(STDERR, 'Cannot read ' . $file . "\n");
        exit(1);
    }
    array_push($all, ...checkSource($file, $source));
}

foreach ($all as $finding) {
    fwrite(STDERR, $finding . "\n");
}

if ($all === []) {
    echo 'PER-CS 3.1 delta check: ' . count($files) . " files, no findings\n";
}
exit($all === [] ? 0 : 1);

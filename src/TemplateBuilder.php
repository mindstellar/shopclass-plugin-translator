<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

use FilesystemIterator;
use Gettext\Extractors\PhpCode;
use Gettext\Generators\Po;
use Gettext\Translations;
use mindstellar\utility\FileSystem;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Makes a .pot for a theme or plugin that ships none, from the texts its PHP files pass to
 * core's translation functions with its own domain.
 */
final class TemplateBuilder
{
    public const MAX_FILES = 3000;
    public const MAX_FILE_BYTES = 1048576;
    public const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git'];

    /** Core's translation functions: [text arg, plural arg or null, context arg or null, domain arg]. */
    private const FUNCTIONS = [
        '__'  => [0, null, null, 1],
        '_e'  => [0, null, null, 1],
        '_n'  => [0, 1, null, 3],
        '_x'  => [0, null, 1, 2],
        '_ex' => [0, null, 1, 2],
    ];

    /** Writes the template to $target and returns how many texts it has; nothing is written when it has none. */
    public static function build(string $folder, string $domain, string $target): int
    {
        $t = self::scan($folder, $domain);
        $n = count($t);
        if ($n === 0) {
            return 0;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not write ' . $dir);
        }
        if (!FileSystem::writeAtomic($target, Po::toString($t), 0644)) {
            throw new RuntimeException('Could not write ' . $target);
        }

        return $n;
    }

    public static function scan(string $folder, string $domain): Translations
    {
        $t = new Translations();
        $t->setHeader('Project-Id-Version', $domain);
        $t->setPluralForms(2, '(n != 1)');
        $root = realpath($folder);
        if ($root === false || !is_dir($root) || $domain === '') {
            return $t;
        }
        foreach (self::files($root) as $rel) {
            self::scanCode((string) file_get_contents($root . '/' . $rel), $rel, $domain, $t);
        }

        return $t;
    }

    /** @return string[] PHP files under $root, relative and sorted, that stay inside it. */
    public static function files(string $root): array
    {
        $root = rtrim($root, '/');
        $dirs = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool {
                return $f->isDir() ? !in_array($f->getFilename(), self::SKIP_DIRS, true) : true;
            }
        );
        $out  = [];
        $seen = 0;
        foreach (new RecursiveIteratorIterator($dirs) as $f) {
            if (count($out) >= self::MAX_FILES || ++$seen > self::MAX_FILES * 10) {
                break;
            }
            if (!$f instanceof SplFileInfo || strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            $real = realpath($f->getPathname());
            if ($real === false || strncmp($real, $root . '/', strlen($root) + 1) !== 0 || !is_file($real)
                || filesize($real) > self::MAX_FILE_BYTES) {
                continue;
            }
            $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        }
        sort($out);

        return $out;
    }

    public static function scanCode(string $code, string $file, string $domain, Translations $t): void
    {
        $tokens = @token_get_all($code);
        $count  = count($tokens);
        $prev   = null;
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $before = $prev;
            $prev   = $tok;
            if (!is_array($tok) || !in_array($tok[0], self::nameTokens(), true)) {
                continue;
            }
            $name = strtolower(ltrim($tok[1], '\\'));
            if (!isset(self::FUNCTIONS[$name]) || self::isNotACall($before)) {
                continue;
            }
            $open = self::next($tokens, $i + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $args = self::args($tokens, $open + 1);
            [$textAt, $pluralAt, $contextAt, $domainAt] = self::FUNCTIONS[$name];
            $callDomain = array_key_exists($domainAt, $args) ? self::literal($args[$domainAt]) : 'core';
            if ($callDomain !== $domain) {
                continue;
            }
            $text    = self::literal($args[$textAt] ?? []);
            $plural  = $pluralAt === null ? '' : self::literal($args[$pluralAt] ?? []);
            $context = $contextAt === null ? '' : self::literal($args[$contextAt] ?? []);
            if ($text === null || $text === '' || $plural === null || $context === null) {
                continue;
            }
            $entry = $t->find($context, $text) ?: $t->insert($context, $text, $plural);
            if ($plural !== '' && !$entry->hasPlural()) {
                $entry->setPlural($plural);
            }
            $entry->addReference($file, (int) $tok[2]);
        }
    }

    /** @return int[] */
    private static function nameTokens(): array
    {
        return defined('T_NAME_FULLY_QUALIFIED') ? [T_STRING, T_NAME_FULLY_QUALIFIED] : [T_STRING];
    }

    /** A method, a static call, a definition or `new` uses the same name for something else. */
    private static function isNotACall($before): bool
    {
        if (!is_array($before)) {
            return false;
        }
        $not = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $not[] = T_NULLSAFE_OBJECT_OPERATOR;
        }

        return in_array($before[0], $not, true);
    }

    private static function next(array $tokens, int $i): ?int
    {
        for ($n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /** @return array<int, array> the significant tokens of each argument */
    private static function args(array $tokens, int $i): array
    {
        $args  = [];
        $arg   = [];
        $depth = 0;
        for ($n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                if (!in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $arg[] = $tok;
                }
                if (in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
                continue;
            }
            if ($depth === 0 && ($tok === ',' || $tok === ')')) {
                if ($arg !== [] || $tok === ',') {
                    $args[] = $arg;
                }
                $arg = [];
                if ($tok === ')') {
                    break;
                }
                continue;
            }
            if (in_array($tok, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($tok, [')', ']', '}'], true)) {
                $depth--;
            }
            $arg[] = $tok;
        }

        return $args;
    }

    /** The text of an argument that is one plain string literal, else null. */
    private static function literal(array $arg): ?string
    {
        if (count($arg) !== 1 || !is_array($arg[0]) || $arg[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return PhpCode::convertString($arg[0][1]);
    }
}

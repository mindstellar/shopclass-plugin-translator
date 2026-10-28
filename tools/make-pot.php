<?php

declare(strict_types=1);

/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Writes languages/translator.pot from every literal __(), _e() and _n() call with the 'translator' domain.
 * Usage: php tools/make-pot.php
 */

$root  = dirname(__DIR__);
$files = ['index.php'];
foreach (['src', 'admin'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);

$str      = '\'((?:\\\\.|[^\'\\\\])*)\'';
$single   = '/\b(?:__|_e)\(\s*' . $str . '\s*,\s*\'translator\'\s*\)/';
$plural   = '/\b_n\(\s*' . $str . '\s*,\s*' . $str . '\s*,[^,]+,\s*\'translator\'\s*\)/';
$unescape = static fn (string $s): string => str_replace(["\\'", '\\\\'], ["'", '\\'], $s);

$strings = [];
$plurals = [];
foreach ($files as $file) {
    $code = (string) file_get_contents($root . '/' . $file);
    $line = static fn (int $offset): int => substr_count($code, "\n", 0, $offset) + 1;
    if (preg_match_all($single, $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $hit) {
            $strings[$unescape($hit[1][0])][] = $file . ':' . $line($hit[0][1]);
        }
    }
    if (preg_match_all($plural, $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $hit) {
            $key            = $unescape($hit[1][0]);
            $plurals[$key]  = [$unescape($hit[2][0]), array_merge($plurals[$key][1] ?? [], [$file . ':' . $line($hit[0][1])])];
        }
    }
}

preg_match('/^Version:\s*(\S+)/m', (string) file_get_contents($root . '/index.php'), $v);
$esc = static fn (string $s): string => addcslashes($s, "\"\\\n");
$out = "msgid \"\"\nmsgstr \"\"\n"
    . '"Project-Id-Version: Translator ' . ($v[1] ?? '') . "\\n\"\n"
    . "\"Report-Msgid-Bugs-To: https://github.com/mindstellar/shopclass-plugin-translator/issues\\n\"\n"
    . "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
    . "\"Content-Transfer-Encoding: 8bit\\n\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\"Language: \\n\"\n";
ksort($strings);
foreach ($strings as $text => $refs) {
    $out .= "\n#: " . implode("\n#: ", array_unique($refs)) . "\nmsgid \"" . $esc((string) $text) . "\"\nmsgstr \"\"\n";
}
ksort($plurals);
foreach ($plurals as $text => [$more, $refs]) {
    $out .= "\n#: " . implode("\n#: ", array_unique($refs)) . "\nmsgid \"" . $esc((string) $text) . "\"\nmsgid_plural \"" . $esc($more)
        . "\"\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
}
file_put_contents($root . '/languages/translator.pot', $out);
echo (count($strings) + count($plurals)) . " strings\n";

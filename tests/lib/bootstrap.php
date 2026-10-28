<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads gettext and core's FileSystem (from SHOPCLASS_CORE, or ../shopclass) and the plugin's
 * classes, with no database and no network.
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

define('ABS_PATH', '/x/');

$core = rtrim((string) (getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 2) . '/../shopclass'), '/');

$gettext = $core . '/oc-includes/vendor/gettext/gettext/src/autoloader.php';
if (!is_file($gettext)) {
    fwrite(STDERR, "gettext is not at $gettext. Run composer install in the core checkout.\n");
    exit(1);
}
require_once $gettext;

$fs = $core . '/oc-includes/osclass/classes/utility/FileSystem.php';
if (!is_file($fs)) {
    fwrite(STDERR, "Core's FileSystem is not at $fs. Set SHOPCLASS_CORE to a Shopclass checkout.\n");
    exit(1);
}
require_once $fs;

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\translator\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!function_exists('__')) {
    function __($text, $domain = '')
    {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $count, $domain = '')
    {
        return (int) $count === 1 ? $single : $plural;
    }
}

function tmpdir(): string
{
    static $dirs = array();
    if ($dirs === array()) {
        register_shutdown_function(static function () use (&$dirs): void {
            foreach ($dirs as $d) {
                if (!is_dir($d)) {
                    continue;
                }
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    @chmod($f->getPathname(), $f->isDir() ? 0755 : 0644);
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($d);
            }
        });
    }
    $d = sys_get_temp_dir() . '/translator-' . bin2hex(random_bytes(6));
    mkdir($d, 0755, true);
    $dirs[] = $d;

    return $d;
}

function compileMo(string $po, string $mo): void
{
    $t = Gettext\Translations::fromPoFile($po);
    file_put_contents($mo, Gettext\Generators\Mo::toString($t));
}

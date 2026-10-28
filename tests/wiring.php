<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\Loader;

$GLOBALS['enabled'] = [];

function osc_content_path()
{
    return $GLOBALS['content'];
}
function osc_theme()
{
    return 'demo';
}
function osc_apply_filter($name, $value)
{
    return $value;
}
class Plugins
{
    public static function listEnabled(): array
    {
        if ($GLOBALS['enabled'] instanceof Throwable) {
            throw $GLOBALS['enabled'];
        }

        return $GLOBALS['enabled'];
    }
}
class RecordingTranslation
{
    public array $calls = [];

    public function _load(string $file, string $domain): void
    {
        $this->calls[] = [basename($file), $domain];
    }
}

/** Loads index.php in its own process with the given core version and returns the hooks it added. */
function hooksFor(?string $version): array
{
    $script = tmpdir() . '/run.php';
    file_put_contents($script, '<?php
define("ABS_PATH", "/x/");
' . ($version !== null ? 'define("OSCLASS_VERSION", ' . var_export($version, true) . ');' : '') . '
$hooks = [];
function osc_plugin_path($f) { return "translator/index.php"; }
function osc_register_plugin($p, $cb) {}
function osc_add_hook($name, $cb, $prio = 10) { global $hooks; $hooks[] = $name; }
require ' . var_export(dirname(__DIR__) . '/index.php', true) . ';
echo json_encode($hooks);
');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));

    return json_decode((string) $out, true) ?: [];
}

harness_section('index.php');
pin('7.0.6 registers the loader hook', ['translator/index.php_uninstall', 'translation_load_after'], hooksFor('7.0.6'));
pin('7.1 registers the loader hook', ['translator/index.php_uninstall', 'translation_load_after'], hooksFor('7.1.0.dev3'));
pin('7.0.5 only warns', ['translator/index.php_uninstall', 'admin_page_header'], hooksFor('7.0.5'));
pin('no version only warns', ['translator/index.php_uninstall', 'admin_page_header'], hooksFor(null));

harness_section('Loader::onLoaded');
$GLOBALS['content'] = tmpdir() . '/';
mkdir($GLOBALS['content'] . 'plugin-data/translator/hi_IN', 0755, true);
touch($GLOBALS['content'] . 'plugin-data/translator/hi_IN/core.mo');
touch($GLOBALS['content'] . 'plugin-data/translator/hi_IN/plugin-alpha.mo');
$GLOBALS['enabled'] = ['alpha/index.php'];
$t = new RecordingTranslation();
Loader::onLoaded($t, 'hi_IN');
pin('loads from the custom languages folder', [['core.mo', 'core'], ['plugin-alpha.mo', 'alpha']], $t->calls);

$log = tmpdir() . '/error.log';
ini_set('error_log', $log);
$GLOBALS['enabled'] = new Error('plugins broke');
$threw = false;
try {
    Loader::onLoaded(new RecordingTranslation(), 'hi_IN');
} catch (Throwable $e) {
    $threw = true;
}
check('never throws', !$threw);
check('logs the failure with the folder', str_contains((string) @file_get_contents($log), 'plugin-data/translator/: plugins broke'));

exit(harness_result());

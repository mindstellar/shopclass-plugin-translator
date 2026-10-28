<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

/** Runs one scenario in its own process, because customDir() remembers its answer. */
function scenario(string $dataPath, string $content, bool $withHelper): array
{
    $script = tmpdir() . '/s' . mt_rand() . '.php';
    file_put_contents($script, '<?php
declare(strict_types=1);
require ' . var_export(__DIR__ . '/lib/bootstrap.php', true) . ';
$GLOBALS["calls"] = [];
function osc_content_path() { return ' . var_export($content, true) . '; }
' . ($withHelper ? 'function osc_plugin_data_path(string $slug): string { $GLOBALS["calls"][] = $slug; return ' . var_export($dataPath, true) . '; }' : '') . '
$a = \mindstellar\translator\Plugin::customDir();
\mindstellar\translator\Plugin::customDir();
\mindstellar\translator\Plugin::install();
echo json_encode([$a, $GLOBALS["calls"]]);
');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));

    return json_decode((string) $out, true) ?: [];
}

$files = static fn (string $d): array => glob($d . '{,*/,*/*/}{*,.htaccess}', GLOB_BRACE) ?: [];

harness_section('core helper answers');
$content = tmpdir() . '/c1/';
$data    = tmpdir() . '/core-made/translator/';
mkdir($data, 0755, true);
[$dir, $calls] = scenario($data, $content, true);
pin('customDir is the helper answer', $data, $dir);
pin('called once with the slug', ['translator'], $calls);
pin('the plugin wrote nothing of its own', [], $files($content));
pin('and no .htaccess in the data folder', [], array_values(array_filter($files($data), static fn ($f) => str_ends_with($f, '.htaccess'))));

harness_section('core helper answers nothing');
$content = tmpdir() . '/c2/';
mkdir($content, 0755, true);
[$dir, $calls] = scenario('', $content, true);
pin('falls back under the content path', $content . 'plugin-data/translator/', $dir);
pin('the helper was still called once', ['translator'], $calls);
check('plugin-data/ is closed', is_file($content . 'plugin-data/.htaccess') && is_file($content . 'plugin-data/index.php'));
check('translator/ has no .htaccess', !file_exists($dir . '.htaccess'));

exit(harness_result());

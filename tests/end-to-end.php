<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\Loader;
use mindstellar\translator\Plugin;
use mindstellar\translator\Source;
use mindstellar\translator\CustomStore;

$site  = realpath(__DIR__ . '/fixtures/site');
$work  = tmpdir() . '/';
$trans = $work . 'translations/';
$GLOBALS['uploads'] = $work . 'uploads/';
$GLOBALS['purged']  = [];
mkdir($trans, 0755, true);
mkdir($GLOBALS['uploads'], 0755, true);

function osc_translations_path()
{
    global $trans;

    return $trans;
}
function osc_uploads_path()
{
    return $GLOBALS['uploads'];
}
function osc_content_path()
{
    return $GLOBALS['custom_root'];
}
function osc_current_admin_locale()
{
    return 'hi_IN';
}
function osc_current_user_locale()
{
    return 'en_US';
}
function osc_purge_page_cache($reason)
{
    $GLOBALS['purged'][] = $reason;
}

$core = rtrim((string) (getenv('SHOPCLASS_CORE') ?: dirname(__DIR__) . '/../shopclass'), '/');
$file = $core . '/oc-includes/osclass/classes/Translation.php';
if (!is_file($file)) {
    fwrite(STDERR, "Translation.php is not at $file\n");
    exit(1);
}
require_once $file;

$GLOBALS['custom_root'] = $work . 'content/';
mkdir($GLOBALS['custom_root'], 0755, true);
Plugin::install();
check('install makes the folder and closes plugin-data/', is_dir(Plugin::customDir()) && is_file($GLOBALS['custom_root'] . 'plugin-data/index.php') && is_file($GLOBALS['custom_root'] . 'plugin-data/.htaccess'));

$src = new Source('core', 'core', 'core', 'Core', $site . '/oc-content/languages/core.pot', $site . '/oc-content/languages/', 'core.po');
$pf  = 'nplurals=2; plural=(n != 1);';
$ref = ['oc-includes/a.php'];

$shipped = $work . 'core.mo';
compileMo($site . '/oc-content/languages/hi_IN/core.po', $shipped);

function fresh(string $shipped, Loader $loader): Translation
{
    $t = new Translation(true);
    $t->_load($shipped, 'core');
    $loader->load($t, 'hi_IN');

    return $t;
}

$store  = Plugin::store();
$loader = new Loader(Plugin::customDir(), 'demo', []);

harness_section('custom text over the shipped one');
$store->set('hi_IN', $src, ['context' => '', 'original' => "Seller's information", 'text' => ['Seller details'], 'replaced' => ['x'], 'references' => $ref], $pf);
$store->set('hi_IN', $src, ['context' => '', 'original' => 'Click, or drop images here, to upload', 'text' => ['Drop files here'], 'replaced' => ['x'], 'references' => []], $pf);
pin('afterWrite ran once per set', ['translator', 'translator'], $GLOBALS['purged']);

$t = fresh($shipped, $loader)->_get();
pin('entry 1 is the custom text', 'Seller details', $t->dgettext('core', "Seller's information"));
pin('entry 2 is the custom text', 'Drop files here', $t->dgettext('core', 'Click, or drop images here, to upload'));
pin('entry 3 keeps the shipped text', '%2$s में %1$s विज्ञापन मिले', $t->dgettext('core', '%1$s listings found in %2$s'));
pin('plural still right', true, $t->dngettext('core', '%d listing', '%d listings', 2) !== '%d listing');

harness_section('without the loader');
$plain = new Translation(true);
$plain->_load($shipped, 'core');
pin('shipped text stays', 'विक्रेता की जानकारी', $plain->_get()->dgettext('core', "Seller's information"));

harness_section('remove');
$store->remove('hi_IN', $src, '', "Seller's information");
$t = fresh($shipped, $loader)->_get();
pin('entry 1 back to shipped', 'विक्रेता की जानकारी', $t->dgettext('core', "Seller's information"));
pin('entry 2 still custom', 'Drop files here', $t->dgettext('core', 'Click, or drop images here, to upload'));

harness_section('compiled cache');
$store->set('hi_IN', $src, ['context' => '', 'original' => 'Hello {name}', 'text' => ['AAAA {name}'], 'replaced' => [], 'references' => []], $pf);
$t = fresh($shipped, $loader)->_get();
pin('first text loads', 'AAAA {name}', $t->dgettext('core', 'Hello {name}'));
$store->set('hi_IN', $src, ['context' => '', 'original' => 'Hello {name}', 'text' => ['BBBB {name}'], 'replaced' => [], 'references' => []], $pf);
$t = fresh($shipped, $loader)->_get();
pin('second text wins in the same second', 'BBBB {name}', $t->dgettext('core', 'Hello {name}'));

harness_section('afterWrite');
$GLOBALS['purged'] = [];
Plugin::afterWrite('hi_IN', $src);
pin('purges the page cache', ['translator'], $GLOBALS['purged']);

exit(harness_result());

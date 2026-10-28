<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use Gettext\Translations;
use mindstellar\translator\admin\Actions;
use mindstellar\translator\admin\Export;
use mindstellar\translator\CustomStore;
use mindstellar\translator\Sources;
use mindstellar\translator\SourceStrings;

$GLOBALS['request'] = [];
$GLOBALS['flashes'] = [];

final class RedirectStop extends RuntimeException
{
}

class Params
{
    public static function getParamEnum($p, array $allowed, $default = null)
    {
        $v = $GLOBALS['request'][$p] ?? null;

        return in_array($v, $allowed, true) ? $v : $default;
    }
}

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function osc_route_admin_url($id, $args = [])
{
    $url = 'ADMIN/index.php?route=' . $id;
    foreach ($args as $k => $v) {
        $url .= '&' . $k . '=' . $v;
    }

    return $url;
}
function osc_redirect_to($url, $code = null)
{
    throw new RedirectStop((string) $url);
}
function osc_add_flash_warning_message($m, $s = '')
{
    $GLOBALS['flashes'][] = ['warning', $m];
}
function osc_add_flash_error_message($m, $s = '')
{
    $GLOBALS['flashes'][] = ['error', $m];
}

class_alias(\mindstellar\translator\admin\Screen::class, 'ScreenForTest');

$site    = realpath(__DIR__ . '/fixtures/site');
$sources = new Sources($site . '/oc-content/languages/', $site . '/oc-content/themes/', $site . '/oc-content/plugins/', 'demo', ['alpha/index.php', 'beta/index.php', 'gamma/index.php']);
$dir     = tmpdir() . '/plugin-data/translator/';
$store   = new CustomStore($dir);
$core    = $sources->find('core');
$seller  = "Seller's information";

$store->set('hi_IN', $core, [
    'context' => '', 'original' => $seller, 'plural' => null,
    'text' => ['मेरा विक्रेता'], 'replaced' => [], 'references' => [],
], SourceStrings::pluralForms($core, 'hi_IN'));

harness_section('full .po');
$out = Export::po($core, 'hi_IN', $store);
$t   = Translations::fromPoString($out);
$all = SourceStrings::read($core, 'hi_IN');
pin('one entry per source text', count($all), count(array_filter(iterator_to_array($t), static fn ($e) => (string) $e->getOriginal() !== '')));
pin('custom text wins over the shipped one', 'मेरा विक्रेता', (string) $t->find('', $seller)->getTranslation());
pin('shipped-only text is kept', 'विक्रेता की जानकारी', (string) Translations::fromPoString(Export::po($core, 'hi_IN', new CustomStore(tmpdir() . '/none/')))->find('', $seller)->getTranslation());
$shippedOnly = null;
foreach ($all as $k => $s) {
    if ($s['translated'] && $s['original'] !== $seller && $s['plural'] === null) {
        $shippedOnly = $s;
        break;
    }
}
check('the fixture has a shipped-only text', $shippedOnly !== null);
pin('the shipped-only text is in the export', $shippedOnly['shipped'][0], (string) $t->find($shippedOnly['context'], $shippedOnly['original'])->getTranslation());
pin('header: Language', 'hi_IN', $t->getHeader('Language'));
pin('header: Plural-Forms', SourceStrings::pluralForms($core, 'hi_IN'), $t->getHeader('Plural-Forms'));
pin('header: source name', 'core', $t->getHeader('Project-Id-Version'));
pin('header: charset', 'text/plain; charset=UTF-8', $t->getHeader('Content-Type'));

$untranslated = array_values(array_filter($all, static fn ($s) => !$s['translated']));
if ($untranslated !== []) {
    pin('an untranslated text has an empty msgstr', '', (string) $t->find($untranslated[0]['context'], $untranslated[0]['original'])->getTranslation());
}

harness_section('plural texts');
$pStore = new CustomStore(tmpdir() . '/plural/');
$plural = static fn (CustomStore $st) => Translations::fromPoString(Export::po($core, 'hi_IN', $st))->find('', '%d listing');
pin('a shipped plural keeps its second form', ['%d विज्ञापन'], $plural(new CustomStore(tmpdir() . '/none/'))->getPluralTranslations());
$pStore->set('hi_IN', $core, [
    'context' => '', 'original' => '%d listing', 'plural' => '%d listings',
    'text' => ['%d मेरा', '%d मेरे'], 'replaced' => [], 'references' => [],
], SourceStrings::pluralForms($core, 'hi_IN'));
pin('a custom plural wins in both forms', ['%d मेरा', ['%d मेरे']], [(string) $plural($pStore)->getTranslation(), $plural($pStore)->getPluralTranslations()]);

harness_section('file names');
pin('po name is source-locale', 'plugin-a.b-hi_IN.po', Export::fileName('hi_IN"/', "plugin-a.b;\r\n"));
pin('zip name is clean', 'translator-hi_IN.zip', Export::zipName('hi_IN/;'));

harness_section('zip');
$zipPlugins = tmpdir() . '/plugins/';
mkdir($zipPlugins);
foreach (['alpha', 'beta', 'gamma'] as $slug) {
    exec('cp -r ' . escapeshellarg($site . '/oc-content/plugins/' . $slug) . ' ' . escapeshellarg($zipPlugins));
}
mkdir($zipPlugins . 'delta/languages', 0755, true);
file_put_contents($zipPlugins . 'delta/index.php', "<?php\n/*\nPlugin Name: Delta\n*/\n");
file_put_contents($zipPlugins . 'delta/languages/delta.pot', "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\"\n");
$zipSources = new Sources($site . '/oc-content/languages/', $site . '/oc-content/themes/', $zipPlugins, 'demo', ['alpha/index.php', 'beta/index.php', 'gamma/index.php', 'delta/index.php']);
check('the fixture has a source with no texts', $zipSources->find('plugin-delta') !== null && SourceStrings::read($zipSources->find('plugin-delta'), 'hi_IN') === []);
[$path, $n] = Export::zip($zipSources, 'hi_IN', $store);
check('the zip file exists', is_file($path));
$zip = new ZipArchive();
check('the zip opens', $zip->open($path) === true);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$expect = ['core-hi_IN.po', 'messages-hi_IN.po', 'plugin-alpha-hi_IN.po', 'plugin-gamma-hi_IN.po', 'theme-demo-hi_IN.po'];
sort($names);
pin('one file per source with texts, none for a source without', $expect, $names);
pin('the count matches', count($expect), $n);
check('there is more than one source', count($names) > 1);
$core1 = Translations::fromPoString((string) $zip->getFromName('core-hi_IN.po'));
pin('the core file has the custom text', 'मेरा विक्रेता', (string) $core1->find('', $seller)->getTranslation());
foreach ($names as $nm) {
    check("$nm parses as a .po with a Language header", Translations::fromPoString((string) $zip->getFromName($nm))->getHeader('Language') === 'hi_IN');
}
$zip->close();
unlink($path);

harness_section('actions');
Actions::$send = static function (string $path, string $name): void {
    $GLOBALS['sent']     = $name;
    $GLOBALS['sentBody'] = (string) file_get_contents($path);
    $GLOBALS['sentPath'] = $path;
};
$state = ['locale' => 'hi_IN', 'source' => $core, 'q' => '', 'filter' => 'all', 'page' => 1];
$GLOBALS['request'] = ['export' => 'full'];
Actions::download($state, $store, $sources);
pin('full export is named source-locale.po', 'core-hi_IN.po', $GLOBALS['sent']);
pin('full export sends the merged po', $out, $GLOBALS['sentBody']);
check('the temp file is deleted after sending', !is_file($GLOBALS['sentPath']));

$GLOBALS['request'] = ['export' => 'zip'];
Actions::download($state, $store, $sources);
pin('zip export is named by locale', 'translator-hi_IN.zip', $GLOBALS['sent']);
pin('zip export sends a zip', 'PK', substr($GLOBALS['sentBody'], 0, 2));
check('the zip temp file is deleted after sending', !is_file($GLOBALS['sentPath']));

pin('zip headers', 'Content-Type: application/zip', Actions::downloadHeaders('x.zip', 5, 'application/zip')[0]);

$GLOBALS['sent'] = null;
Export::$zipClass = 'NoSuchZip';
$GLOBALS['flashes'] = [];
try {
    Actions::download($state, $store, $sources);
} catch (RedirectStop $r) {
}
pin('no zip support: nothing is sent', null, $GLOBALS['sent']);
pin('no zip support: the admin is told', [['error', 'This server cannot make zip files.']], $GLOBALS['flashes']);
try {
    Export::zip($sources, 'hi_IN', $store);
    $zipError = '';
} catch (RuntimeException $e) {
    $zipError = $e->getMessage();
}
pin('a zip error is a sentence the admin can read', 'This server cannot make zip files.', $zipError);

harness_section('links');
$links = Export::links($state);
check('the full link is there', strpos($links, 'export=full') !== false);
check('no zip class: no zip button', strpos($links, 'export=zip') === false);
Export::$zipClass = 'ZipArchive';
$links = Export::links($state);
check('with zip support: the zip button is there', strpos($links, 'export=zip') !== false);
check('links keep the language and source', strpos($links, 'locale=hi_IN') !== false && strpos($links, 'source=core') !== false);

exit(harness_result());

<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use Gettext\Translations;
use mindstellar\translator\CustomStore;
use mindstellar\translator\Source;
use mindstellar\translator\SourceStrings;

$site  = realpath(__DIR__ . '/fixtures/site');
$lang  = $site . '/oc-content/languages/';
$core  = new Source('core', 'core', 'core', 'Core', $lang . 'core.pot', $lang, 'core.po');
$msgs  = new Source('messages', 'messages', 'messages', 'Core messages', $lang . 'messages.pot', $lang, 'messages.po');
$forms = 'nplurals=2; plural=(n != 1);';

$calls = [];
$dir   = tmpdir() . '/plugin-data/translator/';
$store = new CustomStore($dir, static function (string $locale, Source $s) use (&$calls): void {
    $calls[] = [$locale, $s];
});

function tmpLeft(string $dir): array
{
    return glob($dir . '.*.tmp') ?: [];
}

harness_section('set() writes .po and .mo');
$calls = [];
$store->set('hi_IN', $core, ['context' => '', 'original' => "Seller's information", 'text' => ['विक्रेता का विवरण'], 'replaced' => ['विक्रेता की जानकारी'], 'references' => ['oc-includes/a.php']], $forms);
$po = $store->poPath('hi_IN', $core);
$mo = $store->moPath('hi_IN', $core);
pin('po path', $dir . 'hi_IN/core.po', $po);
check('po exists', is_file($po));
check('mo exists', is_file($mo));
check('locale index.php', is_file($dir . 'hi_IN/index.php'));
check('locale .htaccess', is_file($dir . 'hi_IN/.htaccess'));
$text = (string) file_get_contents($po);
check('replaced comment', str_contains($text, '#. replaced: ["विक्रेता की जानकारी"]'), $text);
check('reference line', str_contains($text, "#: oc-includes/a.php\n"));
check('plural header', str_contains($text, '"Plural-Forms: ' . $forms . '\n"'));
check('source header', str_contains($text, '"X-Translator-Source: core\n"'));
check('generator header', str_contains($text, '"X-Generator: translator\n"'));
check('language header', str_contains($text, '"Language: hi_IN\n"'));
pin('po mode', '644', substr(sprintf('%o', fileperms($po)), -3));
pin('afterWrite once', 1, count($calls));
pin('afterWrite args', ['hi_IN', 'core'], [$calls[0][0], $calls[0][1]->name()]);

$key = SourceStrings::key('', "Seller's information");
pin('read() back', [$key => [
    'context'    => '',
    'original'   => "Seller's information",
    'plural'     => null,
    'text'       => ['विक्रेता का विवरण'],
    'replaced'   => ['विक्रेता की जानकारी'],
    'references' => ['oc-includes/a.php'],
]], $store->read('hi_IN', $core));
pin('mo has text', 'विक्रेता का विवरण', Translations::fromMoFile($mo)->find('', "Seller's information")->getTranslation());

harness_section('plural, multi-line, context');
$store->set('hi_IN', $core, ['context' => '', 'original' => '%d listing', 'plural' => '%d listings', 'text' => ['%d विज्ञापन', '%d विज्ञापनें'], 'replaced' => ['%d listing', '%d listings'], 'references' => []], $forms);
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Hello {name}', 'text' => ["Line one\nLine two"], 'replaced' => ["Old one\nOld \"two\""], 'references' => []], $forms);
$store->set('hi_IN', $core, ['context' => 'menu', 'original' => 'Edit', 'text' => ['बदलें'], 'replaced' => ['Edit'], 'references' => []], $forms);
$read = $store->read('hi_IN', $core);
pin('plural forms', ['%d विज्ञापन', '%d विज्ञापनें'], $read[SourceStrings::key('', '%d listing')]['text']);
pin('plural id', '%d listings', $read[SourceStrings::key('', '%d listing')]['plural']);
pin('multi-line text', ["Line one\nLine two"], $read[SourceStrings::key('', 'Hello {name}')]['text']);
pin('multi-line replaced', ["Old one\nOld \"two\""], $read[SourceStrings::key('', 'Hello {name}')]['replaced']);
pin('context entry', ['बदलें'], $read[SourceStrings::key('menu', 'Edit')]['text']);
check('no context-less Edit', !isset($read[SourceStrings::key('', 'Edit')]));
$moT = Translations::fromMoFile($mo);
pin('mo context', 'बदलें', $moT->find('menu', 'Edit')->getTranslation());
pin('mo plural', ['%d विज्ञापनें'], $moT->find('', '%d listing')->getPluralTranslations());
$keys = array_keys($read);
$sorted = $keys;
sort($sorted, SORT_STRING);
pin('entries sorted by key', $sorted, $keys);

harness_section('set() twice replaces');
$store->set('hi_IN', $core, ['context' => '', 'original' => "Seller's information", 'text' => ['दूसरा'], 'replaced' => ['विक्रेता की जानकारी'], 'references' => ['oc-includes/a.php']], $forms);
$text = (string) file_get_contents($po);
pin('one entry for the key', 1, substr_count($text, "msgid \"Seller's information\""));
pin('one replaced comment', 4, substr_count($text, '#. replaced: '));
pin('new text', ['दूसरा'], $store->read('hi_IN', $core)[$key]['text']);

harness_section('remove()');
$calls = [];
$store->remove('hi_IN', $core, 'menu', 'Edit');
$read = $store->read('hi_IN', $core);
check('removed entry gone', !isset($read[SourceStrings::key('menu', 'Edit')]));
check('other entry stays', isset($read[$key]));
pin('afterWrite once on remove', 1, count($calls));
$calls = [];
$store->remove('hi_IN', $core, 'menu', 'Edit');
pin('removing a missing entry writes nothing', 0, count($calls));

harness_section("set() with [''] removes (D5)");
$calls = [];
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Hello {name}', 'text' => [''], 'replaced' => [], 'references' => []], $forms);
check('reset entry gone', !isset($store->read('hi_IN', $core)[SourceStrings::key('', 'Hello {name}')]));
check('reset entry gone from the .po', !str_contains((string) file_get_contents($po), 'msgid "Hello {name}"'));
check('reset entry gone from the .mo', Translations::fromMoFile($mo)->find('', 'Hello {name}') === false);
pin('afterWrite once on reset', 1, count($calls));
$store->set('hi_IN', $core, ['context' => '', 'original' => '%d listing', 'plural' => '%d listings', 'text' => ['', ''], 'replaced' => [], 'references' => []], $forms);
pin('only entry 1 left', [$key], array_keys($store->read('hi_IN', $core)));

$d5Dir   = tmpdir() . '/';
$d5Calls = 0;
$d5Store = new CustomStore($d5Dir, static function () use (&$d5Calls): void {
    $d5Calls++;
});
$d5Store->set('hi_IN', $msgs, ['original' => 'Publish', 'text' => ['x']], $forms);
$d5Store->set('hi_IN', $msgs, ['original' => 'Publish', 'text' => ['']], $forms);
check('reset of the only entry deletes the .po', !is_file($d5Store->poPath('hi_IN', $msgs)));
check('reset of the only entry deletes the .mo', !is_file($d5Store->moPath('hi_IN', $msgs)));
pin('afterWrite on both calls', 2, $d5Calls);

harness_section('removing the last entry deletes both files');
$calls = [];
$store->remove('hi_IN', $core, '', "Seller's information");
check('po deleted', !is_file($po));
check('mo deleted', !is_file($mo));
pin('read() empty', [], $store->read('hi_IN', $core));
pin('afterWrite once on deleting the files', 1, count($calls));

harness_section('replaceAll()');
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Publish', 'text' => ['x'], 'replaced' => [], 'references' => []], $forms);
$calls = [];
$store->replaceAll('hi_IN', $core, [
    SourceStrings::key('', 'Edit') => [
        'context' => 'menu', 'original' => 'Edit', 'plural' => null,
        'text' => ['बदलें'], 'replaced' => ['Edit'], 'references' => [],
    ],
    'blank' => [
        'context' => '', 'original' => 'Blank', 'plural' => null,
        'text' => [''], 'replaced' => [], 'references' => [],
    ],
], $forms);
pin('replaceAll keeps only given', [SourceStrings::key('menu', 'Edit')], array_keys($store->read('hi_IN', $core)));
pin('afterWrite once on replaceAll', 1, count($calls));

harness_section('.mo mtime strictly grows');
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Publish', 'text' => ['same'], 'replaced' => [], 'references' => []], $forms);
clearstatcache();
$m1 = filemtime($mo);
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Publish', 'text' => ['same'], 'replaced' => [], 'references' => []], $forms);
clearstatcache();
$m2 = filemtime($mo);
check('second mtime greater', $m2 > $m1, "$m1 then $m2");
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Publish', 'text' => ['same'], 'replaced' => [], 'references' => []], $forms);
clearstatcache();
check('third mtime greater', filemtime($mo) > $m2);

harness_section('Plural-Forms is only plain arithmetic');
$evil = new Source('messages', 'messages', 'messages', 'Core messages', $lang . 'messages.pot', $lang, 'messages.po');
$evilDir   = tmpdir() . '/';
$evilStore = new CustomStore($evilDir);
$arabic = 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);';
$evilStore->set('hi_IN', $evil, ['original' => 'Publish', 'text' => ['x']], $arabic);
pin('a real complex header is kept', $arabic, Translations::fromPoFile($evilStore->poPath('hi_IN', $evil))->getHeader('Plural-Forms'));
foreach (['nplurals=2; plural=exec($_GET[c]);', 'nplurals=2; plural=(n != 1); system("id")', 'nplurals=2; plural=`ls`;'] as $bad) {
    $evilStore->set('hi_IN', $evil, ['original' => 'Publish', 'text' => ['x']], $bad);
    pin('refused: ' . $bad, $forms, Translations::fromPoFile($evilStore->poPath('hi_IN', $evil))->getHeader('Plural-Forms'));
}
$evilStore->set('hi_IN', $evil, ['original' => 'Contact the seller', 'text' => ['y']], $forms);
$evilPo = $evilStore->poPath('hi_IN', $evil);
file_put_contents($evilPo, str_replace('plural=(n != 1);', 'plural=exec($_GET[c]);', (string) file_get_contents($evilPo)));
$evilStore->remove('hi_IN', $evil, '', 'Contact the seller');
pin('remove() does not keep a hostile header', $forms, Translations::fromPoFile($evilStore->poPath('hi_IN', $evil))->getHeader('Plural-Forms'));

harness_section('nplurals is capped');
$capStore = new CustomStore(tmpdir() . '/');
$capStore->set('hi_IN', $core, ['original' => '%d listing', 'plural' => '%d listings', 'text' => ['a', 'b']], $forms);
$capPo = $capStore->poPath('hi_IN', $core);
foreach (['99999999', '7', '0'] as $n) {
    file_put_contents($capPo, preg_replace('/nplurals=\d+/', 'nplurals=' . $n, (string) file_get_contents($capPo)));
    pin("nplurals=$n falls back to the source's", ['a', 'b'], $capStore->read('hi_IN', $core)[SourceStrings::key('', '%d listing')]['text'] ?? null);
}
$capStore->set('hi_IN', $core, ['original' => 'Publish', 'text' => ['x']], 'nplurals=7; plural=n;');
pin('nplurals=7 is not written', $forms, Translations::fromPoFile($capPo)->getHeader('Plural-Forms'));

harness_section('bad locale');
foreach (['../x', "hi_IN\n", 'hi_IN/', ''] as $bad) {
    $threw = false;
    try {
        $store->poPath($bad, $core);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check('poPath(' . json_encode($bad) . ') throws', $threw);
}
$threw = false;
try {
    $store->set('../hi_IN', $core, ['context' => '', 'original' => 'Publish', 'text' => ['x'], 'replaced' => [], 'references' => []], $forms);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('set() with a bad locale throws', $threw);
$threw = false;
try {
    $store->poPath('hi_IN', new Source('../core', 'core', 'core', 'Core', null, $lang, 'core.po'));
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a bad source name throws', $threw);

harness_section('a failed .po write leaves the .mo alone');
$failDir   = tmpdir() . '/';
$failStore = new CustomStore($failDir);
mkdir($failDir . 'hi_IN', 0755);
mkdir($failStore->poPath('hi_IN', $msgs));
file_put_contents($failStore->moPath('hi_IN', $msgs), 'old mo');
$threw = '';
try {
    $failStore->set('hi_IN', $msgs, ['context' => '', 'original' => 'Publish', 'text' => ['प्रकाशित'], 'replaced' => [], 'references' => []], $forms);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
pin('RuntimeException names the .po', 'Could not write ' . $failStore->poPath('hi_IN', $msgs), $threw);
pin('.mo unchanged', 'old mo', file_get_contents($failStore->moPath('hi_IN', $msgs)));
pin('no .tmp left', [], tmpLeft($failDir . 'hi_IN/'));

harness_section('a failed .mo write puts the old .po back');
$moDir   = tmpdir() . '/';
$moStore = new CustomStore($moDir);
$moStore->set('hi_IN', $msgs, ['original' => 'Publish', 'text' => ['one']], $forms);
$oldPo = file_get_contents($moStore->poPath('hi_IN', $msgs));
unlink($moStore->moPath('hi_IN', $msgs));
mkdir($moStore->moPath('hi_IN', $msgs));
$threw = '';
try {
    $moStore->set('hi_IN', $msgs, ['original' => 'Publish', 'text' => ['two']], $forms);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
pin('RuntimeException names the .mo', 'Could not write ' . $moStore->moPath('hi_IN', $msgs), $threw);
pin('old .po restored', $oldPo, file_get_contents($moStore->poPath('hi_IN', $msgs)));
pin('.mo failure: no .tmp left', [], tmpLeft($moDir . 'hi_IN/'));
unlink($moStore->poPath('hi_IN', $msgs));
try {
    $moStore->set('hi_IN', $msgs, ['original' => 'Publish', 'text' => ['three']], $forms);
} catch (RuntimeException $e) {
}
check('no .po left when there was none before', !is_file($moStore->poPath('hi_IN', $msgs)));

harness_section('merge, backup, restore, removeAll');
$bDir   = tmpdir() . '/';
$bCalls = 0;
$bStore = new CustomStore($bDir, static function () use (&$bCalls): void {
    $bCalls++;
});
$one    = static fn (string $o, string $t): array => ['context' => '', 'original' => $o, 'text' => [$t], 'replaced' => [], 'references' => []];
$bPo    = $bStore->poPath('hi_IN', $core);
$bMo    = $bStore->moPath('hi_IN', $core);
$bBak   = $bStore->backupPath('hi_IN', $core);
pin('backup path sits next to the .po', $bPo . '.bak', $bBak);
$bStore->backup('hi_IN', $core);
pin('no .po means an empty backup', '', is_file($bBak) ? (string) file_get_contents($bBak) : null);
check('an empty backup can be undone', $bStore->hasBackup('hi_IN', $core));
$bStore->replaceAll('hi_IN', $core, [$one('Publish', 'one')], $forms);
$bCalls = 0;
$bStore->restore('hi_IN', $core);
pin('undo of a first upload clears the page cache once', 1, $bCalls);
check('undo of a first upload removes the .po and .mo', !is_file($bPo) && !is_file($bMo));
check('undo of a first upload deletes the backup', !is_file($bBak));
$bStore->replaceAll('hi_IN', $core, [$one('Publish', 'one'), $one('Cancel', 'two')], $forms);
$bStore->merge('hi_IN', $core, [$one('Publish', 'three'), $one('Save', 'four')], $forms);
$merged = $bStore->read('hi_IN', $core);
pin('merge keeps an unrelated entry', ['two'], $merged[SourceStrings::key('', 'Cancel')]['text'] ?? null);
pin('merge overwrites a matching entry', ['three'], $merged[SourceStrings::key('', 'Publish')]['text'] ?? null);
pin('merge adds a new entry', ['four'], $merged[SourceStrings::key('', 'Save')]['text'] ?? null);
$bStore->replaceAll('hi_IN', $core, [$one('Save', 'five')], $forms);
pin('replaceAll drops the others', [SourceStrings::key('', 'Save')], array_keys($bStore->read('hi_IN', $core)));

$saved = (string) file_get_contents($bPo);
$bStore->backup('hi_IN', $core);
pin('backup copies the .po', $saved, (string) file_get_contents($bBak));
check('hasBackup', $bStore->hasBackup('hi_IN', $core));
$bStore->replaceAll('hi_IN', $core, [$one('Cancel', 'six')], $forms);
clearstatcache();
$moMtime = (int) filemtime($bMo);
$bCalls  = 0;
$bStore->restore('hi_IN', $core);
pin('restore clears the page cache once', 1, $bCalls);
pin('restore puts back the exact .po', $saved, (string) file_get_contents($bPo));
pin('restore rebuilds the .mo', ['Save' => 'five'], (static function (string $mo): array {
    $out = [];
    foreach (Translations::fromMoFile($mo) as $tr) {
        $out[$tr->getOriginal()] = $tr->getTranslation();
    }

    return $out;
})($bMo));
clearstatcache();
check('restore makes the .mo newer', (int) filemtime($bMo) > $moMtime);
check('restore deletes the backup', !is_file($bBak));
$threw = false;
try {
    $bStore->restore('hi_IN', $core);
} catch (RuntimeException $e) {
    $threw = true;
}
check('restore with no backup throws', $threw);
pin('restore with no backup leaves the .po', $saved, (string) file_get_contents($bPo));

$bStore->backup('hi_IN', $core);
$bCalls = 0;
pin('removeAll returns the count', 1, $bStore->removeAll('hi_IN', $core));
pin('removeAll clears the page cache once', 1, $bCalls);
check('removeAll deletes the .po and .mo', !is_file($bPo) && !is_file($bMo));
pin('removeAll on nothing is 0', 0, $bStore->removeAll('hi_IN', $core));
$bStore->restore('hi_IN', $core);
pin('restore after removeAll', $saved, (string) file_get_contents($bPo));
check('restore after removeAll writes the .mo', is_file($bMo));
file_put_contents($bBak, 'stale');
$bStore->removeAll('hi_IN', $core);
$bStore->backup('hi_IN', $core);
pin('a backup with no .po replaces the stale one', '', (string) file_get_contents($bBak));

harness_section('read-only dir');
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    echo "SKIP  running as root\n";
} else {
    $roDir   = tmpdir() . '/';
    $roStore = new CustomStore($roDir);
    $roStore->set('hi_IN', $core, ['context' => '', 'original' => 'Publish', 'text' => ['one'], 'replaced' => [], 'references' => []], $forms);
    $before = file_get_contents($roStore->poPath('hi_IN', $core));
    chmod($roDir . 'hi_IN', 0500);
    $threw = false;
    try {
        $roStore->set('hi_IN', $core, ['context' => '', 'original' => 'Publish', 'text' => ['two'], 'replaced' => [], 'references' => []], $forms);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    chmod($roDir . 'hi_IN', 0755);
    check('read-only dir throws RuntimeException', $threw);
    pin('read-only: .po unchanged', $before, file_get_contents($roStore->poPath('hi_IN', $core)));
    pin('read-only: no .tmp left', [], tmpLeft($roDir . 'hi_IN/'));
}

exit(harness_result());

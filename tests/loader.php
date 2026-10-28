<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use Gettext\Translations;
use Gettext\Translator;
use mindstellar\translator\Loader;

class FakeTranslation
{
    public array $calls = [];

    public function _load(string $file, string $domain): void
    {
        $this->calls[] = [basename($file), $domain];
    }
}

$site = realpath(__DIR__ . '/fixtures/site');
$root = tmpdir();
$dir  = $root . '/custom/';
mkdir($dir . 'hi_IN', 0755, true);
// Real folders a bad locale could reach, so the pins below fail without the locale check.
foreach (['/x/hi_IN', "/custom/hi_IN\n", '/custom/hi_IN/hi_IN'] as $reach) {
    mkdir($root . $reach, 0755, true);
    touch($root . $reach . '/core.mo');
}
foreach (['core', 'messages', 'theme-demo', 'theme-other', 'plugin-alpha', 'plugin-beta', 'plugin-zeta', 'plugin-odd.old', 'evil'] as $n) {
    touch($dir . "hi_IN/$n.mo");
}
touch($dir . 'hi_IN/core.po');

harness_section('which files load');
$loader = new Loader($dir, 'demo', ['alpha/index.php', 'zeta/index.php', 'odd.old/index.php']);
$t      = new FakeTranslation();
$paths  = $loader->load($t, 'hi_IN');
pin('calls in order', [
    ['core.mo', 'core'], ['messages.mo', 'messages'], ['theme-demo.mo', 'demo'],
    ['plugin-alpha.mo', 'alpha'], ['plugin-zeta.mo', 'zeta'],
], $t->calls);
pin('returned paths', 5, count($paths));
pin('first returned path', $dir . 'hi_IN/core.mo', $paths[0]);

foreach (['../x/hi_IN', "hi_IN\n", 'hi_IN/hi_IN'] as $bad) {
    $t = new FakeTranslation();
    pin('bad locale ' . json_encode($bad) . ' loads nothing', [], $loader->load($t, $bad));
    pin('bad locale ' . json_encode($bad) . ' calls nothing', [], $t->calls);
}
$t = new FakeTranslation();
pin('missing locale dir loads nothing', [], $loader->load($t, 'fr_FR'));

harness_section('one bad file does not stop the others');
$broken = new class () extends FakeTranslation {
    public function _load(string $file, string $domain): void
    {
        if (basename($file) === 'messages.mo') {
            throw new RuntimeException('corrupt');
        }
        parent::_load($file, $domain);
    }
};
$log = tmpdir() . '/error.log';
$was = ini_set('error_log', $log);
$paths = $loader->load($broken, 'hi_IN');
ini_set('error_log', (string) $was);
pin('the rest still load', ['core.mo', 'theme-demo.mo', 'plugin-alpha.mo', 'plugin-zeta.mo'], array_map('basename', $paths));
check('the bad file is logged', str_contains((string) @file_get_contents($log), 'hi_IN/messages.mo: corrupt'));

harness_section('overriding a real catalogue');
$fx   = $site . '/oc-content/languages/hi_IN/core.po';
$work = tmpdir();

$mirror = new class () {
    public Translator $tr;

    public function __construct()
    {
        $this->tr = new Translator();
    }

    public function _load(string $file, string $domain): void
    {
        $t = Translations::fromMoFile($file);
        $t->setDomain($domain);
        $this->tr->loadTranslations($t);
    }
};

$customPo = function (bool $withPlural) use ($work): string {
    $t = new Translations();
    $t->setDomain('core');
    $t->setHeader('Plural-Forms', 'nplurals=2; plural=(n != 1);');
    $e = $t->insert('', "Seller's information");
    $e->setTranslation('Custom seller text');
    $po = $work . '/custom.po';
    file_put_contents($po, Gettext\Generators\Po::toString($t));
    if (!$withPlural) {
        $s = (string) file_get_contents($po);
        file_put_contents($po, preg_replace('/^"Plural-Forms:.*\n/m', '', $s));
    }

    return $po;
};

$shippedMo = $work . '/shipped.mo';
compileMo($fx, $shippedMo);
$customMo = $work . '/custom.mo';
compileMo($customPo(true), $customMo);

$mirror->_load($shippedMo, 'core');
$mirror->_load($customMo, 'core');
$tr = $mirror->tr;
pin('custom text wins', 'Custom seller text', $tr->dgettext('core', "Seller's information"));
pin('other shipped text stays', '%2$s में %1$s विज्ञापन मिले', $tr->dgettext('core', '%1$s listings found in %2$s'));
pin('shipped plural works', 'ok', $tr->dngettext('core', '%d listing', '%d listings', 2) !== '' ? 'ok' : 'empty');

harness_section('first catalogue for the domain (no shipped .mo)');
$m2 = clone $mirror;
$m2->tr = new Translator();
$m2->_load($customMo, 'core');
pin('custom text, no shipped', 'Custom seller text', $m2->tr->dgettext('core', "Seller's information"));

$pluralPo = function (bool $withHeader) use ($work): string {
    $t = new Translations();
    $t->setDomain('core');
    if ($withHeader) {
        $t->setHeader('Plural-Forms', 'nplurals=2; plural=(n > 1);');
    }
    $e = $t->insert('', '%d listing', '%d listings');
    $e->setTranslation('%d one');
    $e->setPluralTranslations(['%d many']);
    $po = $work . '/p' . (int) $withHeader . '.po';
    file_put_contents($po, Gettext\Generators\Po::toString($t));
    if (!$withHeader) {
        file_put_contents($po, preg_replace('/^"Plural-Forms:.*\n/m', '', (string) file_get_contents($po)));
    }

    return $po;
};

$with = $work . '/p1.mo';
$without = $work . '/p0.mo';
compileMo($pluralPo(true), $with);
compileMo($pluralPo(false), $without);
$a = new Translator();
$b = new Translator();
$ma = clone $mirror;
$ma->tr = $a;
$ma->_load($with, 'core');
$mb = clone $mirror;
$mb->tr = $b;
$mb->_load($without, 'core');
pin('zero is singular with header', '%d one', $a->dngettext('core', '%d listing', '%d listings', 0));
pin('two is plural with header', '%d many', $a->dngettext('core', '%d listing', '%d listings', 2));
pin('zero is plural without the header', '%d many', $b->dngettext('core', '%d listing', '%d listings', 0));

exit(harness_result());

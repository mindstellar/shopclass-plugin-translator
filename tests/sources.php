<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\Locales;
use mindstellar\translator\Sources;

$GLOBALS['stubCodes'] = ['hi_IN', 'en_US', 'bad', 'h_IN', 'de_DE/', 'ast_ES'];

class OSCLocale
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function listAllCodes(): array
    {
        return $GLOBALS['stubCodes'];
    }
}

$site = realpath(__DIR__ . '/fixtures/site');

function header_value(string $file, string $key): string
{
    return is_file($file) && preg_match('/^' . $key . ':\s*(.+)$/m', (string) file_get_contents($file), $m) ? trim($m[1]) : '';
}

function make(string $site, string $theme = 'demo', ?array $plugins = null, string $parent = '', string $templates = ''): Sources
{
    return new Sources(
        $site . '/oc-content/languages/',
        $site . '/oc-content/themes/',
        $site . '/oc-content/plugins/',
        $theme,
        $plugins ?? ['alpha/index.php', 'beta/index.php', 'gamma/index.php'],
        static fn (string $slug): string => header_value($site . '/oc-content/plugins/' . $slug . '/index.php', 'Plugin Name'),
        static fn (string $slug): string => header_value($site . '/oc-content/themes/' . $slug . '/index.php', 'Theme Name'),
        $parent,
        $templates
    );
}

harness_section('all()');
$s   = make($site);
$all = $s->all();
pin('names', ['core', 'messages', 'theme-demo', 'plugin-alpha', 'plugin-gamma'], array_map(fn ($x) => $x->name(), $all));
pin('domains', ['core', 'messages', 'demo', 'alpha', 'gamma'], array_map(fn ($x) => $x->domain(), $all));
pin('a lower-case header name is title-cased', 'Storefront', Sources::themeTitle(['name' => 'storefront']));
pin('a header name with capitals is kept', 'My Theme', Sources::themeTitle(['name' => 'My Theme']));
pin('theme_name is used when name is missing', 'Folio Child', Sources::themeTitle(['theme_name' => 'folio-child']));
pin('no header leaves the slug fallback to the caller', '', Sources::themeTitle(false));
pin('labels', ['Core', 'Core messages', 'Theme: Demo', 'Plugin: Alpha', 'Plugin: Gamma'], array_map(fn ($x) => $x->label(), $all));
pin('kinds', ['core', 'messages', 'theme', 'plugin', 'plugin'], array_map(fn ($x) => $x->kind(), $all));
check('beta absent', $s->find('plugin-beta') === null);

harness_section('paths');
$core  = $s->find('core');
$gamma = $s->find('plugin-gamma');
pin('core originals is the pot', $site . '/oc-content/languages/core.pot', $core->originalsPath('hi_IN'));
pin('gamma originals is en_US po', $site . '/oc-content/plugins/gamma/languages/en_US/messages.po', $gamma->originalsPath('hi_IN'));
pin('gamma has no pot', null, $gamma->potPath());
check('core shipped hi_IN', $core->shippedPoPath('hi_IN') !== null);
check('theme shipped hi_IN', $s->find('theme-demo')->shippedPoPath('hi_IN') !== null);
check('alpha shipped hi_IN', $s->find('plugin-alpha')->shippedPoPath('hi_IN') !== null);
pin('gamma no shipped hi_IN', null, $gamma->shippedPoPath('hi_IN'));
pin('bad locale gives null', null, $core->shippedPoPath('../x'));

harness_section('find() refuses odd names');
foreach (['plugin-beta', '../core', 'core.po', 'plugin-', 'theme-other', 'plugin-alpha/../core'] as $n) {
    pin("find($n)", null, $s->find($n));
}
check('isName core', Sources::isName('core'));
check('isName rejects ../core', !Sources::isName('../core'));
foreach (["core\n", "messages\n", "theme-demo\n", "plugin-alpha\n"] as $n) {
    check('isName rejects a trailing newline: ' . json_encode($n), !Sources::isName($n));
}

harness_section('names');
pin('parse core', ['core', 'core'], Sources::parseName('core'));
pin('parse theme', ['theme', 'demo'], Sources::parseName(Sources::themeName('demo')));
pin('parse plugin', ['plugin', 'alpha'], Sources::parseName(Sources::pluginName('alpha')));
pin('parse refuses evil', null, Sources::parseName('evil'));
pin('parse refuses a newline', null, Sources::parseName("core\n"));
pin('plugin slug', 'alpha', Sources::pluginSlug('alpha/index.php'));
pin('plugin slug refuses ../', null, Sources::pluginSlug('../evil/index.php'));
pin('plugin slug refuses a trailing newline', null, Sources::pluginSlug("alpha\n/index.php"));

harness_section('unsafe slugs are skipped');
$names = array_map(fn ($x) => $x->name(), make($site, '../themes/demo', ['../index.php', '../evil/index.php', 'alpha/index.php'])->all());
pin('only safe sources', ['core', 'messages', 'plugin-alpha'], $names);
pin('missing theme folder skipped', ['core', 'messages'], array_map(fn ($x) => $x->name(), make($site, 'nope', [])->all()));

harness_section('a child theme lists its parent');
$child = make($site, 'demo-child', [], 'demo');
pin('the parent is a source', ['core', 'messages', 'theme-demo'], array_map(fn ($x) => $x->name(), $child->all()));
pin('the parent keeps its own label', 'Theme: Demo', $child->find('theme-demo')->label());
pin('no parent, no theme source', ['core', 'messages'], array_map(fn ($x) => $x->name(), make($site, 'demo-child', [])->all()));
pin('a parent that is the theme is listed once', ['core', 'messages', 'theme-demo'], array_map(fn ($x) => $x->name(), make($site, 'demo', [], 'demo')->all()));

harness_section('sources with no template');
$templates = tmpdir() . '/templates/';
$t = make($site, 'demo-child', null, 'demo', $templates);
pin('a theme and a plugin with no template are listed', ['core', 'messages', 'theme-demo-child', 'theme-demo', 'plugin-alpha', 'plugin-beta', 'plugin-gamma'], array_map(fn ($x) => $x->name(), $t->all()));
$beta = $t->find('plugin-beta');
pin('beta has no template', 'none', $beta->template());
pin('beta has no pot yet', null, $beta->potPath());
check('a template can be made for beta', $beta->canMakeTemplate());
pin('beta is read from its folder', $site . '/oc-content/plugins/beta', $beta->folder());
pin('the template path comes from the source name', $templates . 'plugin-beta.pot', $beta->templateFile());
pin('the child theme folder', $site . '/oc-content/themes/demo-child', $t->find('theme-demo-child')->folder());
pin('a shipped pot is shipped', 'shipped', $t->find('theme-demo')->template());
pin('an en_US po is shipped', 'shipped', $t->find('plugin-gamma')->template());
check('no template is made for a shipped source', !$t->find('plugin-alpha')->canMakeTemplate() && !$t->find('plugin-gamma')->canMakeTemplate());
check('core never makes a template', !$t->find('core')->canMakeTemplate());
mkdir($templates, 0755, true);
file_put_contents($templates . 'plugin-beta.pot', "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Hi\"\nmsgstr \"\"\n");
file_put_contents($templates . 'plugin-alpha.pot', "msgid \"\"\nmsgstr \"\"\n");
pin('a made template is used', 'generated', $beta->template());
pin('its pot is the made template', $templates . 'plugin-beta.pot', $beta->potPath());
pin('its originals are the made template', $templates . 'plugin-beta.pot', $beta->originalsPath('hi_IN'));
check('it can be rebuilt', $beta->canMakeTemplate());
pin('a shipped pot wins over a made one', $site . '/oc-content/plugins/alpha/languages/alpha.pot', $t->find('plugin-alpha')->potPath());
pin('a plugin folder that is missing is not listed', null, make($site, 'demo', ['missing/index.php'], '', $templates)->find('plugin-missing'));

harness_section('Locales');
foreach (['hi_IN', 'en_US', 'ast_ES', 'hi_in'] as $c) {
    check("isCode($c)", Locales::isCode($c));
}
foreach (['h_IN', 'hi_IN/', '../', "hi_IN\n", 'hi_IN_x', 'abcd_IN'] as $c) {
    check('isCode refuses ' . json_encode($c), !Locales::isCode($c));
}
pin('installed drops bad codes', ['ast_ES', 'en_US', 'hi_IN'], Locales::installed());

exit(harness_result());

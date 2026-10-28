<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\translator\admin\Actions;
use mindstellar\translator\admin\PoUpload;
use mindstellar\translator\admin\Screen;
use mindstellar\translator\CustomStore;
use mindstellar\translator\Sources;
use mindstellar\translator\SyncStatus;

$GLOBALS['request']   = [];
$GLOBALS['files']     = [];
$GLOBALS['method']    = 'GET';
$GLOBALS['csrfOk']    = true;
$GLOBALS['csrfCalls'] = 0;
$GLOBALS['moderator'] = false;
$GLOBALS['flashes']   = [];
$GLOBALS['recorded']     = [];
$GLOBALS['kv']        = null;
$GLOBALS['kvWrites']  = 0;

final class RedirectStop extends RuntimeException
{
    public string $url;

    public function __construct(string $url)
    {
        parent::__construct('redirect');
        $this->url = $url;
    }
}

/** Request params; the purifying reads strip tags so a test can tell a raw read from a purified one. */
class Params
{
    private static function purify($v, bool $raw)
    {
        if ($raw) {
            return $v;
        }

        return is_array($v) ? array_map(static fn ($x) => self::purify($x, false), $v) : strip_tags((string) $v);
    }

    public static function getParam($p, $h = false, $x = true, $q = true)
    {
        return isset($GLOBALS['request'][$p]) ? self::purify($GLOBALS['request'][$p], !$h && !$x && !$q) : '';
    }

    public static function getParamString($p, $h = false, $x = true, $q = true)
    {
        $v = $GLOBALS['request'][$p] ?? '';

        return is_array($v) ? '' : (string) self::purify($v, !$h && !$x && !$q);
    }

    public static function getParamArray($p, $h = false, $x = true, $q = true)
    {
        $v = $GLOBALS['request'][$p] ?? [];

        return is_array($v) ? self::purify($v, !$h && !$x && !$q) : [];
    }

    public static function getParamInt($p, $default = 0)
    {
        $v = $GLOBALS['request'][$p] ?? null;

        return $v === null || is_array($v) ? $default : (int) $v;
    }

    public static function getParamEnum($p, array $allowed, $default = null)
    {
        $v = $GLOBALS['request'][$p] ?? null;
        if ($v === null || is_array($v)) {
            return $default;
        }
        foreach ($allowed as $a) {
            if ((string) $a === (string) $v) {
                return $a;
            }
        }

        return $default;
    }

    public static function existParam($p)
    {
        return isset($GLOBALS['request'][$p]);
    }

    public static function getFiles($p)
    {
        return $GLOBALS['files'][$p] ?? [];
    }

    public static function getServerParam($p, $h = false, $x = true, $q = true)
    {
        return $p === 'REQUEST_METHOD' ? $GLOBALS['method'] : ($GLOBALS['server'][$p] ?? '');
    }
}

class OSCLocale
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function listAllCodes(): array
    {
        return ['en_US', 'hi_IN'];
    }

    public function findByCode($code): array
    {
        $names = ['en_US' => 'English (US)', 'hi_IN' => 'Hindi'];

        return isset($names[$code]) ? [['pk_c_code' => $code, 's_name' => $names[$code]]] : [];
    }
}

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function osc_csrf_check()
{
    $GLOBALS['csrfCalls']++;
    if (!$GLOBALS['csrfOk']) {
        throw new RuntimeException('csrf');
    }
}
function osc_is_moderator()
{
    return $GLOBALS['moderator'];
}
function osc_redirect_to($url, $code = null)
{
    throw new RedirectStop((string) $url);
}
function osc_add_flash_ok_message($m, $s = '')
{
    $GLOBALS['flashes'][] = ['ok', $m];
}
function osc_add_flash_error_message($m, $s = '')
{
    $GLOBALS['flashes'][] = ['error', $m];
}
function osc_add_flash_warning_message($m, $s = '')
{
    $GLOBALS['flashes'][] = ['warning', $m];
}
function osc_admin_base_url($action = false)
{
    return 'ADMIN/index.php';
}
function osc_route_admin_url($id, $args = [])
{
    $url = 'ADMIN/index.php?page=plugins&action=renderplugin&route=' . $id;
    foreach ($args as $k => $v) {
        $url .= '&' . $k . '=' . $v;
    }

    return $url;
}
function osc_language()
{
    return 'hi_IN';
}
function osc_content_path()
{
    return $GLOBALS['content'];
}
function record(string $name, array $args): void
{
    $GLOBALS['recorded'][] = [$name, $args];
}
function osc_register_script(...$a)
{
    record('register_script', $a);
}
function osc_enqueue_script(...$a)
{
    record('enqueue_script', $a);
}
function osc_enqueue_style(...$a)
{
    record('enqueue_style', $a);
}
function osc_asset_url_versioned($url)
{
    return $url . '?v=1';
}
function osc_plugin_url($file)
{
    return 'PLUGINS/translator/';
}
function osc_admin_plugin_page(...$a)
{
    record('admin_plugin_page', $a);

    return [];
}
function osc_add_filter(...$a)
{
    record('add_filter', $a);
}
function osc_admin_menu_tools(...$a)
{
    record('admin_menu_tools', $a);
}
function osc_admin_empty(array $o)
{
    echo '<div class="osc-empty"><i class="bi ' . osc_esc_html($o['icon'] ?? '') . '"></i>' . osc_esc_html($o['title'] ?? '') . '</div>';
}
function osc_admin_page_head($title, array $a = [], array $o = [])
{
    echo '<h2>' . osc_esc_html($title) . '</h2>';
}
function osc_admin_panel_open($title = '', array $o = [])
{
    echo '<div class="widget-box"><div class="widget-box-content">';
}
function osc_admin_panel_close()
{
    echo '</div></div>';
}
function osc_admin_count(int $n, string $id = ''): string
{
    return ' <span class="osc-count">' . $n . '</span>';
}
function osc_admin_table_empty($cols, array $o = [])
{
    echo '<tr class="osc-table-empty-row"><td colspan="' . (int) $cols . '">' . osc_esc_html($o['title'] ?? '') . '</td></tr>';
}
function osc_admin_status($state, $word)
{
    echo '<span class="osc-status status-' . osc_esc_html($state) . '">' . osc_esc_html($word) . '</span>';
}
function osc_admin_pager(array $o)
{
    echo '<div class="pager" data-total="' . (int) $o['total'] . '"></div>';
}
function osc_admin_form_open(array $o = [])
{
    echo '<form method="post" enctype="multipart/form-data" action="' . osc_esc_html($o['url'] ?? '') . '">';
    foreach (array_merge(['page' => $o['page'] ?? '', 'action' => $o['action'] ?? ''], $o['fields'] ?? []) as $k => $v) {
        if ($v !== '' && $v !== null) {
            echo '<input type="hidden" name="' . osc_esc_html($k) . '" value="' . osc_esc_html($v) . '">';
        }
    }
}
function osc_admin_form_close($actions = null, array $o = [])
{
    echo '</form>';
}
function osc_format_date($date, $format = null)
{
    return (string) $date;
}
function osc_date_format()
{
    return 'Y-m-d';
}
function osc_time_format()
{
    return 'H:i';
}

$site  = realpath(__DIR__ . '/fixtures/site');
$GLOBALS['content'] = tmpdir() . '/';
$dir   = $GLOBALS['content'] . 'plugin-data/translator/';
// Every write forgets the stored sync result, as Plugin::afterWrite() does.
$store = new CustomStore($dir, static function (): void {
    $GLOBALS['kv'] = null;
});
$poFile = $dir . 'hi_IN/core.po';
$moFile = $dir . 'hi_IN/core.mo';

function fixtureSources(string $site): Sources
{
    return new Sources(
        $site . '/oc-content/languages/',
        $site . '/oc-content/themes/',
        $site . '/oc-content/plugins/',
        'demo',
        ['alpha/index.php', 'beta/index.php', 'gamma/index.php']
    );
}
/** Points the screen at a store, with a sync status kept in $GLOBALS['kv']. */
function useStore(string $site, CustomStore $store, string $dir): void
{
    $status = new SyncStatus(fixtureSources($site), $store, $dir, ['en_US', 'hi_IN'], static fn (): ?string => $GLOBALS['kv'], static function (?string $json): void {
        $GLOBALS['kv'] = $json;
        $GLOBALS['kvWrites']++;
    });
    Screen::use(fixtureSources($site), $store, $status);
}
useStore($site, $store, $dir);

/** Runs Screen::handle() for one request; returns the redirect URL, or null when it returned. */
function run(array $request, string $method = 'POST', array $files = []): ?string
{
    $GLOBALS['request'] = $request;
    $GLOBALS['method']  = $method;
    $GLOBALS['files']   = $files;
    $GLOBALS['flashes'] = [];
    try {
        Screen::handle();
    } catch (RedirectStop $r) {
        return $r->url;
    }

    return null;
}

function flashes(string $type): array
{
    $out = [];
    foreach ($GLOBALS['flashes'] as [$t, $m]) {
        if ($t === $type) {
            $out[] = $m;
        }
    }

    return $out;
}

function page(): string
{
    ob_start();
    try {
        include __DIR__ . '/../admin/page.php';
    } finally {
        $out = (string) ob_get_clean();
    }

    return $out;
}

function base(array $extra = []): array
{
    return ['page' => 'plugins', 'action' => 'renderplugin', 'route' => 'translator'] + $extra;
}

$seller  = "Seller's information";
$found   = '%1$s listings found in %2$s';
$rules   = 'Read the <a href="%s">rules</a> first';

harness_section('state');
$GLOBALS['request'] = base();
$s = Screen::state();
pin('default locale is the site language', 'hi_IN', $s['locale']);
pin('default source is core', 'core', $s['source']->name());
pin('default filter', 'all', $s['filter']);
pin('default page', 1, $s['page']);
$GLOBALS['request'] = base(['locale' => '../x']);
pin('a locale that is not installed falls back', 'hi_IN', Screen::state()['locale']);
$GLOBALS['request'] = base(['locale' => 'en_US']);
pin('an installed locale is kept', 'en_US', Screen::state()['locale']);
$GLOBALS['request'] = base(['source' => 'plugin-beta']);
pin('a source with no strings falls back to core', 'core', Screen::state()['source']->name());
$GLOBALS['request'] = base(['source' => 'plugin-alpha']);
pin('a listed source is kept', 'plugin-alpha', Screen::state()['source']->name());
$GLOBALS['request'] = base(['filter' => 'evil']);
pin('an unknown filter is all', 'all', Screen::state()['filter']);
$GLOBALS['request'] = base(['filter' => 'changed']);
pin('changed filter is kept', 'changed', Screen::state()['filter']);
$GLOBALS['request'] = base(['iPage' => '-3']);
pin('a negative page is 1', 1, Screen::state()['page']);
$GLOBALS['request'] = base(['iPage' => '3']);
pin('the page is read from iPage', 3, Screen::state()['page']);
$GLOBALS['request'] = base(['q' => str_repeat('é', 250)]);
pin('q is cut to 200 characters', 200, mb_strlen(Screen::state()['q']));
$GLOBALS['request'] = base(['q' => "abc\xff"]);
pin('q that is not UTF-8 is dropped', '', Screen::state()['q']);
$GLOBALS['request'] = base(['q' => '  <b>x</b>  ']);
pin('q is read raw and trimmed', '<b>x</b>', Screen::state()['q']);

harness_section('url');
$GLOBALS['request'] = base();
$s = Screen::state();
pin(
    'url encodes values and keeps the key order',
    'ADMIN/index.php?page=plugins&action=renderplugin&route=translator&locale=hi_IN&source=core&q=a%26b&filter=changed&iPage=2&tr_do=download',
    Screen::url(['q' => 'a&b', 'filter' => 'changed', 'page' => 2] + $s, ['tr_do' => 'download'])
);
pin(
    'url leaves out q and filter at their defaults',
    'ADMIN/index.php?page=plugins&action=renderplugin&route=translator&locale=hi_IN&source=core&iPage=1',
    Screen::url($s)
);

harness_section('view');
$GLOBALS['request'] = base();
$v = Screen::view();
pin('nplurals', 2, $v['nplurals']);
pin('listing total', 7, $v['listing']['total']);
pin('counts', ['all' => 7, 'changed' => 0, 'look' => 0], $v['counts']);
pin('locale labels', ['en_US' => 'English (US)', 'hi_IN' => 'Hindi'], $v['locales']);
pin('source groups', ['Core', 'Theme', 'Plugins'], array_keys($v['groups']));
pin('no custom file yet', false, $v['hasCustom']);
pin('six check messages for the script', true, isset($v['messages']['empty'], $v['messages']['keep'], $v['messages']['extra'], $v['messages']['tags'], $v['messages']['unsafe'], $v['messages']['ok']));
pin('nothing is flagged', [], $v['flagged']);
pin('the scan finds nothing', 0, $v['sync']['total']);
pin('no custom file anywhere means no banner', '', Screen::banner($v));
$GLOBALS['request'] = base(['iPage' => '9']);
$v = Screen::view();
pin('a page past the end is clamped', 1, $v['page']);
check('the list URL carries the clamped page', strpos($v['urls']['self'], 'iPage=1') !== false && strpos($v['urls']['self'], 'iPage=9') === false, $v['urls']['self']);
$GLOBALS['request'] = base();

harness_section('page');
$out = page();
check('page has the drawer', strpos($out, 'id="trDrawer"') !== false);
check('page has no boxed panel', strpos($out, 'widget-box') === false);
check('the toolbar opens the import and export drawer', strpos($out, 'id="trIoOpen"') !== false && strpos($out, 'aria-controls="trIo"') !== false);
check('upload and export sit in that drawer', preg_match('#id="trIo".*value="upload".*export=full.*id="trDrawer"#s', $out) === 1);
pin('page has 7 rows', 7, substr_count($out, 'class="tr-row"'));
check('page prints the HTML original escaped', strpos($out, '&lt;a href=&quot;%s&quot;&gt;rules&lt;/a&gt;') !== false);
check('page never prints the HTML original raw', strpos($out, '<a href="%s">rules</a>') === false);
check('page has the save button', strpos($out, 'name="tr_do" value="save"') !== false);
check('page has the upload button', strpos($out, 'name="tr_do" value="upload"') !== false);
check('page has no download button before a save', strpos($out, 'Download my changes') === false);
check('page carries a check message for the script', strpos($out, 'data-msg-keep="Your text must keep %s"') !== false);
$GLOBALS['request'] = base(['q' => '"><script>x</script>']);
$out = page();
check('page escapes the search box', strpos($out, 'value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"') !== false && strpos($out, '<script>x') === false);
$GLOBALS['request'] = base(['filter' => 'look']);
check('look filter shows its empty line', strpos(page(), 'No texts need a look.') !== false);
$GLOBALS['request'] = base(['q' => 'zzqqxx-no-such-text']);
check('a search with no hits says so', strpos(page(), 'No texts match your search.') !== false);
$GLOBALS['request'] = base(['filter' => 'changed']);
check('changed filter with no changes says so', strpos(page(), 'osc-table-empty-row"><td colspan="4">You have not changed any texts here yet.') !== false);

harness_section('handle: not ours');
pin('no route returns', null, run(['tr_do' => 'save'], 'POST'));
pin('no route does not check CSRF', 0, $GLOBALS['csrfCalls']);
pin('no action returns', null, run(base(), 'POST'));
pin('no action does not check CSRF', 0, $GLOBALS['csrfCalls']);

harness_section('handle: save');
$save = base(['locale' => 'hi_IN', 'source' => 'core', 'tr_do' => 'save', 'context' => '', 'original' => $seller, 'text' => ["Seller's details"]]);
$url = run($save, 'GET');
pin('GET save redirects to the list', 'ADMIN/index.php?page=plugins&action=renderplugin&route=translator&locale=hi_IN&source=core&iPage=1', $url);
pin('GET save does not check CSRF', 0, $GLOBALS['csrfCalls']);
check('GET save writes nothing', !is_file($poFile));

$GLOBALS['csrfOk'] = false;
$threw = false;
try {
    run($save);
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'csrf';
}
$GLOBALS['csrfOk'] = true;
check('bad token stops the save', $threw);
check('bad token writes nothing', !is_file($poFile));

$GLOBALS['moderator'] = true;
$url = run($save);
$GLOBALS['moderator'] = false;
pin('moderator is sent to the admin home', 'ADMIN/index.php', $url);
pin('moderator gets the error', ['Only an administrator can change texts.'], flashes('error'));
check('moderator writes nothing', !is_file($poFile));

$calls = $GLOBALS['csrfCalls'];
$url = run($save);
pin('save checks CSRF', $calls + 1, $GLOBALS['csrfCalls']);
check('save writes the .po and .mo', is_file($poFile) && is_file($moFile));
pin('save stores the text', ["Seller's details"], $store->read('hi_IN', Screen::state()['source'])[SourceStrings_key('', $seller)]['text'] ?? null);
pin('save says so', ['Text saved.'], flashes('ok'));
check('save redirects to the list', is_string($url) && strpos($url, 'locale=hi_IN&source=core&iPage=1') !== false);

function SourceStrings_key(string $c, string $o): string
{
    return \mindstellar\translator\SourceStrings::key($c, $o);
}

$bad = base(['tr_do' => 'save', 'original' => $found, 'text' => ['%1$s listings found']]);
run($bad);
pin('a dropped placeholder is refused', ['Your text must keep %2$s'], flashes('error'));
check('a refused text is not stored', !isset($store->read('hi_IN', Screen::state()['source'])[SourceStrings_key('', $found)]));

run(base(['tr_do' => 'save', 'original' => $rules, 'text' => ['Lies die <a href="%s">Regeln</a>']]));
pin('HTML text and original are read raw', ['Lies die <a href="%s">Regeln</a>'], $store->read('hi_IN', Screen::state()['source'])[SourceStrings_key('', $rules)]['text'] ?? null);

run(base(['tr_do' => 'save', 'original' => $rules]));
pin('a save with no text is refused', ['No text was sent. Nothing was changed.'], flashes('error'));
check('a save with no text keeps the entry', isset($store->read('hi_IN', Screen::state()['source'])[SourceStrings_key('', $rules)]));

run(base(['tr_do' => 'save', 'original' => "Not\r\nhere", 'text' => ['x']]));
pin('an unknown original is refused', ['That text is not in this source any more'], flashes('error'));

$GLOBALS['request'] = base();
check('page shows the download button after a save', strpos(page(), 'Download my changes') !== false);

run(base(['tr_do' => 'reset', 'original' => $rules]));
pin('reset says so', ['Back to the original text.'], flashes('ok'));
run(base(['tr_do' => 'reset', 'original' => $seller, 'text' => ['ignored']]));
check('reset of the last entry removes the files', !is_file($poFile) && !is_file($moFile));

$blocker = tmpdir() . '/not-a-dir';
file_put_contents($blocker, 'x');
useStore($site, new CustomStore($blocker . '/'), $blocker . '/');
$url = run(base(['tr_do' => 'save', 'original' => $seller, 'text' => ["Seller's details"]]));
pin('a failed write says so', ['Could not save. Check that oc-content/plugin-data/translator/ can be written.'], flashes('error'));
pin('a failed write says nothing went well', [], flashes('ok'));
check('a failed write still redirects to the list', is_string($url) && strpos($url, 'locale=hi_IN&source=core&iPage=1') !== false, (string) $url);
$failDir   = tmpdir() . '/';
$failStore = new CustomStore($failDir);
$failStore->set('hi_IN', Screen::state()['source'], ['original' => $seller, 'text' => ["Seller's details"], 'replaced' => [], 'references' => []], 'nplurals=2; plural=(n != 1);');
useStore($site, new CustomStore($failDir, static function (): void {
    throw new RuntimeException('disk full');
}), $failDir);
$url = run(base(['tr_do' => 'reset', 'original' => $seller]));
pin('a failed reset says so', ['Could not save. Check that oc-content/plugin-data/translator/ can be written.'], flashes('error'));
check('a failed reset still redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false, (string) $url);
useStore($site, $store, $dir);

$url = run(base(['tr_do' => 'nope']));
check('an unknown action redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false);
check('an unknown action writes nothing', !is_file($poFile));

harness_section('handle: upload');
PoUpload::$isUploaded = static fn (string $f): bool => true;
function upload(string $content): array
{
    $f = tmpdir() . '/up.po';
    file_put_contents($f, $content);

    return ['file' => ['name' => 'up.po', 'type' => 'text/plain', 'tmp_name' => $f, 'error' => 0, 'size' => strlen($content)]];
}
$po = "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\n"
    . "msgid \"Seller's information\"\nmsgstr \"Uploaded seller\"\n\n"
    . "msgid \"%1\$s listings found in %2\$s\"\nmsgstr \"%1\$s in %2\$s\"\n";
$url = run(base(['tr_do' => 'upload']), 'POST', upload($po));
pin('upload says how many', ['2 texts saved.'], flashes('ok'));
$read = $store->read('hi_IN', Screen::state()['source']);
pin('upload wrote both entries', [SourceStrings_key('', $found), SourceStrings_key('', $seller)], array_keys($read));
check('upload redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false);

$before = (string) file_get_contents($poFile);
run(base(['tr_do' => 'upload']), 'POST', upload("msgid \"Nope <b>x</b>\"\nmsgstr \"No\"\n"));
pin('only unknown texts is an error', ["No texts in the file match this source.<br>1 text is not in this source: &quot;Nope &lt;b&gt;x&lt;/b&gt;&quot;"], flashes('error'));
pin('only unknown texts leaves the file', $before, (string) file_get_contents($poFile));

run(base(['tr_do' => 'upload']), 'POST', upload($po . "\nmsgid \"Nope\"\nmsgstr \"No\"\n"));
pin('skipped texts are named in a warning', ['1 text is not in this source: &quot;Nope&quot;'], flashes('warning'));

run(base(['tr_do' => 'upload']), 'POST', upload("\xff\xfe not utf8"));
pin('a non UTF-8 file is refused', ['The file must be UTF-8.'], flashes('error'));
run(base(['tr_do' => 'upload']), 'POST', []);
pin('no file is refused', ['Choose a .po file.'], flashes('error'));
pin('a refused upload leaves the file', $before, (string) file_get_contents($poFile));

harness_section('handle: upload modes, undo, remove all');
$src      = Screen::state()['source'];
$bakFile  = $store->backupPath('hi_IN', $src);
$keysNow  = static fn (): array => array_keys($store->read('hi_IN', $src));
$rulesPo  = "msgid \"Read the <a href=\\\"%s\\\">rules</a> first\"\nmsgstr \"Lies die <a href=\\\"%s\\\">Regeln</a>\"\n";
$sellerKey = SourceStrings_key('', $seller);
$foundKey  = SourceStrings_key('', $found);
$rulesKey  = SourceStrings_key('', $rules);
$GLOBALS['request']  = base();
$GLOBALS['recorded'] = [];
page();
check('the page asks for core\'s confirm script', in_array(['enqueue_script', ['admin-editor']], $GLOBALS['recorded'], true));

$prev = (string) file_get_contents($poFile);
run(base(['tr_do' => 'upload']), 'POST', upload($rulesPo));
pin('add mode is the default and keeps the other changes', [$foundKey, $rulesKey, $sellerKey], $keysNow());
pin('add mode says how many came from the file', ['1 text saved.'], flashes('ok'));
pin('an upload backs up the file it changes', $prev, is_file($bakFile) ? (string) file_get_contents($bakFile) : null);

$prev = (string) file_get_contents($poFile);
run(base(['tr_do' => 'upload', 'mode' => 'replace']), 'POST', upload($rulesPo));
pin('replace mode drops the other changes', [$rulesKey], $keysNow());
pin('the backup is the file before this upload', $prev, (string) file_get_contents($bakFile));
run(base(['tr_do' => 'upload', 'mode' => 'evil']), 'POST', upload($po));
pin('an unknown mode adds', [$foundKey, $rulesKey, $sellerKey], $keysNow());
run(base(['tr_do' => 'upload', 'mode' => 'replace']), 'POST', upload($rulesPo));

$bak = (string) file_get_contents($bakFile);
run(base(['tr_do' => 'save', 'original' => $seller, 'text' => ['One save']]));
pin('a single save leaves the backup alone', $bak, (string) file_get_contents($bakFile));
run(base(['tr_do' => 'reset', 'original' => $seller]));

$GLOBALS['request'] = base();
$out = page();
check('the undo button shows when there is a backup', strpos($out, 'value="undo"') !== false);
check('the page has the full export link', strpos($out, 'export=full') !== false);
check('the page has the zip export link when zip works', (strpos($out, 'export=zip') !== false) === class_exists('ZipArchive'));
check('remove all asks first with core\'s confirm', preg_match('#<button[^>]*value="removeall"[^>]*data-osc-confirm="[^"]+"[^>]*data-osc-confirm-title="Remove all my changes\?"#', $out) === 1);
check('the upload form has both modes, add checked', strpos($out, 'name="mode" value="add" id="trModeAdd" checked') !== false && strpos($out, 'name="mode" value="replace"') !== false);

foreach (['undo', 'removeall'] as $do) {
    $snap = [(string) file_get_contents($poFile), (string) file_get_contents($bakFile)];
    $GLOBALS['csrfOk'] = false;
    $threw = false;
    try {
        run(base(['tr_do' => $do]));
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'csrf';
    }
    $GLOBALS['csrfOk'] = true;
    check("bad token stops $do", $threw);
    pin("bad token $do changes nothing", $snap, [(string) file_get_contents($poFile), (string) file_get_contents($bakFile)]);
    $GLOBALS['moderator'] = true;
    $url = run(base(['tr_do' => $do]));
    $GLOBALS['moderator'] = false;
    pin("moderator $do goes to the admin home", 'ADMIN/index.php', $url);
    pin("moderator $do changes nothing", $snap, [(string) file_get_contents($poFile), (string) file_get_contents($bakFile)]);
    run(base(['tr_do' => $do]), 'GET');
    pin("GET $do changes nothing", $snap, [(string) file_get_contents($poFile), (string) file_get_contents($bakFile)]);
}
$GLOBALS['csrfOk'] = false;
$threw = false;
try {
    run(base(['tr_do' => 'upload']), 'POST', upload($po));
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'csrf';
}
$GLOBALS['csrfOk'] = true;
check('bad token stops an upload', $threw);
pin('bad token upload keeps the backup', $bak, (string) file_get_contents($bakFile));

clearstatcache();
$moBefore = (int) filemtime($moFile);
$url = run(base(['tr_do' => 'undo']));
pin('undo says so', ['Your texts are back as they were.'], flashes('ok'));
pin('undo restores the exact file', $bak, (string) file_get_contents($poFile));
pin('undo restores the entries', [$foundKey, $rulesKey, $sellerKey], $keysNow());
clearstatcache();
check('undo rebuilds a newer .mo', is_file($moFile) && (int) filemtime($moFile) > $moBefore);
check('undo deletes the backup', !is_file($bakFile));
check('undo redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false, (string) $url);
$GLOBALS['request'] = base();
check('no undo button without a backup', strpos(page(), 'value="undo"') === false);

$prev = (string) file_get_contents($poFile);
run(base(['tr_do' => 'undo']));
pin('undo with no backup is refused', ['There is nothing to undo.'], flashes('warning'));
pin('undo with no backup changes nothing', $prev, (string) file_get_contents($poFile));

$url = run(base(['tr_do' => 'removeall']));
pin('remove all names the count', ['3 texts removed.'], flashes('ok'));
check('remove all deletes the .po and .mo', !is_file($poFile) && !is_file($moFile));
pin('remove all backs up first', $prev, is_file($bakFile) ? (string) file_get_contents($bakFile) : null);
check('remove all redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false, (string) $url);
run(base(['tr_do' => 'removeall']));
pin('remove all with nothing to remove warns', ['You have not changed any texts here yet.'], flashes('warning'));
check('remove all with nothing keeps the backup', is_file($bakFile));
run(base(['tr_do' => 'undo']));
pin('undo after remove all restores the file', $prev, is_file($poFile) ? (string) file_get_contents($poFile) : null);
check('undo after remove all rebuilds the .mo', is_file($moFile));

run(base(['tr_do' => 'removeall']));
run(base(['tr_do' => 'upload']), 'POST', upload($po));
pin('an upload onto no file leaves an empty backup', '', is_file($bakFile) ? (string) file_get_contents($bakFile) : null);
$GLOBALS['request'] = base();
check('the undo button shows after a first upload', strpos(page(), 'value="undo"') !== false);
run(base(['tr_do' => 'undo']));
check('undo of a first upload removes the file', !is_file($poFile) && !is_file($moFile));
run(base(['tr_do' => 'upload']), 'POST', upload($po));
run(base(['tr_do' => 'upload', 'mode' => 'replace']), 'POST', upload($po));
run(base(['tr_do' => 'undo']));
pin('the state the later tests expect', [$foundKey, $sellerKey], $keysNow());
$before = (string) file_get_contents($poFile);

harness_section('handle: body too big');
$GLOBALS['server'] = ['CONTENT_LENGTH' => '9000000'];
$_POST = [];
$GLOBALS['csrfCalls'] = 0;
$url = run(base(), 'POST');
pin('a body PHP dropped says the file is too big', ['The file is too big to upload.'], flashes('error'));
check('a dropped body redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false, (string) $url);
pin('a dropped body is not a CSRF check', 0, $GLOBALS['csrfCalls']);
$GLOBALS['server'] = [];
run(base(), 'POST');
pin('an empty POST with no length is left alone', [], flashes('error'));
run(base(), 'GET');
pin('a GET is left alone', [], flashes('error'));
preg_match_all('#<form[^>]*method="post"[^>]*action="([^"]*)"#', page(), $fm);
pin('every post form posts to the route URL', array_fill(0, count($fm[1]), 'ADMIN/index.php?page=plugins&amp;action=renderplugin&amp;route=translator'), $fm[1]);
check('the check, drawer, upload and remove all forms are there', count($fm[1]) === 4, (string) count($fm[1]));

harness_section('handle: download');
pin('the file name keeps only safe characters', 'translator-hi_IN-plugin-a.b.po', Actions::fileName('hi_IN"/', "plugin-a.b;\r\n"));
pin('the download headers', [
    'Content-Type: text/x-gettext-translation; charset=UTF-8',
    'Content-Disposition: attachment; filename="x.po"',
    'Content-Length: 12',
    'Cache-Control: no-store, private',
    'X-Content-Type-Options: nosniff',
], Actions::downloadHeaders('x.po', 12));
Actions::$send = static function (string $path, string $name): void {
    $GLOBALS['sent'] = $name;
    readfile($path);
};
ob_start();
$url = run(base(['tr_do' => 'download']), 'GET');
$body = (string) ob_get_clean();
pin('download does not redirect', null, $url);
pin('download names the file from the locale and source', 'translator-hi_IN-core.po', $GLOBALS['sent'] ?? null);
pin('download sends the file bytes', (string) file_get_contents($poFile), $body);

$GLOBALS['sent'] = null;
ob_start();
run(base(['tr_do' => 'download', 'locale' => '../x', 'source' => '../../etc']), 'GET');
ob_end_clean();
pin('download name never comes from the request', 'translator-hi_IN-core.po', $GLOBALS['sent']);

$GLOBALS['sent']      = null;
$GLOBALS['moderator'] = true;
$url = run(base(['tr_do' => 'download']), 'GET');
$GLOBALS['moderator'] = false;
pin('moderator cannot download', 'ADMIN/index.php', $url);
pin('moderator download sends nothing', null, $GLOBALS['sent']);

$url = run(base(['tr_do' => 'download', 'locale' => 'en_US']), 'GET');
pin('download with no file warns', ['No changed texts for this language and source yet.'], flashes('warning'));
check('download with no file redirects to the list', is_string($url) && strpos($url, 'locale=en_US') !== false);

harness_section('sync: ago');
$agoBanner = static function (int $seconds): string {
    $text = Screen::banner(['sync' => ['total' => 0, 'at' => time() - $seconds, 'items' => []], 'hasAny' => true]);
    preg_match('#<span class="tr-sync-meta">Last checked (.*?)</span>#', $text, $m);

    return $m[1] ?? $text;
};
pin('under a minute', 'just now', $agoBanner(5));
pin('a clock behind is just now', 'just now', $agoBanner(-10));
pin('90 seconds', '1 minute ago', $agoBanner(90));
pin('two hours', '2 hours ago', $agoBanner(7200));
check('three days is a date', preg_match('/^\d{4}-\d{2}-\d{2}/', $agoBanner(3 * 86400)) === 1, $agoBanner(3 * 86400));

harness_section('sync: banner escaping');
$evil = '"><script>x</script>';
$hostile = Screen::banner([
    'sync'       => ['total' => 1, 'at' => time(), 'items' => ['p' => ['locale' => 'hi_IN', 'source' => 'core', 'total' => 1]]],
    'hasAny'     => true,
    'pairLabels' => ['p' => $evil],
    'locale'     => $evil,
    'source'     => $evil,
    'q'          => $evil,
    'filter'     => $evil,
]);
check('hostile text never opens a tag', strpos($hostile, '<script') === false, $hostile);
check('the pair label is escaped', strpos($hostile, '&quot;&gt;&lt;script&gt;x&lt;/script&gt; (1)') !== false, $hostile);
check('the hidden values are escaped', substr_count($hostile, 'value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"') === 4, $hostile);

harness_section('sync: cache');
$core = Screen::state()['source'];
$forms = 'nplurals=2; plural=(n != 1);';
$store->replaceAll('hi_IN', $core, [], $forms);
$GLOBALS['request'] = base();
$GLOBALS['kv'] = null;
$writesBefore = $GLOBALS['kvWrites'];
Screen::view();
check('a view stores the scan', is_string($GLOBALS['kv']));
pin('one store write', $writesBefore + 1, $GLOBALS['kvWrites']);
run(base(['tr_do' => 'save', 'original' => $seller, 'text' => ["Seller's details"]]));
pin('a save forgets the stored scan', null, $GLOBALS['kv']);
$v = Screen::view();
check('the next view stores it again', is_string($GLOBALS['kv']));
pin('a clean text is not flagged', [], $v['flagged']);
check('a clean site shows the all-good banner', strpos(Screen::banner($v), 'alert-success') !== false && strpos(Screen::banner($v), 'All your texts match the current originals.') !== false, Screen::banner($v));

harness_section('sync: newer');
$sellerKey = SourceStrings_key('', $seller);
$entry = $store->read('hi_IN', $core)[$sellerKey];
$store->set('hi_IN', $core, ['replaced' => ['old']] + $entry, $forms);
$GLOBALS['request'] = base();
$v = Screen::view();
pin('the row is flagged newer', ['newer'], $v['listing']['rows'][0]['flags']);
pin('the scan counts it', 1, $v['sync']['items']['hi_IN/core']['newer'] ?? null);
pin('the pair has a label', ['hi_IN/core' => 'Hindi, Core'], $v['pairLabels']);
$banner = Screen::banner($v);
check('warning banner', strpos($banner, 'alert-warning') !== false, $banner);
check('banner says how many', strpos($banner, '1 of your texts needs a look.') !== false, $banner);
check('banner links to the look filter', strpos($banner, 'locale=hi_IN&amp;source=core&amp;filter=look&amp;iPage=1') !== false, $banner);
check('banner names the pair', strpos($banner, 'Hindi, Core (1)') !== false, $banner);
check('banner has the check button', strpos($banner, 'name="tr_do" value="check"') !== false, $banner);
check('banner says when', strpos($banner, 'Last checked just now') !== false, $banner);
check('banner form keeps the state', strpos($banner, 'name="locale" value="hi_IN"') !== false && strpos($banner, 'name="route" value="translator"') !== false, $banner);

$out = page();
check('the banner is inside its slot', strpos($out, '<div id="tr-banner"><div class="alert alert-warning tr-sync"') !== false);
check('the drawer has the new original block', strpos($out, 'id="trNewOrig"') !== false && strpos($out, 'The text you replaced is now') !== false);
preg_match('#<tr class="tr-row"[^>]*data-newer=[^>]*>.*?</tr>#s', $out, $m);
$row = $m[0] ?? '';
check('the newer row carries the new original', strpos($row, 'data-newer="[&quot;विक्रेता की जानकारी&quot;]"') !== false, $row);
check('the newer row carries its flags', strpos($row, 'data-flags="newer"') !== false, $row);
check('the newer line stays plain', strpos($row, '<span class="tr-diff">You replaced') !== false && strpos($row, 'tr-diff-bad') === false, $row);
check('the newer row says the original changed', strpos($row, 'status-pending">Original changed') !== false, $row);
check('the newer row shows the old original', strpos($row, '<del>old</del>') !== false && strpos($row, '<ins>विक्रेता की जानकारी</ins>') !== false, $row);
check('the newer row can keep or take the new original', strpos($row, 'value="keep"') !== false && strpos($row, 'Use the new original') !== false, $row);
check('the actions form names the text', strpos($row, 'name="original" value="Seller&#039;s information"') !== false, $row);
pin('one Edit button in the row actions', 1, substr_count($out, 'tr-edit'));
check('the flagged row has no Edit in its last cell', preg_match('#<td class="text-end tr-actions"></td>#', $row) === 1, $row);

harness_section('sync: keep');
$keep = base(['tr_do' => 'keep', 'context' => '', 'original' => $seller, 'text' => ['from the request']]);
$before = (string) file_get_contents($poFile);
$calls = $GLOBALS['csrfCalls'];
$url = run($keep, 'GET');
check('GET keep redirects', is_string($url) && strpos($url, 'route=translator') !== false);
pin('GET keep does not check CSRF', $calls, $GLOBALS['csrfCalls']);
pin('GET keep writes nothing', $before, (string) file_get_contents($poFile));
$GLOBALS['csrfOk'] = false;
$threw = false;
try {
    run($keep);
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'csrf';
}
$GLOBALS['csrfOk'] = true;
check('bad token stops the keep', $threw);
pin('bad token keep writes nothing', $before, (string) file_get_contents($poFile));
$GLOBALS['moderator'] = true;
$url = run($keep);
$GLOBALS['moderator'] = false;
pin('moderator keep goes to the admin home', 'ADMIN/index.php', $url);
pin('moderator keep writes nothing', $before, (string) file_get_contents($poFile));

$url = run(base(['tr_do' => 'keep', 'original' => 'Never heard of it']));
pin('keep on an unknown text is refused', ['That text is not one of your changes.'], flashes('error'));
pin('keep on an unknown text writes nothing', $before, (string) file_get_contents($poFile));
run(base(['tr_do' => 'keep', 'original' => $rules]));
pin('keep on a text with no change is refused', ['That text is not one of your changes.'], flashes('error'));

$calls = $GLOBALS['csrfCalls'];
$url = run($keep);
pin('keep checks CSRF', $calls + 1, $GLOBALS['csrfCalls']);
pin('keep says so', ['Kept your text.'], flashes('ok'));
pin('keep redirects to the list', 'ADMIN/index.php?page=plugins&action=renderplugin&route=translator&locale=hi_IN&source=core&iPage=1', $url);
$kept = $store->read('hi_IN', $core)[$sellerKey] ?? [];
pin('keep stores the current original', ['विक्रेता की जानकारी'], $kept['replaced'] ?? null);
pin('keep leaves the text alone', ["Seller's details"], $kept['text'] ?? null);
check('the .po holds the new comment', strpos((string) file_get_contents($poFile), '#. replaced: ["विक्रेता की जानकारी"]') !== false);
$GLOBALS['request'] = base();
$v = Screen::view();
pin('a kept row is not flagged', [], $v['listing']['rows'][0]['flags']);
check('after the keep the banner is green', strpos(Screen::banner($v), 'alert-success') !== false);

harness_section('sync: reset with no change');
clearstatcache();
$before = (string) file_get_contents($poFile);
touch($poFile, time() - 100);
clearstatcache();
$mtime = filemtime($poFile);
$url = run(base(['tr_do' => 'reset', 'original' => $rules]));
pin('reset of an unchanged text warns', ['That text was not changed.'], flashes('warning'));
pin('reset of an unchanged text says nothing went well', [], flashes('ok'));
clearstatcache();
pin('reset of an unchanged text leaves the .po', $before, (string) file_get_contents($poFile));
pin('reset of an unchanged text leaves the mtime', $mtime, filemtime($poFile));
pin('reset of an unchanged text redirects to the list', 'ADMIN/index.php?page=plugins&action=renderplugin&route=translator&locale=hi_IN&source=core&iPage=1', $url);

harness_section('sync: removed');
$store->set('hi_IN', $core, ['context' => '', 'original' => 'Nope <b>gone</b>', 'plural' => null, 'text' => ['Nein'], 'replaced' => ['Nope!'], 'references' => []], $forms);
$GLOBALS['request'] = base();
$out = page();
preg_match('#<tr class="tr-row tr-row-static">.*?</tr>#s', $out, $m);
$row = $m[0] ?? '';
check('the removed row is static', $row !== '');
check('the removed row says so', strpos($row, 'status-failed">Original removed') !== false, $row);
check('the removed row can be removed', strpos($row, 'Remove mine') !== false && strpos($row, 'value="reset"') !== false, $row);
check('the removed row has no data attributes', strpos($row, 'data-original') === false && strpos($row, 'data-text') === false, $row);
check('the removed row has no Edit', strpos($row, 'tr-edit') === false && strpos($row, 'bi-pencil') === false, $row);
check('the removed original is escaped', strpos($row, 'Nope &lt;b&gt;gone&lt;/b&gt;') !== false, $row);
run(base(['tr_do' => 'keep', 'original' => 'Nope <b>gone</b>']));
pin('keep on a removed text is refused', ['That text is not in this source any more'], flashes('error'));
run(base(['tr_do' => 'reset', 'original' => 'Nope <b>gone</b>']));
pin('reset of a removed text says so', ['Back to the original text.'], flashes('ok'));
check('reset of a removed text removes it', !isset($store->read('hi_IN', $core)[SourceStrings_key('', 'Nope <b>gone</b>')]));
check('reset of a removed text keeps the others', isset($store->read('hi_IN', $core)[$sellerKey]));

$crlf = "Line one\r\nLine two";
$store->set('hi_IN', $core, ['context' => '', 'original' => $crlf, 'plural' => null, 'text' => ['Zeile'], 'replaced' => [], 'references' => []], $forms);
check('the CRLF entry is stored under its raw key', isset($store->read('hi_IN', $core)[SourceStrings_key('', $crlf)]));
run(base(['tr_do' => 'reset', 'original' => $crlf]));
pin('reset of a removed text with CRLF says so', ['Back to the original text.'], flashes('ok'));
check('reset of a removed text with CRLF removes it', !isset($store->read('hi_IN', $core)[SourceStrings_key('', $crlf)]));

harness_section('sync: placeholders');
$store->set('hi_IN', $core, ['context' => '', 'original' => $found, 'plural' => null, 'text' => ['%1$s listings found'], 'replaced' => ['%2$s में %1$s विज्ञापन मिले'], 'references' => []], $forms);
$GLOBALS['request'] = base();
$out = page();
check('a bad text says the placeholders differ', strpos($out, 'status-failed">Placeholders differ') !== false);
check('a bad text names the problem in red', strpos($out, '<span class="tr-diff tr-diff-bad">Your text must keep %2$s</span>') !== false);
$before = (string) file_get_contents($poFile);
$url = run(base(['tr_do' => 'keep', 'original' => $found]));
pin('keep of a text with a missing placeholder is refused', ['Your text must keep %2$s'], flashes('error'));
pin('a refused keep says nothing went well', [], flashes('ok'));
pin('a refused keep writes nothing', $before, (string) file_get_contents($poFile));
check('a refused keep redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false, (string) $url);
check('a bad text offers the original, not keep', strpos($out, 'Use the original<') !== false && strpos($out, 'value="keep"') === false);

harness_section('sync: check');
$writes = $GLOBALS['kvWrites'];
$calls  = $GLOBALS['csrfCalls'];
$url = run(base(['tr_do' => 'check']));
pin('check rescans', $writes + 1, $GLOBALS['kvWrites']);
pin('check checks CSRF', $calls + 1, $GLOBALS['csrfCalls']);
pin('check says so', ['Checked.'], flashes('ok'));
check('check redirects to the list', is_string($url) && strpos($url, 'route=translator') !== false);
$GLOBALS['csrfOk'] = false;
$threw = false;
try {
    run(base(['tr_do' => 'check']));
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'csrf';
}
$GLOBALS['csrfOk'] = true;
check('bad token stops the check', $threw);
pin('bad token check stores nothing', $writes + 1, $GLOBALS['kvWrites']);
$store->replaceAll('hi_IN', $core, [], $forms);

harness_section('sync: the view and the cache');
$GLOBALS['request'] = base();
$first = Screen::view();
$writes = $GLOBALS['kvWrites'];
$again = Screen::view();
pin('a second view reads the stored scan', $writes, $GLOBALS['kvWrites']);
pin('and returns it unchanged', $first['sync']['at'], $again['sync']['at']);
$throwing = new SyncStatus(fixtureSources($site), $store, $dir, ['en_US', 'hi_IN'], static function (): ?string {
    throw new RuntimeException('db down');
}, static function (?string $json): void {
});
Screen::use(fixtureSources($site), $store, $throwing);
$v = Screen::view();
pin('a failing status leaves no scan', null, $v['sync']);
pin('and no banner', '', Screen::banner($v));
$out = page();
check('the page still renders', strpos($out, 'tr-table') !== false && strpos($out, 'tr-sync') === false);
useStore($site, $store, $dir);

harness_section('boot and menu');
$GLOBALS['recorded']   = [];
$GLOBALS['request'] = base();
Screen::boot();
$names = array_column($GLOBALS['recorded'], 0);
pin('boot on the route registers the assets', ['admin_plugin_page', 'add_filter', 'register_script', 'enqueue_script', 'enqueue_style'], $names);
pin('the script depends on admin-osc', ['admin-osc'], $GLOBALS['recorded'][2][1][2] ?? null);
pin('the section label is filtered', 'custom_plugin_title', $GLOBALS['recorded'][1][1][0] ?? null);
pin('the section label says Tools', 'Tools', ($GLOBALS['recorded'][1][1][1])('Plugins'));
$GLOBALS['recorded']   = [];
$GLOBALS['request'] = ['page' => 'items'];
Screen::boot();
pin('boot elsewhere only declares the page', ['admin_plugin_page'], array_column($GLOBALS['recorded'], 0));
$GLOBALS['recorded'] = [];
Screen::menu();
pin('menu row is under Tools for administrators', ['Translator', 'ADMIN/index.php?page=plugins&action=renderplugin&route=translator', 'translator', 'administrator'], $GLOBALS['recorded'][0][1] ?? null);

harness_section('moderator page');
$GLOBALS['moderator'] = true;
$GLOBALS['request']   = base();
$out = page();
$GLOBALS['moderator'] = false;
check('moderator sees the shield', strpos($out, 'bi-shield-lock') !== false);
check('moderator sees no drawer or rows', strpos($out, 'trDrawer') === false && strpos($out, 'tr-row') === false);

harness_section('index.php');
/** Loads index.php in its own process and returns the hooks and routes it added. */
function wiring(?string $version, bool $admin): array
{
    $script = tmpdir() . '/run.php';
    file_put_contents($script, '<?php
define("ABS_PATH", "/x/");
' . ($admin ? 'define("OC_ADMIN", true);' : '') . '
' . ($version !== null ? 'define("OSCLASS_VERSION", ' . var_export($version, true) . ');' : '') . '
$out = ["hooks" => [], "routes" => []];
function osc_plugin_path($f) { return "translator/index.php"; }
function osc_plugin_folder($f) { return "translator/"; }
function osc_register_plugin($p, $cb) {}
function osc_add_hook($name, $cb, $prio = 10) { global $out; $out["hooks"][] = $name; }
function osc_add_route($id, $re, $url, $file) { global $out; $out["routes"][] = [$id, $file]; }
require ' . var_export(dirname(__DIR__) . '/index.php', true) . ';
echo json_encode($out);
');
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));

    return json_decode((string) $res, true) ?: [];
}
$w = wiring('7.0.6', true);
pin('admin request wires the screen', ['translator/index.php_uninstall', 'translation_load_after', 'admin_menu_init', 'init_admin'], $w['hooks'] ?? null);
pin('the route points at the page', [['translator', 'translator/admin/page.php']], $w['routes'] ?? null);
pin('front request leaves the screen out', [], wiring('7.0.6', false)['routes'] ?? null);
pin('an old core does not wire the screen', ['translator/index.php_uninstall', 'admin_page_header'], wiring('7.0.5', true)['hooks'] ?? null);

exit(harness_result());

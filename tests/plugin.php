<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\model {
    class KeyValue
    {
        public function get(string $group, string $key): ?array
        {
            $GLOBALS['kvCalls'][] = ['get', $group, $key];
            if ($GLOBALS['kvFail']) {
                throw new \RuntimeException('db down');
            }

            return $GLOBALS['kvRow'];
        }

        public function set(string $group, string $key, ?string $value, ?int $expiresAt = null): void
        {
            $GLOBALS['kvCalls'][] = ['set', $group, $key];
            $GLOBALS['kvSet']     = $value;
            if ($GLOBALS['kvFail']) {
                throw new \RuntimeException('db down');
            }
        }

        public function delete(string $group, string $key): int
        {
            $GLOBALS['kvCalls'][] = ['delete', $group, $key];
            if ($GLOBALS['kvFail']) {
                throw new \RuntimeException('db down');
            }

            return 1;
        }

        public function deleteGroup(string $group): int
        {
            $GLOBALS['kvCalls'][] = ['deleteGroup', $group];
            if ($GLOBALS['kvFail']) {
                throw new \RuntimeException('db down');
            }

            return 1;
        }
    }
}

namespace {
    require __DIR__ . '/lib/bootstrap.php';

    use mindstellar\translator\Plugin;
    use mindstellar\translator\Source;

    $GLOBALS['kvCalls'] = [];
    $GLOBALS['kvFail']  = false;
    $GLOBALS['kvRow']   = null;
    $GLOBALS['kvSet']   = null;

    $GLOBALS['content'] = tmpdir() . '/';
    $GLOBALS['purged']  = [];
    $GLOBALS['inits']   = 0;

    function osc_content_path()
    {
        return $GLOBALS['content'];
    }
    function osc_current_user_locale()
    {
        return 'en_US';
    }
    function osc_current_admin_locale()
    {
        return 'hi_IN';
    }
    function osc_purge_page_cache($reason)
    {
        $GLOBALS['purged'][] = $reason;
    }

    $src = new Source('core', 'core', 'core', 'Core', null, '/nowhere/', 'core.po');

    harness_section('install');
    pin('custom folder', $GLOBALS['content'] . 'plugin-data/translator/', Plugin::customDir());
    Plugin::install();
    check('folder made', is_dir(Plugin::customDir()));
    check('plugin-data/index.php written', is_file($GLOBALS['content'] . 'plugin-data/index.php'));
    check('plugin-data/.htaccess written', is_file($GLOBALS['content'] . 'plugin-data/.htaccess'));
    check('no .htaccess inside translator/', !file_exists(Plugin::customDir() . '.htaccess'));
    $before = count(glob($GLOBALS['content'] . 'plugin-data/{,*/}{*,.htaccess}', GLOB_BRACE) ?: []);
    Plugin::customDir();
    Plugin::install();
    pin('a second call makes no new files', $before, count(glob($GLOBALS['content'] . 'plugin-data/{,*/}{*,.htaccess}', GLOB_BRACE) ?: []));

    harness_section('uninstall');
    $GLOBALS['kvCalls'] = [];
    Plugin::uninstall();
    pin('drops the sync group once', [['deleteGroup', 'translator']], $GLOBALS['kvCalls']);
    $GLOBALS['kvFail'] = true;
    $thrown = false;
    try {
        Plugin::uninstall();
    } catch (\Throwable $e) {
        $thrown = true;
    }
    check('a failing delete does not throw', !$thrown);
    $GLOBALS['kvFail'] = false;

    harness_section('afterWrite before Translation is loaded');
    Plugin::afterWrite('en_US', $src);
    pin('purges', ['translator'], $GLOBALS['purged']);

    if (!class_exists('Translation', false)) {
        class Translation
        {
            public static function init(): void
            {
                $GLOBALS['inits']++;
            }
        }
    }

    harness_section('afterWrite on the site');
    Plugin::afterWrite('en_US', $src);
    pin('the user locale reloads', 1, $GLOBALS['inits']);
    Plugin::afterWrite('hi_IN', $src);
    pin('another locale does not reload', 1, $GLOBALS['inits']);

    harness_section('afterWrite in the admin');
    define('OC_ADMIN', true);
    Plugin::afterWrite('hi_IN', $src);
    pin('the admin locale reloads', 2, $GLOBALS['inits']);
    Plugin::afterWrite('en_US', $src);
    pin('the user locale does not reload in the admin', 2, $GLOBALS['inits']);
    pin('every call purges', 5, count($GLOBALS['purged']));


    harness_section('sync status');
    $site = realpath(__DIR__ . '/fixtures/site') . '/oc-content/';
    class OSCLocale
    {
        public static function getInstance(): self
        {
            return new self();
        }

        public function listAllCodes(): array
        {
            return ['hi_IN'];
        }
    }
    class Plugins
    {
        public static function listEnabled(): array
        {
            return [];
        }
    }
    function osc_translations_path()
    {
        return realpath(__DIR__ . '/fixtures/site') . '/oc-content/languages/';
    }
    function osc_themes_path()
    {
        return realpath(__DIR__ . '/fixtures/site') . '/oc-content/themes/';
    }
    function osc_plugins_path()
    {
        return realpath(__DIR__ . '/fixtures/site') . '/oc-content/plugins/';
    }
    function osc_theme()
    {
        return 'demo';
    }
    function osc_apply_filter($name, $value)
    {
        return $value;
    }

    $GLOBALS['kvCalls'] = [];
    $result = Plugin::status()->current();
    check('a result comes back', isset($result['total']) && $result['total'] === 0);
    pin('it reads, then stores', ['get', 'set'], array_column($GLOBALS['kvCalls'], 0));
    pin('group and key', ['translator', 'sync'], array_slice($GLOBALS['kvCalls'][0], 1));

    pin('the stored text is the result as JSON', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $GLOBALS['kvSet']);

    $GLOBALS['kvRow'] = ['value' => $GLOBALS['kvSet']];
    $GLOBALS['kvCalls'] = [];
    $again = Plugin::status()->current();
    pin('a stored result is read back and reused', [$result, ['get']], [$again, array_column($GLOBALS['kvCalls'], 0)]);
    $GLOBALS['kvRow'] = ['value' => 12345];
    $GLOBALS['kvCalls'] = [];
    Plugin::status()->current();
    pin('a value that is not text is ignored, so it rescans and stores', ['get', 'set'], array_column($GLOBALS['kvCalls'], 0));
    $GLOBALS['kvRow'] = null;

    $GLOBALS['kvFail']  = true;
    $GLOBALS['kvCalls'] = [];
    $result = Plugin::status()->current();
    check('a failing store still gives a result', isset($result['total']));

    harness_section('afterWrite forgets the sync result');
    $GLOBALS['kvFail']  = false;
    $GLOBALS['kvCalls'] = [];
    Plugin::afterWrite('en_US', $src);
    pin('one delete', [['delete', 'translator', 'sync']], $GLOBALS['kvCalls']);
    $GLOBALS['kvFail'] = true;
    $thrown = false;
    try {
        Plugin::afterWrite('en_US', $src);
    } catch (\Throwable $e) {
        $thrown = true;
    }
    check('a failing delete does not throw', !$thrown);

    exit(harness_result());
}

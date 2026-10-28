<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator\admin;

use mindstellar\translator\CustomStore;
use mindstellar\translator\Locales;
use mindstellar\translator\Plugin;
use mindstellar\translator\Source;
use mindstellar\translator\Sources;
use mindstellar\translator\SourceStrings;
use mindstellar\translator\SyncCheck;
use mindstellar\translator\SyncStatus;
use Params;
use Throwable;

/**
 * The Translator admin screen: its route, menu row and assets, the request state and the
 * action dispatcher. The actions themselves are in Actions.
 */
final class Screen
{
    public const ROUTE  = 'translator';
    public const ACTION = 'tr_do';

    private static string $pluginFile = '';
    private static ?Sources $sources = null;
    private static ?CustomStore $store = null;
    private static ?SyncStatus $status = null;

    public static function register(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
        osc_add_route(self::ROUTE, self::ROUTE, self::ROUTE, osc_plugin_folder($pluginFile) . 'admin/page.php');
        osc_add_hook('admin_menu_init', [self::class, 'menu']);
        osc_add_hook('init_admin', [self::class, 'boot']);
    }

    public static function menu(): void
    {
        osc_admin_menu_tools(__('Translator', 'translator'), osc_route_admin_url(self::ROUTE), 'translator', 'administrator');
    }

    public static function boot(): void
    {
        osc_admin_plugin_page(self::ROUTE, [
            'title' => __('Translator', 'translator'),
            'help'  => __('Change or translate any text on your site. Pick the language and the source, then click a text to edit it. Your changes are kept when core, themes and plugins update.', 'translator'),
        ]);
        if (Params::getParamString('route') !== self::ROUTE) {
            return;
        }
        // The page sits under Tools, so its section label says Tools, not Plugins.
        osc_add_filter('custom_plugin_title', static fn (): string => __('Tools'));
        $base = osc_plugin_url(self::$pluginFile);
        osc_register_script('translator-admin', osc_asset_url_versioned($base . 'admin/translator.js'), ['admin-osc']);
        osc_enqueue_script('translator-admin');
        osc_enqueue_style('translator-admin', osc_asset_url_versioned($base . 'admin/translator.css'));
        self::handle();
    }

    public static function handle(): void
    {
        if (Params::getParamString('route') !== self::ROUTE) {
            return;
        }
        // PHP drops the whole body, fields included, when it is bigger than post_max_size.
        if (self::isPost() && $_POST === [] && (int) Params::getServerParam('CONTENT_LENGTH') > 0) {
            osc_add_flash_error_message(osc_esc_html(__('The file is too big to upload.', 'translator')), 'admin');
            osc_redirect_to(self::url(self::state()));

            return;
        }
        $do = Params::getParamString(self::ACTION);
        if ($do === '') {
            return;
        }
        $state = self::state();
        if ($do === 'download') {
            self::guard();
            Actions::download($state, self::store());

            return;
        }
        if (!self::isPost()) {
            osc_redirect_to(self::url($state));

            return;
        }
        osc_csrf_check();
        self::guard();
        switch ($do) {
            case 'save':
                Actions::save($state, self::store());
                break;
            case 'reset':
                Actions::reset($state, self::store());
                break;
            case 'upload':
                Actions::upload($state, self::store());
                break;
            case 'keep':
                Actions::keep($state, self::store());
                break;
            case 'check':
                Actions::check($state, self::status());
                break;
            case 'undo':
                Actions::undo($state, self::store());
                break;
            case 'removeall':
                Actions::removeAll($state, self::store());
                break;
            default:
                osc_redirect_to(self::url($state));
        }
    }

    private static function isPost(): bool
    {
        return strtoupper((string) Params::getServerParam('REQUEST_METHOD')) === 'POST';
    }

    public static function use(?Sources $sources, ?CustomStore $store, ?SyncStatus $status = null): void
    {
        self::$sources = $sources;
        self::$store   = $store;
        self::$status  = $status;
    }

    /** @return array{locale: string, source: Source, q: string, filter: string, page: int} */
    public static function state(): array
    {
        $installed = Locales::installed();
        $locale    = Params::getParamString('locale');
        if (!in_array($locale, $installed, true)) {
            $default = function_exists('osc_language') ? (string) osc_language() : '';
            $locale  = in_array($default, $installed, true) ? $default : ($installed[0] ?? 'en_US');
        }

        $sources = self::sources();
        $source  = $sources->find(Params::getParamString('source')) ?? $sources->find('core') ?? ($sources->all()[0] ?? null);
        if ($source === null) {
            $source = new Source('core', 'core', 'core', 'Core', null, '', 'core.po');
        }

        $q = trim(Params::getParamString('q', false, false, false));
        $q = preg_match('//u', $q) === 1 ? trim(mb_substr($q, 0, Listing::MAX_QUERY)) : '';

        return [
            'locale' => $locale,
            'source' => $source,
            'q'      => $q,
            'filter' => (string) Params::getParamEnum('filter', Listing::FILTERS, 'all'),
            'page'   => max(1, Params::getParamInt('iPage', 1)),
        ];
    }

    public static function view(): array
    {
        $state   = self::state();
        $locale  = $state['locale'];
        $source  = $state['source'];
        $store   = self::store();
        $strings = SourceStrings::read($source, $locale);
        $custom  = $store->read($locale, $source);
        $flagged = SyncCheck::compare($strings, $custom);
        $rows    = Listing::rows($strings, $custom, $flagged);
        foreach ($rows as $key => $row) {
            $rows[$key]['replaced'] = $custom[$key]['replaced'] ?? [];
        }
        $listing = Listing::page($rows, $state['q'], $state['filter'], $state['page']);
        $state['page'] = $listing['page'];
        $forms   = SourceStrings::pluralForms($source, $locale);

        $locales = [];
        foreach (Locales::installed() as $code) {
            $locales[$code] = self::localeLabel($code);
        }
        $groups = [];
        foreach (self::sources()->all() as $s) {
            $group = $s->kind() === 'plugin' ? __('Plugins', 'translator')
                : ($s->kind() === 'theme' ? __('Theme', 'translator') : __('Core', 'translator'));
            $groups[$group][] = $s;
        }

        $sync   = null;
        $hasAny = false;
        $labels = [];
        try {
            $status = self::status();
            $hasAny = $status->pairs() !== [];
            $sync   = $status->current();
            foreach ($sync['items'] as $pair => $item) {
                $found = self::sources()->find((string) ($item['source'] ?? ''));
                $labels[$pair] = self::localeLabel((string) ($item['locale'] ?? '')) . ', ' . ($found !== null ? $found->label() : (string) ($item['source'] ?? ''));
            }
        } catch (Throwable $e) {
            $sync = null;
        }

        return $state + [
            'locales'     => $locales,
            'sources'     => self::sources()->all(),
            'groups'      => $groups,
            'listing'     => $listing,
            'flagged'     => $flagged,
            'sync'        => $sync,
            'hasAny'      => $hasAny,
            'pairLabels'  => $labels,
            'counts'      => Listing::counts($rows),
            'nplurals'    => SourceStrings::pluralCount($forms) ?? 2,
            'pluralForms' => $forms,
            'hasCustom'   => is_file($store->poPath($locale, $source)),
            'hasBackup'   => $store->hasBackup($locale, $source),
            'urls'        => [
                'self'     => self::url($state),
                'download' => self::url($state, [self::ACTION => 'download']),
                'post'     => osc_route_admin_url(self::ROUTE),
            ],
            'messages'    => [
                'empty'    => __('Enter a text', 'translator'),
                'keep'     => __('Your text must keep %s', 'translator'),
                'extra'    => __('Your text has a placeholder the original does not have: %s', 'translator'),
                'tags'     => __('Your text must keep the same HTML tags as the original', 'translator'),
                'unsafe'   => __('Your text cannot contain scripts or event handlers', 'translator'),
                'ok'       => __('Looks good.', 'translator'),
                'title'    => __('Edit text', 'translator'),
                'original' => __('Original text', 'translator'),
                'plural'   => __('Plural', 'translator'),
                'yours'    => __('Your text', 'translator'),
                'singular' => __('Singular', 'translator'),
                'form'     => __('Plural form %s', 'translator'),
            ],
        ];
    }

    /** The list URL for a state; q and filter are left out at their defaults. */
    public static function url(array $state, array $extra = []): string
    {
        $source = $state['source'] ?? null;
        $args   = [
            'locale' => (string) ($state['locale'] ?? ''),
            'source' => $source instanceof Source ? $source->name() : (string) $source,
        ];
        if (($state['q'] ?? '') !== '') {
            $args['q'] = (string) $state['q'];
        }
        if (($state['filter'] ?? 'all') !== 'all') {
            $args['filter'] = (string) $state['filter'];
        }
        $args['iPage'] = (string) ($state['page'] ?? 1);
        $out = [];
        foreach ($args + $extra as $k => $v) {
            $out[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }

        return osc_route_admin_url(self::ROUTE, $out);
    }

    /** The summary of every custom text that needs a look; empty when the site has no custom texts. */
    public static function banner(array $view): string
    {
        $sync = $view['sync'] ?? null;
        if (!is_array($sync) || empty($view['hasAny'])) {
            return '';
        }
        $trSync   = $sync;
        $trLabels = $view['pairLabels'] ?? [];
        $trFields = self::hidden($view);
        $trAgo    = self::ago((int) $sync['at'], time());
        ob_start();
        include dirname(__DIR__, 2) . '/admin/banner.php';

        return trim((string) ob_get_clean());
    }

    /**
     * The hidden fields every form on the screen posts, so the action lands back on the same list.
     *
     * @return array<string, string>
     */
    public static function hidden(array $view): array
    {
        $source = $view['source'] ?? null;

        return [
            'page'   => 'plugins',
            'action' => 'renderplugin',
            'route'  => self::ROUTE,
            'locale' => (string) ($view['locale'] ?? ''),
            'source' => $source instanceof Source ? $source->name() : (string) $source,
            'q'      => (string) ($view['q'] ?? ''),
            'filter' => (string) ($view['filter'] ?? 'all'),
            'iPage'  => (string) ($view['page'] ?? 1),
        ];
    }

    private static function ago(int $at, int $now): string
    {
        $diff = $now - $at;
        if ($diff < 60) {
            return __('just now', 'translator');
        }
        if ($diff < 3600) {
            $n = intdiv($diff, 60);

            return sprintf(_n('%d minute ago', '%d minutes ago', $n, 'translator'), $n);
        }
        if ($diff < 86400) {
            $n = intdiv($diff, 3600);

            return sprintf(_n('%d hour ago', '%d hours ago', $n, 'translator'), $n);
        }

        return (string) osc_format_date(date('Y-m-d H:i:s', $at), osc_date_format() . ' ' . osc_time_format());
    }

    private static function sources(): Sources
    {
        return self::$sources ?? (self::$sources = Plugin::sources());
    }

    private static function store(): CustomStore
    {
        return self::$store ?? Plugin::store();
    }

    private static function status(): SyncStatus
    {
        return self::$status ?? Plugin::status();
    }

    private static function localeLabel(string $code): string
    {
        $row = class_exists('OSCLocale') ? \OSCLocale::getInstance()->findByCode($code) : [];
        if (is_array($row) && isset($row[0]) && is_array($row[0])) {
            $row = $row[0];
        }
        $name = is_array($row) ? trim((string) ($row['s_name'] ?? '')) : '';

        return $name !== '' ? $name : $code;
    }

    private static function guard(): void
    {
        if (osc_is_moderator()) {
            osc_add_flash_error_message(osc_esc_html(__('Only an administrator can change texts.', 'translator')), 'admin');
            osc_redirect_to(osc_admin_base_url(true));
        }
    }
}

<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

final class Sources
{
    /** A theme or plugin folder name; /D stops `$` matching before a trailing newline. */
    private const SLUG = '[A-Za-z0-9][A-Za-z0-9_-]*';

    public const NAME_PATTERN = '/^(core|messages|theme-' . self::SLUG . '|plugin-' . self::SLUG . ')$/D';

    /** Source kinds in the order core loads catalogues. */
    public const KINDS = ['core', 'messages', 'theme', 'plugin'];

    private const SLUG_PATTERN = '/^' . self::SLUG . '$/D';

    private string $translationsPath;
    private string $themesPath;
    private string $pluginsPath;
    private string $themeSlug;
    private string $parentSlug;
    private string $templatesDir;
    private array $enabledPlugins;
    /** @var callable|null */
    private $pluginLabel;
    /** @var callable|null */
    private $themeLabel;
    /** @var Source[]|null */
    private ?array $all = null;

    /**
     * The label callables take a slug and return the display name, or empty to use the slug.
     * $parentSlug is the parent of a child theme, or empty. With $templatesDir set, a theme or plugin
     * that ships no template is listed too, so one can be made from its files.
     */
    public function __construct(
        string $translationsPath,
        string $themesPath,
        string $pluginsPath,
        string $themeSlug,
        array $enabledPlugins,
        ?callable $pluginLabel = null,
        ?callable $themeLabel = null,
        string $parentSlug = '',
        string $templatesDir = ''
    ) {
        $this->translationsPath = $translationsPath;
        $this->themesPath       = $themesPath;
        $this->pluginsPath      = $pluginsPath;
        $this->themeSlug        = $themeSlug;
        $this->enabledPlugins   = $enabledPlugins;
        $this->pluginLabel      = $pluginLabel;
        $this->themeLabel       = $themeLabel;
        $this->parentSlug       = $parentSlug;
        $this->templatesDir     = $templatesDir === '' ? '' : rtrim($templatesDir, '/') . '/';
    }

    public static function fromSite(): self
    {
        $theme = (string) osc_apply_filter('theme', osc_theme());

        return new self(
            osc_translations_path(),
            osc_themes_path(),
            osc_plugins_path(),
            $theme,
            \Plugins::listEnabled(),
            static function (string $slug) {
                $info = \Plugins::getInfo($slug . '/index.php');

                return is_array($info) ? ($info['plugin_name'] ?? '') : '';
            },
            static function (string $slug) {
                return self::themeTitle(\WebThemes::getInstance()->loadThemeInfo($slug));
            },
            self::parentTheme($theme),
            Plugin::customDir() . 'templates/'
        );
    }

    /** The "Parent Theme:" header of a child theme, or empty when it has none. */
    public static function parentTheme(string $slug): string
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || !class_exists('WebThemes')) {
            return '';
        }
        $info   = \WebThemes::getInstance()->loadThemeInfo($slug);
        $parent = is_array($info) ? trim((string) ($info['template'] ?? '')) : '';

        return $parent !== $slug && preg_match(self::SLUG_PATTERN, $parent) === 1 ? $parent : '';
    }

    /** The theme header's name, with a lower-case header such as "storefront" shown as "Storefront". */
    public static function themeTitle($info): string
    {
        $name = is_array($info) ? trim((string) ($info['name'] ?? $info['theme_name'] ?? '')) : '';
        if ($name === '') {
            return '';
        }

        return $name === strtolower($name) ? ucwords(str_replace(['-', '_'], ' ', $name)) : $name;
    }

    public static function isName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public static function themeName(string $slug): string
    {
        return 'theme-' . $slug;
    }

    public static function pluginName(string $slug): string
    {
        return 'plugin-' . $slug;
    }

    /** The plugin folder of a `Plugins::listEnabled()` entry such as `stripe/index.php`, or null when unsafe. */
    public static function pluginSlug(string $entry): ?string
    {
        $slug = strstr($entry, '/', true);

        return $slug !== false && preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }

    /** @return array{0:string, 1:string}|null [kind, domain] for a source name, null when it is not one */
    public static function parseName(string $name): ?array
    {
        if (!self::isName($name)) {
            return null;
        }
        foreach (['theme', 'plugin'] as $kind) {
            if (strncmp($name, $kind . '-', strlen($kind) + 1) === 0) {
                return [$kind, substr($name, strlen($kind) + 1)];
            }
        }

        return [$name, $name];
    }

    /** @return Source[] */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $candidates = [
            new Source(
                'core',
                'core',
                'core',
                'Core',
                self::fileOrNull($this->translationsPath . 'core.pot'),
                $this->translationsPath,
                'core.po'
            ),
            new Source(
                'messages',
                'messages',
                'messages',
                'Core messages',
                self::fileOrNull($this->translationsPath . 'messages.pot'),
                $this->translationsPath,
                'messages.po'
            ),
        ];

        // A child theme's templates fall back to its parent's, so both hold texts the site shows.
        foreach (array_unique([$this->themeSlug, $this->parentSlug]) as $slug) {
            $theme = $slug !== '' ? $this->themeSource($slug) : null;
            if ($theme !== null) {
                $candidates[] = $theme;
            }
        }

        $plugins = [];
        foreach ($this->enabledPlugins as $entry) {
            $plugin = is_string($entry) ? $this->pluginSource($entry) : null;
            if ($plugin !== null) {
                $plugins[$plugin->name()] = $plugin;
            }
        }
        $plugins = array_values($plugins);
        usort($plugins, static fn (Source $a, Source $b): int => strcasecmp($a->label(), $b->label()));

        $all = [];
        foreach (array_merge($candidates, $plugins) as $source) {
            if ($source->template() !== 'none' || $source->canMakeTemplate()) {
                $all[] = $source;
            }
        }

        return $this->all = $all;
    }

    /** Only names all() gives match, so a request value never becomes a path. */
    public function find(string $name): ?Source
    {
        foreach ($this->all() as $source) {
            if ($source->name() === $name) {
                return $source;
            }
        }

        return null;
    }

    private function themeSource(string $slug): ?Source
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || !is_dir($this->themesPath . $slug)) {
            return null;
        }
        $dir = $this->themesPath . $slug . '/languages/';

        return new Source(
            self::themeName($slug),
            'theme',
            $slug,
            'Theme: ' . $this->label($this->themeLabel, $slug),
            self::findPot($dir, [$slug . '.pot', 'theme.pot']),
            $dir,
            'theme.po',
            $this->themesPath . $slug,
            $this->templateFile(self::themeName($slug))
        );
    }

    private function pluginSource(string $entry): ?Source
    {
        $slug = self::pluginSlug($entry);
        if ($slug === null) {
            return null;
        }
        $dir = $this->pluginsPath . $slug . '/languages/';

        return new Source(
            self::pluginName($slug),
            'plugin',
            $slug,
            'Plugin: ' . $this->label($this->pluginLabel, $slug),
            self::findPot($dir, [$slug . '.pot', 'messages.pot']),
            $dir,
            'messages.po',
            is_dir($this->pluginsPath . $slug) ? $this->pluginsPath . $slug : '',
            $this->templateFile(self::pluginName($slug))
        );
    }

    /** Where a template made for a source is kept; the name comes from all(), never from a request. */
    private function templateFile(string $name): string
    {
        return $this->templatesDir !== '' && self::isName($name) ? $this->templatesDir . $name . '.pot' : '';
    }

    private function label(?callable $reader, string $slug): string
    {
        $label = $reader !== null ? $reader($slug) : '';

        return is_string($label) && trim($label) !== '' ? trim($label) : $slug;
    }

    private static function fileOrNull(string $path): ?string
    {
        return is_file($path) ? $path : null;
    }

    /** @param string[] $names */
    private static function findPot(string $dir, array $names): ?string
    {
        foreach ($names as $name) {
            if (is_file($dir . $name)) {
                return $dir . $name;
            }
        }
        $found = glob($dir . '*.pot');

        return is_array($found) && count($found) === 1 ? $found[0] : null;
    }
}

<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

final class Loader
{
    private string $dir;
    private string $themeSlug;
    private array $enabledPlugins;

    public function __construct(string $dir, string $themeSlug, array $enabledPlugins)
    {
        $this->dir            = rtrim($dir, '/') . '/';
        $this->themeSlug      = $themeSlug;
        $this->enabledPlugins = $enabledPlugins;
    }

    /** Hook callback: loads the custom catalogues for the locale that was just loaded. */
    public static function onLoaded($translation, $locale): void
    {
        $dir = '';
        try {
            $dir = Plugin::customDir();
            (new self($dir, (string) osc_apply_filter('theme', osc_theme()), \Plugins::listEnabled()))
                ->load($translation, (string) $locale);
        } catch (\Throwable $e) {
            error_log('translator: could not load custom texts from ' . $dir . ': ' . $e->getMessage());
        }
    }

    /** @return string[] the .mo paths loaded, in order */
    public function load(object $translation, string $locale): array
    {
        if (!Locales::isCode($locale)) {
            return [];
        }
        $files = glob($this->dir . $locale . '/*.mo');
        if (!is_array($files)) {
            return [];
        }

        $enabled = [];
        foreach ($this->enabledPlugins as $entry) {
            $slug = is_string($entry) ? Sources::pluginSlug($entry) : null;
            if ($slug !== null) {
                $enabled[$slug] = true;
            }
        }

        $byKind = array_fill_keys(Sources::KINDS, []);
        foreach ($files as $path) {
            $parsed = Sources::parseName(basename($path, '.mo'));
            if ($parsed === null) {
                continue;
            }
            [$kind, $domain] = $parsed;
            if (($kind === 'theme' && $domain !== $this->themeSlug) || ($kind === 'plugin' && !isset($enabled[$domain]))) {
                continue;
            }
            $byKind[$kind][$domain] = $path;
        }
        ksort($byKind['plugin'], SORT_STRING);

        $loaded = [];
        foreach ($byKind as $catalogues) {
            foreach ($catalogues as $domain => $path) {
                try {
                    $translation->_load($path, (string) $domain);
                    $loaded[] = $path;
                } catch (\Throwable $e) {
                    error_log('translator: could not load ' . $path . ': ' . $e->getMessage());
                }
            }
        }

        return $loaded;
    }
}

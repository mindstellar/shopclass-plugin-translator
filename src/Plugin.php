<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

use mindstellar\utility\FileSystem;

final class Plugin
{
    public const SLUG = 'translator';
    public const DATA_ROOT = 'plugin-data/';

    private static ?string $dir = null;

    /** The folder for the custom texts, with a trailing slash. Core makes it when it can; else the plugin does and closes plugin-data/. */
    public static function customDir(): string
    {
        if (self::$dir !== null) {
            return self::$dir;
        }
        $dir = function_exists('osc_plugin_data_path') ? (string) osc_plugin_data_path(self::SLUG) : '';
        if ($dir === '') {
            $root = osc_content_path() . self::DATA_ROOT;
            $dir  = $root . self::SLUG . '/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir)) {
                FileSystem::protectFolder($root);
            }
        }

        return self::$dir = rtrim($dir, '/') . '/';
    }

    /** The store the admin screen uses; tests build CustomStore with their own afterWrite instead. */
    public static function store(): CustomStore
    {
        return new CustomStore(self::customDir(), [self::class, 'afterWrite']);
    }

    /** The sources the admin screen lists. */
    public static function sources(): Sources
    {
        return Sources::fromSite();
    }

    /** The sync check over every custom file; a database error means no cache, never a broken screen. */
    public static function status(): SyncStatus
    {
        return new SyncStatus(
            self::sources(),
            self::store(),
            self::customDir(),
            Locales::installed(),
            static function (): ?string {
                $row = self::kv(static fn ($kv) => $kv->get(SyncStatus::KV_GROUP, SyncStatus::KV_KEY));

                return is_array($row) && is_string($row['value'] ?? null) ? $row['value'] : null;
            },
            static function (?string $json): void {
                self::kv(static function ($kv) use ($json) {
                    return $json === null
                        ? $kv->delete(SyncStatus::KV_GROUP, SyncStatus::KV_KEY)
                        : $kv->set(SyncStatus::KV_GROUP, SyncStatus::KV_KEY, $json);
                });
            }
        );
    }

    /** Drops the stored sync result; a database error is ignored. */
    public static function forgetStatus(): void
    {
        self::kv(static fn ($kv) => $kv->delete(SyncStatus::KV_GROUP, SyncStatus::KV_KEY));
    }

    /** Runs $use on the key-value store; a database error gives null, because no cache is fine. */
    private static function kv(callable $use)
    {
        try {
            return $use(new \mindstellar\model\KeyValue());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Clears the page cache and, for the locale in use, reloads the texts so this request shows them. */
    public static function afterWrite(string $locale, Source $s): void
    {
        self::forgetStatus();
        if (function_exists('osc_purge_page_cache')) {
            osc_purge_page_cache('translator');
        }
        $current = defined('OC_ADMIN') && OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();
        if ($locale === $current && class_exists('Translation', false)) {
            \Translation::init();
        }
    }

    public static function install(): void
    {
        self::customDir();
    }

    /** Drops the sync cache. The custom texts stay on disk. */
    public static function uninstall(): void
    {
        self::kv(static fn ($kv) => $kv->deleteGroup(SyncStatus::KV_GROUP));
    }
}

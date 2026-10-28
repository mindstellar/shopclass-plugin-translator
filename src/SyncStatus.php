<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

/** Scans every custom text file against its source, and keeps the result until a file changes. */
final class SyncStatus
{
    public const KV_GROUP = 'translator';
    public const KV_KEY   = 'sync';

    private Sources $sources;
    private CustomStore $store;
    private string $customDir;
    private array $installedLocales;
    private ?array $pairs = null;
    /** @var callable */
    private $read;
    /** @var callable */
    private $write;

    /**
     * $read() returns the stored JSON or null; $write(?string $json) stores it, or forgets it on null.
     */
    public function __construct(Sources $sources, CustomStore $store, string $customDir, array $installedLocales, callable $read, callable $write)
    {
        $this->sources          = $sources;
        $this->store            = $store;
        $this->customDir        = rtrim($customDir, '/') . '/';
        $this->installedLocales = $installedLocales;
        $this->read             = $read;
        $this->write            = $write;
    }

    /**
     * Custom files that belong to an installed locale and a listed source.
     *
     * @return array<string, array{0:string, 1:Source}> e.g. ['hi_IN/core' => ['hi_IN', Source]], sorted by key
     */
    public function pairs(): array
    {
        if ($this->pairs !== null) {
            return $this->pairs;
        }
        $out = [];
        foreach (glob($this->customDir . '*/*.po') ?: [] as $file) {
            $locale = basename(dirname($file));
            if (!Locales::isCode($locale) || !in_array($locale, $this->installedLocales, true)) {
                continue;
            }
            $name   = basename($file, '.po');
            $source = $this->sources->find($name);
            if ($source !== null) {
                $out[$locale . '/' . $name] = [$locale, $source];
            }
        }
        ksort($out);

        return $this->pairs = $out;
    }

    public function fingerprint(): string
    {
        $lines = [];
        foreach ($this->pairs() as $key => [$locale, $source]) {
            $lines[] = 'pair|' . $key;
            $paths   = [
                $this->store->poPath($locale, $source),
                $source->originalsPath($locale),
                $source->shippedPoPath($locale),
            ];
            foreach ($paths as $path) {
                $lines[] = $path === null ? 'none' : self::stat($path);
            }
        }
        sort($lines);

        return sha1(implode("\n", $lines));
    }

    /**
     * @return array{at:int, fingerprint:string, total:int, items:array<string, array>}
     */
    public function run(): array
    {
        $fingerprint = $this->fingerprint();
        $items       = [];
        $total       = 0;
        foreach ($this->pairs() as $key => [$locale, $source]) {
            $counts = SyncCheck::counts(SyncCheck::compare(
                SourceStrings::read($source, $locale),
                $this->store->read($locale, $source)
            ));
            if ($counts['total'] > 0) {
                $items[$key] = ['locale' => $locale, 'source' => $source->name()] + $counts;
                $total      += $counts['total'];
            }
        }

        return ['at' => time(), 'fingerprint' => $fingerprint, 'total' => $total, 'items' => $items];
    }

    /** The stored result while its fingerprint still matches; otherwise a fresh scan, which is stored. */
    public function current(bool $force = false): array
    {
        if (!$force) {
            $stored = $this->stored();
            if ($stored !== null && $stored['fingerprint'] === $this->fingerprint()) {
                return $stored;
            }
        }
        $result = $this->run();
        ($this->write)(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    public function forget(): void
    {
        ($this->write)(null);
    }

    private function stored(): ?array
    {
        $json = ($this->read)();
        if (!is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);
        if (
            !is_array($data) || !isset($data['fingerprint'], $data['at'], $data['total'], $data['items'])
            || !is_string($data['fingerprint']) || !is_int($data['at']) || !is_int($data['total']) || !is_array($data['items'])
        ) {
            return null;
        }
        foreach ($data['items'] as $key => $item) {
            if (!self::validItem($key, $item)) {
                return null;
            }
        }

        return $data;
    }

    private static function validItem($key, $item): bool
    {
        if (!is_string($key) || !is_array($item) || !is_string($item['locale'] ?? null) || !is_string($item['source'] ?? null)) {
            return false;
        }
        if (!Locales::isCode($item['locale']) || !Sources::isName($item['source']) || $key !== $item['locale'] . '/' . $item['source']) {
            return false;
        }
        foreach (['removed', 'newer', 'placeholders', 'total'] as $count) {
            if (!is_int($item[$count] ?? null) || $item[$count] < 0) {
                return false;
            }
        }

        return true;
    }

    private static function stat(string $path): string
    {
        clearstatcache(true, $path);
        $time = @filemtime($path);
        $size = @filesize($path);

        return $time === false || $size === false ? $path . '|missing' : $path . '|' . $time . '|' . $size;
    }
}

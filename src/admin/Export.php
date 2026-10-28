<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator\admin;

use Gettext\Generators\Po;
use mindstellar\translator\CustomStore;
use mindstellar\translator\Source;
use mindstellar\translator\Sources;
use mindstellar\translator\SourceStrings;
use RuntimeException;

/** The full translation of a source: the shipped texts with the owner's changes over them. */
final class Export
{
    /** Tests point this at a missing class to act like a host with no zip support. */
    public static string $zipClass = 'ZipArchive';

    public static function zipAvailable(): bool
    {
        return class_exists(self::$zipClass);
    }

    /** Keeps only characters that are safe in a download file name. */
    public static function cleanName(string $part): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $part) ?? '';
    }

    public static function fileName(string $locale, string $source): string
    {
        return self::cleanName($source) . '-' . self::cleanName($locale) . '.po';
    }

    public static function zipName(string $locale): string
    {
        return 'translator-' . self::cleanName($locale) . '.zip';
    }

    /** One .po with every text of the source; the owner's text wins, an untranslated text has an empty msgstr. */
    public static function po(Source $source, string $locale, CustomStore $store): string
    {
        $strings = SourceStrings::read($source, $locale);
        $custom  = $store->read($locale, $source);
        $t       = CustomStore::catalogue($locale, SourceStrings::pluralForms($source, $locale), $source->domain());
        $t->setHeader('Project-Id-Version', $source->name());
        $t->setHeader('MIME-Version', '1.0');
        $t->setHeader('Content-Transfer-Encoding', '8bit');

        foreach ($strings as $key => $s) {
            CustomStore::insert($t, [
                'context'    => $s['context'],
                'original'   => $s['original'],
                'plural'     => $s['plural'],
                'text'       => isset($custom[$key]) ? $custom[$key]['text'] : ($s['translated'] ? $s['shipped'] : []),
                'references' => $s['references'],
            ]);
        }

        return Po::toString($t);
    }

    /**
     * Writes a zip with one .po per source that has texts, and returns its path. The caller deletes the file.
     *
     * @return array{0: string, 1: int} the path and how many files are in it
     */
    public static function zip(Sources $sources, string $locale, CustomStore $store): array
    {
        $class = self::$zipClass;
        if (!class_exists($class)) {
            throw new RuntimeException(__('This server cannot make zip files.', 'translator'));
        }
        $path = tempnam(sys_get_temp_dir(), 'trz');
        if ($path === false) {
            throw new RuntimeException(__('Could not make a temporary file for the export.', 'translator'));
        }
        $zip = new $class();
        if ($zip->open($path, $class::CREATE | $class::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException(__('Could not open the zip file.', 'translator'));
        }
        $n = 0;
        foreach ($sources->all() as $source) {
            if (SourceStrings::read($source, $locale) === []) {
                continue;
            }
            $zip->addFromString(self::fileName($locale, $source->name()), self::po($source, $locale, $store));
            $n++;
        }
        $closed = $zip->close();
        if (!$closed || $n === 0) {
            @unlink($path);
            if ($n === 0) {
                return ['', 0];
            }
            throw new RuntimeException(__('Could not write the zip file.', 'translator'));
        }

        return [$path, $n];
    }

    /** The export links for the Import and export drawer; the zip link is left out when the host has no zip support. */
    public static function links(array $state): string
    {
        $full = Screen::url($state, [Screen::ACTION => 'download', 'export' => 'full']);
        $out  = '<a class="btn btn-sm btn-dim" href="' . osc_esc_html($full) . '"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i> '
            . osc_esc_html(__('Export full translation (.po)', 'translator')) . '</a>';
        if (self::zipAvailable()) {
            $zip  = Screen::url($state, [Screen::ACTION => 'download', 'export' => 'zip']);
            $out .= ' <a class="btn btn-sm btn-dim" href="' . osc_esc_html($zip) . '"><i class="bi bi-file-earmark-zip" aria-hidden="true"></i> '
                . osc_esc_html(__('Export all as .zip', 'translator')) . '</a>';
        }

        return $out;
    }
}

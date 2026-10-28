<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator\admin;

use InvalidArgumentException;
use mindstellar\translator\CustomStore;
use mindstellar\translator\Plugin;
use mindstellar\translator\Sources;
use mindstellar\translator\SourceStrings;
use mindstellar\translator\SyncStatus;
use mindstellar\translator\TemplateBuilder;
use Params;
use Throwable;

/**
 * The Translator screen's save, reset, keep, check, upload, undo, remove all, template and download actions. Each one ends in a
 * redirect back to the list, or in the file for a download.
 */
final class Actions
{
    public const UPLOAD_MODES = ['add', 'replace'];

    /** @var callable(string, string): void|null Tests replace it; null sends the file and exits. */
    public static $send = null;

    /** Removes the owner's entry, so it also clears one the source no longer has. */
    public static function reset(array $state, CustomStore $store): void
    {
        $locale  = $state['locale'];
        $source  = $state['source'];
        $strings = SourceStrings::read($source, $locale);
        try {
            $custom = $store->read($locale, $source);
            [$context, $original] = self::target($strings, $custom);
            if (!isset($custom[SourceStrings::key($context, $original)])) {
                osc_add_flash_warning_message(osc_esc_html(__('That text was not changed.', 'translator')), 'admin');
            } else {
                $store->remove($locale, $source, $context, $original);
                osc_add_flash_ok_message(osc_esc_html(__('Back to the original text.', 'translator')), 'admin');
            }
        } catch (Throwable $e) {
            osc_add_flash_error_message(self::writeError(), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    public static function save(array $state, CustomStore $store): void
    {
        $locale  = $state['locale'];
        $source  = $state['source'];
        $strings = SourceStrings::read($source, $locale);
        $forms   = SourceStrings::pluralForms($source, $locale);
        [$context, $original] = self::target($strings);
        $text = Params::getParamArray('text', false, false, false);
        if ($text === []) {
            osc_add_flash_error_message(osc_esc_html(__('No text was sent. Nothing was changed.', 'translator')), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        $result = TextForm::validate($strings, $context, $original, $text, SourceStrings::pluralCount($forms) ?? 2);

        try {
            if ($result['errors'] !== []) {
                osc_add_flash_error_message(self::escList($result['errors']), 'admin');
            } elseif ($result['reset']) {
                $store->remove($locale, $source, $context, $original);
                osc_add_flash_ok_message(osc_esc_html(__('Back to the original text.', 'translator')), 'admin');
            } else {
                $store->set($locale, $source, (array) $result['entry'], $forms);
                osc_add_flash_ok_message(osc_esc_html(__('Text saved.', 'translator')), 'admin');
            }
        } catch (Throwable $e) {
            osc_add_flash_error_message(self::writeError(), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    /** Keeps the owner's text and records the current original as the one it replaces. */
    public static function keep(array $state, CustomStore $store): void
    {
        $locale = $state['locale'];
        $source = $state['source'];
        try {
            $strings = SourceStrings::read($source, $locale);
            $custom  = $store->read($locale, $source);
            [$context, $original] = self::target($strings, $custom);
            $key = SourceStrings::key($context, $original);
            if (!isset($custom[$key])) {
                osc_add_flash_error_message(osc_esc_html(__('That text is not one of your changes.', 'translator')), 'admin');
            } elseif (!isset($strings[$key])) {
                osc_add_flash_error_message(osc_esc_html(__('That text is not in this source any more', 'translator')), 'admin');
            } else {
                $entry  = $custom[$key];
                $errors = TextForm::validate($strings, $context, $original, $entry['text'], count($entry['text']))['errors'];
                if ($errors !== []) {
                    osc_add_flash_error_message(self::escList($errors), 'admin');
                } else {
                    $entry['replaced'] = array_values($strings[$key]['shipped']);
                    $store->set($locale, $source, $entry, SourceStrings::pluralForms($source, $locale));
                    osc_add_flash_ok_message(osc_esc_html(__('Kept your text.', 'translator')), 'admin');
                }
            }
        } catch (Throwable $e) {
            osc_add_flash_error_message(self::writeError(), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    /** Scans every custom file again, whatever the stored result says. */
    public static function check(array $state, SyncStatus $status): void
    {
        try {
            $status->current(true);
            osc_add_flash_ok_message(osc_esc_html(__('Checked.', 'translator')), 'admin');
        } catch (Throwable $e) {
            osc_add_flash_error_message(osc_esc_html(__('Could not check your texts.', 'translator')), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    /**
     * The posted context and original; browsers send line breaks as CRLF, so those become LF
     * unless the raw pair is a known key.
     *
     * @return array{0: string, 1: string}
     */
    private static function target(array ...$known): array
    {
        $context  = Params::getParamString('context', false, false, false);
        $original = Params::getParamString('original', false, false, false);
        $key      = SourceStrings::key($context, $original);
        foreach ($known as $map) {
            if (isset($map[$key])) {
                return [$context, $original];
            }
        }

        return [str_replace("\r\n", "\n", $context), str_replace("\r\n", "\n", $original)];
    }

    public static function upload(array $state, CustomStore $store): void
    {
        $locale = $state['locale'];
        $source = $state['source'];
        $file   = Params::getFiles('file');
        $error  = PoUpload::fileError(is_array($file) ? $file : []);
        if ($error !== null) {
            osc_add_flash_error_message(osc_esc_html($error), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }

        $strings = SourceStrings::read($source, $locale);
        $forms   = SourceStrings::pluralForms($source, $locale);
        try {
            $content = file_get_contents((string) $file['tmp_name']);
            $result  = PoUpload::parse(is_string($content) ? $content : '', $strings, SourceStrings::pluralCount($forms) ?? 2);
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(osc_esc_html($e->getMessage()), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        } catch (Throwable $e) {
            osc_add_flash_error_message(osc_esc_html(__('The file is not a .po file we can read.', 'translator')), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }

        if ($result['saved'] === 0) {
            $lines = array_merge([__('No texts in the file match this source.', 'translator')], self::skipped($result));
            osc_add_flash_error_message(self::escList($lines), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        try {
            $store->backup($locale, $source);
            if (Params::getParamEnum('mode', self::UPLOAD_MODES, 'add') === 'replace') {
                $store->replaceAll($locale, $source, $result['entries'], $forms);
            } else {
                $store->merge($locale, $source, $result['entries'], $forms);
            }
        } catch (Throwable $e) {
            osc_add_flash_error_message(self::writeError(), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        $saved = (int) $result['saved'];
        osc_add_flash_ok_message(osc_esc_html(sprintf(_n('%d text saved.', '%d texts saved.', $saved, 'translator'), $saved)), 'admin');
        $skipped = self::skipped($result);
        if ($skipped !== []) {
            osc_add_flash_warning_message(self::escList($skipped), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    /** Puts back the texts as they were before the last upload or remove all. */
    public static function undo(array $state, CustomStore $store): void
    {
        $locale = $state['locale'];
        $source = $state['source'];
        if (!$store->hasBackup($locale, $source)) {
            osc_add_flash_warning_message(osc_esc_html(__('There is nothing to undo.', 'translator')), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        try {
            $store->restore($locale, $source);
            osc_add_flash_ok_message(osc_esc_html(__('Your texts are back as they were.', 'translator')), 'admin');
        } catch (Throwable $e) {
            osc_add_flash_error_message(self::writeError(), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    public static function removeAll(array $state, CustomStore $store): void
    {
        $locale = $state['locale'];
        $source = $state['source'];
        try {
            if ($store->read($locale, $source) === []) {
                osc_add_flash_warning_message(osc_esc_html(__('You have not changed any texts here yet.', 'translator')), 'admin');
            } else {
                $store->backup($locale, $source);
                $n = $store->removeAll($locale, $source);
                osc_add_flash_ok_message(osc_esc_html(sprintf(_n('%d text removed.', '%d texts removed.', $n, 'translator'), $n)), 'admin');
            }
        } catch (Throwable $e) {
            osc_add_flash_error_message(self::writeError(), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    /** Makes, or makes again, the template of a theme or plugin that ships none. */
    public static function template(array $state): void
    {
        $source = $state['source'];
        if (!$source->canMakeTemplate()) {
            osc_add_flash_warning_message(osc_esc_html(__('This source has its own template.', 'translator')), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        try {
            $n = TemplateBuilder::build($source->folder(), $source->domain(), $source->templateFile());
        } catch (Throwable $e) {
            osc_add_flash_error_message(self::writeError(), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        if ($n === 0) {
            osc_add_flash_error_message(osc_esc_html(__('No texts for this source were found in its files.', 'translator')), 'admin');
        } else {
            osc_add_flash_ok_message(osc_esc_html(sprintf(_n('Template made with %d text.', 'Template made with %d texts.', $n, 'translator'), $n)), 'admin');
        }
        osc_redirect_to(Screen::url($state));
    }

    /** @return string[] one line per kind of skipped text, naming the first five */
    private static function skipped(array $result): array
    {
        $lines = [];
        if ($result['unknown'] !== []) {
            $n       = count($result['unknown']);
            $lines[] = sprintf(_n('%1$d text is not in this source: %2$s', '%1$d texts are not in this source: %2$s', $n, 'translator'), $n, self::firstFive($result['unknown']));
        }
        if ($result['failed'] !== []) {
            $n       = count($result['failed']);
            $lines[] = sprintf(_n('%1$d text did not pass the check: %2$s', '%1$d texts did not pass the check: %2$s', $n, 'translator'), $n, self::firstFive(array_keys($result['failed'])));
        }

        return $lines;
    }

    private static function firstFive(array $originals): string
    {
        $shown = array_map(static fn ($o): string => '"' . (string) $o . '"', array_slice($originals, 0, 5));

        return implode(', ', $shown) . (count($originals) > 5 ? ', …' : '');
    }

    /** Sends the owner's .po, or with `export` set the full translation of one source or a zip of every source. */
    public static function download(array $state, CustomStore $store, ?Sources $sources = null): void
    {
        $export = Params::getParamEnum('export', ['full', 'zip'], '');
        if ($export !== '') {
            self::export($state, $store, $export, $sources);

            return;
        }
        $locale = $state['locale'];
        $source = $state['source'];
        $path   = $store->poPath($locale, $source);
        if (!is_file($path)) {
            osc_add_flash_warning_message(osc_esc_html(__('No changed texts for this language and source yet.', 'translator')), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        self::deliver($path, self::fileName($locale, $source->name()), 'text/x-gettext-translation', false);
    }

    private static function export(array $state, CustomStore $store, string $what, ?Sources $sources): void
    {
        $locale = $state['locale'];
        $path   = '';
        if ($what === 'zip' && !Export::zipAvailable()) {
            osc_add_flash_error_message(osc_esc_html(__('This server cannot make zip files.', 'translator')), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        try {
            if ($what === 'zip') {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(120);
                }
                [$path] = Export::zip($sources ?? Plugin::sources(), $locale, $store);
                $name   = Export::zipName($locale);
                $type   = 'application/zip';
            } else {
                $path = (string) tempnam(sys_get_temp_dir(), 'trp');
                if ($path === '' || file_put_contents($path, Export::po($state['source'], $locale, $store)) === false) {
                    throw new \RuntimeException(__('Could not write the export.', 'translator'));
                }
                $name = Export::fileName($locale, $state['source']->name());
                $type = 'text/x-gettext-translation';
            }
        } catch (Throwable $e) {
            if ($path !== '') {
                @unlink($path);
            }
            $why = $e instanceof \RuntimeException ? ' ' . $e->getMessage() : '';
            osc_add_flash_error_message(osc_esc_html(__('Could not make the export.', 'translator') . $why), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        if ($path === '') {
            osc_add_flash_warning_message(osc_esc_html(__('No texts to export for this language.', 'translator')), 'admin');
            osc_redirect_to(Screen::url($state));

            return;
        }
        self::deliver($path, $name, $type, true);
    }

    private static function deliver(string $path, string $name, string $type, bool $temp): void
    {
        if (self::$send !== null) {
            try {
                (self::$send)($path, $name);
            } finally {
                $temp && @unlink($path);
            }

            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        foreach (self::downloadHeaders($name, (int) filesize($path), $type) as $header) {
            header($header);
        }
        if ($temp) {
            register_shutdown_function(static function () use ($path): void {
                @unlink($path);
            });
        }
        readfile($path);
        exit;
    }

    public static function fileName(string $locale, string $source): string
    {
        return 'translator-' . Export::cleanName($locale) . '-' . Export::cleanName($source) . '.po';
    }

    /** @return string[] */
    public static function downloadHeaders(string $name, int $size, string $type = 'text/x-gettext-translation'): array
    {
        return [
            'Content-Type: ' . $type . ($type === 'application/zip' ? '' : '; charset=UTF-8'),
            'Content-Disposition: attachment; filename="' . $name . '"',
            'Content-Length: ' . $size,
            'Cache-Control: no-store, private',
            'X-Content-Type-Options: nosniff',
        ];
    }

    private static function writeError(): string
    {
        return osc_esc_html(__('Could not save. Check that oc-content/plugin-data/translator/ can be written.', 'translator'));
    }

    /** @param string[] $lines */
    private static function escList(array $lines): string
    {
        return implode('<br>', array_map(static fn ($l): string => osc_esc_html((string) $l), $lines));
    }
}

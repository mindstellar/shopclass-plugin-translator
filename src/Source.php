<?php
/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\translator;

final class Source
{
    private string $name;
    private string $kind;
    private string $domain;
    private string $label;
    private ?string $pot;
    private string $poDir;
    private string $poFile;

    public function __construct(
        string $name,
        string $kind,
        string $domain,
        string $label,
        ?string $pot,
        string $poDir,
        string $poFile
    ) {
        $this->name   = $name;
        $this->kind   = $kind;
        $this->domain = $domain;
        $this->label  = $label;
        $this->pot    = $pot;
        $this->poDir  = $poDir;
        $this->poFile = $poFile;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function potPath(): ?string
    {
        return $this->pot;
    }

    public function shippedPoPath(string $locale): ?string
    {
        if (!Locales::isCode($locale)) {
            return null;
        }
        $file = $this->poDir . $locale . '/' . $this->poFile;

        return is_file($file) ? $file : null;
    }

    public function originalsPath(string $locale): ?string
    {
        return $this->pot ?? $this->shippedPoPath($locale) ?? $this->shippedPoPath('en_US');
    }
}

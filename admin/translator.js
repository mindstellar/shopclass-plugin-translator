/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
(function () {
    'use strict';

    var PLACEHOLDER = /%(?:\d+\$)?[bcdeEfFgGosuxX]|\{[A-Za-z_][A-Za-z0-9_]*\}/g;
    var TAG = /<\/?[a-zA-Z][^>]*>/g;
    var UNSAFE = /<\s*script|javascript\s*:|\bon[a-z]+\s*=/i;

    function placeholders(s) {
        return (s.replace(/%%/g, '').match(PLACEHOLDER) || []).sort();
    }

    function subtract(a, b) {
        var left = a.slice();
        b.forEach(function (item) {
            var i = left.indexOf(item);
            if (i !== -1) {
                left.splice(i, 1);
            }
        });
        return left;
    }

    function normaliseTag(tag) {
        tag = tag.replace(/\s+/g, ' ').trim();
        tag = tag.replace(/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)/, function (m, slash, name) {
            return '<' + slash + name.toLowerCase();
        });
        return tag.replace(/([^\s=\/<>"']+)(\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]*))?/g, function (m, name, value) {
            return m.charAt(0) === '<' ? m : name.toLowerCase() + (value || '');
        });
    }

    function tags(s) {
        return (s.match(TAG) || []).map(normaliseTag).sort();
    }

    function trCheck(original, text, messages) {
        if (text.trim() === '') {
            return [messages.empty];
        }
        var problems = [];
        var want = placeholders(original);
        var have = placeholders(text);

        subtract(want, have).forEach(function (p) {
            problems.push(messages.keep.replace('%s', function () { return p; }));
        });
        subtract(have, want).forEach(function (p) {
            problems.push(messages.extra.replace('%s', function () { return p; }));
        });
        if (tags(original).join('\n') !== tags(text).join('\n')) {
            problems.push(messages.tags);
        }
        if (UNSAFE.test(text)) {
            problems.push(messages.unsafe);
        }
        return problems;
    }

    window.trCheck = trCheck;

    function init() {
        var drawer = document.getElementById('trDrawer');
        var backdrop = document.getElementById('trBackdrop');
        if (!drawer || !backdrop) {
            return;
        }
        var form = document.getElementById('trForm');
        var forms = document.getElementById('trForms');
        var orig = document.getElementById('trOrig');
        var newOrig = document.getElementById('trNewOrig');
        var newOrigText = document.getElementById('trNewOrigText');
        var check = document.getElementById('trCheck');
        var save = document.getElementById('trSave');
        var reset = document.getElementById('trReset');
        var title = document.getElementById('trTitle');
        var src = document.getElementById('trSrc');
        var ctx = form.elements.context;
        var origField = form.elements.original;
        var d = drawer.dataset;
        var messages = {
            empty: d.msgEmpty || 'Enter a text',
            keep: d.msgKeep || 'Your text must keep %s',
            extra: d.msgExtra || 'Your text has a placeholder the original does not have: %s',
            tags: d.msgTags || 'Your text must keep the same HTML tags as the original',
            unsafe: d.msgUnsafe || 'Your text cannot contain scripts or event handlers'
        };
        var label = {
            ok: d.msgOk || 'Looks good.',
            title: d.msgTitle || 'Edit text',
            original: d.msgOriginal || 'Original text',
            plural: d.msgPlural || 'Plural',
            yours: d.msgYours || 'Your text',
            singular: d.msgSingular || 'Singular',
            form: d.msgForm || 'Plural form %s'
        };
        var current = null;
        var ui = typeof window.oscDrawer === 'function'
            ? window.oscDrawer({ drawer: drawer, backdrop: backdrop, openClass: 'is-open', empty: false })
            : null;

        function esc(s) {
            return typeof window.oscEscapeHTML === 'function' ? window.oscEscapeHTML(s) :
                s.replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
        }

        function list(raw) {
            if (!raw) {
                return [];
            }
            try {
                var v = JSON.parse(raw);
                return Array.isArray(v) ? v.map(String) : [];
            } catch (e) {
                return [];
            }
        }

        function runCheck() {
            var areas = forms.querySelectorAll('textarea');
            var lines = [];
            areas.forEach(function (area, i) {
                var against = i === 0 ? current.original : (current.plural || current.original);
                trCheck(against, area.value, messages).forEach(function (m) {
                    lines.push(areas.length > 1 && i > 0 ? label.form.replace('%s', i + 1) + ': ' + m : m);
                });
            });
            if (lines.length) {
                check.className = 'tr-warn';
                check.innerHTML = lines.map(esc).join('<br>');
            } else {
                check.className = 'tr-ok';
                check.textContent = label.ok;
            }
            save.disabled = lines.length > 0;
        }

        function fieldLabel(i, n) {
            if (n === 1) {
                return label.yours;
            }
            if (i === 0) {
                return label.singular;
            }
            return n === 2 ? label.plural : label.form.replace('%s', i + 1);
        }

        function open(row) {
            var text = list(row.dataset.text);
            var shipped = list(row.dataset.shipped);
            var values = text.length ? text : shipped;
            var plural = row.dataset.plural || '';
            var n = Math.max(values.length, 1);
            current = { original: row.dataset.original || '', plural: plural };

            ctx.value = row.dataset.context || '';
            origField.value = current.original;
            title.textContent = label.title;
            src.textContent = d.src || '';
            orig.textContent = '';
            var first = document.createElement('div');
            first.textContent = current.original;
            orig.appendChild(first);
            if (plural) {
                var second = document.createElement('div');
                second.textContent = label.plural + ': ' + plural;
                orig.appendChild(second);
            }

            if (newOrig && newOrigText) {
                var now = list(row.dataset.newer);
                newOrigText.textContent = '';
                now.forEach(function (form) {
                    var line = document.createElement('div');
                    line.textContent = form;
                    newOrigText.appendChild(line);
                });
                newOrig.hidden = now.length === 0;
            }

            forms.textContent = '';
            for (var i = 0; i < n; i++) {
                var id = 'trText' + i;
                var wrap = document.createElement('div');
                wrap.className = 'mb-2';
                var lab = document.createElement('label');
                lab.className = 'tr-label';
                lab.htmlFor = id;
                lab.textContent = fieldLabel(i, n);
                var area = document.createElement('textarea');
                area.id = id;
                area.name = 'text[]';
                area.className = 'form-control';
                area.rows = 3;
                area.value = values[i] || '';
                area.addEventListener('input', runCheck);
                wrap.appendChild(lab);
                wrap.appendChild(area);
                forms.appendChild(wrap);
            }
            reset.hidden = text.length === 0;
            runCheck();
            if (ui) {
                ui.open(row);
            } else {
                drawer.classList.add('is-open');
                backdrop.classList.add('is-open');
            }
            var focus = forms.querySelector('textarea');
            if (focus) {
                focus.focus();
            }
        }

        function close() {
            if (ui) {
                ui.close(true);
            } else {
                drawer.classList.remove('is-open');
                backdrop.classList.remove('is-open');
            }
        }

        var rows = document.querySelectorAll('tr.tr-row[data-original]');
        rows.forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target && e.target.closest && e.target.closest('form')) {
                    return;
                }
                open(row);
            });
            row.querySelectorAll('button.tr-edit').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    open(btn.closest('tr.tr-row') || row);
                });
            });
            row.addEventListener('keydown', function (e) {
                if ((e.key === 'Enter' || e.key === ' ') && e.target === row) {
                    e.preventDefault();
                    open(row);
                }
            });
        });
        ['trClose', 'trCancel'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', close);
            }
        });

        var controls = document.getElementById('trControls');
        if (controls) {
            controls.querySelectorAll('select, input[name="filter"]').forEach(function (el) {
                el.addEventListener('change', function () { controls.submit(); });
            });
        }
    }

    function noDialogOpen() {
        return !document.querySelector('dialog[open]');
    }

    function initIo() {
        var drawer = document.getElementById('trIo');
        var backdrop = document.getElementById('trIoBackdrop');
        var opener = document.getElementById('trIoOpen');
        if (!drawer || !backdrop || !opener) {
            return;
        }
        var ui = typeof window.oscDrawer === 'function'
            ? window.oscDrawer({
                drawer: drawer,
                backdrop: backdrop,
                empty: false,
                canClose: noDialogOpen,
                focusFirst: function (d) {
                    var first = d.querySelector('.osc-drawer-close');
                    if (first) {
                        first.focus();
                    }
                }
            })
            : null;
        opener.setAttribute('aria-expanded', 'false');
        opener.addEventListener('click', function () {
            if (ui) {
                ui.open(opener);
            } else {
                drawer.hidden = false;
                backdrop.hidden = false;
                drawer.classList.add('is-open');
                backdrop.classList.add('is-open');
            }
            opener.setAttribute('aria-expanded', 'true');
        });
        drawer.querySelectorAll('[data-tr-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (ui) {
                    ui.close();
                } else {
                    drawer.classList.remove('is-open');
                    backdrop.classList.remove('is-open');
                    drawer.hidden = true;
                    backdrop.hidden = true;
                    opener.focus();
                }
            });
        });
        new MutationObserver(function () {
            opener.setAttribute('aria-expanded', drawer.hidden ? 'false' : 'true');
        }).observe(drawer, { attributes: true, attributeFilter: ['hidden'] });
    }

    function busy(el) {
        if (!el || el.getAttribute('aria-busy') === 'true') {
            return;
        }
        el.setAttribute('aria-busy', 'true');
        el.classList.add('tr-busy');
        var spin = document.createElement('span');
        spin.className = 'spinner-border spinner-border-sm tr-spin';
        spin.setAttribute('aria-hidden', 'true');
        el.insertBefore(spin, el.firstChild);
    }

    function idle(el) {
        el.removeAttribute('aria-busy');
        el.classList.remove('tr-busy');
        el.disabled = false;
        var spin = el.querySelector('.tr-spin');
        if (spin) {
            spin.remove();
        }
    }

    // A post shows a spinner and blocks a second click; the button is disabled after the
    // form data is built, so its name and value are still sent.
    function initBusy() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (e.defaultPrevented || !form.closest || !form.closest('.tr-page') || (form.method || '').toLowerCase() !== 'post') {
                return;
            }
            var by = e.submitter || form.querySelector('button[type="submit"]');
            busy(by);
            window.setTimeout(function () {
                form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
                if (by && by.form === form) {
                    by.disabled = true;
                }
            }, 0);
        });
        document.querySelectorAll('.tr-io .tr-file-actions a').forEach(function (a) {
            a.addEventListener('click', function () {
                busy(a);
                window.setTimeout(function () { idle(a); }, 4000);
            });
        });
        if (typeof window.addEventListener === 'function') {
            window.addEventListener('pageshow', function (e) {
                if (e.persisted) {
                    document.querySelectorAll('.tr-busy').forEach(idle);
                }
            });
        }
    }

    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init();
            initIo();
            initBusy();
        });
    }
})();

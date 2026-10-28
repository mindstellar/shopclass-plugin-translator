/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const file = new URL('../admin/translator.js', import.meta.url);
const ctx = { window: {}, document: { addEventListener() {} } };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(file, 'utf8'), ctx);
const trCheck = ctx.window.trCheck;

const msg = {
    empty: 'Enter a text',
    keep: 'Your text must keep %s',
    extra: 'Your text has a placeholder the original does not have: %s',
    tags: 'Your text must keep the same HTML tags as the original',
    unsafe: 'Your text cannot contain scripts or event handlers',
};
const keep = (p) => 'Your text must keep ' + p;
const extra = (p) => 'Your text has a placeholder the original does not have: ' + p;
const html = msg.tags;
const bad = msg.unsafe;

let total = 0;
let failed = 0;
function pin(label, expected, actual) {
    total++;
    try {
        assert.deepEqual(Array.from(actual), expected);
    } catch (e) {
        failed++;
        console.log('FAIL ' + label + ': expected ' + JSON.stringify(expected) + ' got ' + JSON.stringify(Array.from(actual)));
    }
}
const c = (o, t) => trCheck(o, t, msg);

pin('trCheck is exposed', ['function'], [typeof trCheck]);
pin('renamed words ok', [], c('%s listings found', '%s ads found'));
pin('dropped %s', [keep('%s')], c('%s listings found', 'listings found'));
pin('%s to %d', [keep('%s'), extra('%d')], c('%s listings found', '%d listings found'));
pin('swapped positional ok', [], c('%1$s in %2$s', '%2$s mein %1$s'));
pin('%% ignored', [], c('100%% sure', '100 sure'));
pin('%%d is not a placeholder', [], c('100%%d done', '100 done'));
pin('%%s in the text is not extra', [], c('done', 'done 100%%s'));
pin('lone % ignored', [], c('50% off', '50% chhoot'));
pin('{name} missing', [keep('{name}')], c('Hello {name}', 'Hello'));
pin('repeated placeholder needs both', [keep('%s')], c('%s and %s', '%s and x'));
pin('href changed', [html], c('Read <a href="%s">rules</a>', 'Read <a href="/x/%s">rules</a>'));
pin('attribute value case matters', [html], c('<a href="/Rules">r</a>', '<a href="/rules">r</a>'));
pin('tags reordered ok', [], c('<b>x</b> <i>y</i>', '<i>y</i> <b>x</b>'));
pin('tag name case ok', [], c('<a href="%s">r</a>', '<A HREF="%s">r</A>'));
pin('extra br', [html], c('a b', 'a<br>b'));
pin('tags on plain original', [html], c('plain', '<b>plain</b>'));
pin('script refused', [bad], c('<script>x</script>', '<script>y</script>'));
pin('onclick refused', [bad], c('<b onclick="a">x</b>', '<b onclick="a">y</b>'));
pin('javascript: refused', [bad], c('<a href="javascript:a">x</a>', '<a href="javascript:a">y</a>'));
pin('upper-case SCRIPT refused', [bad], c('<SCRIPT>x</SCRIPT>', '<SCRIPT>y</SCRIPT>'));
pin('upper-case ONCLICK refused', [bad], c('<b ONCLICK="a">x</b>', '<b ONCLICK="a">y</b>'));
pin('upper-case JAVASCRIPT: refused', [bad], c('<a href="JAVASCRIPT:a">x</a>', '<a href="JAVASCRIPT:a">y</a>'));
pin('extra spaces inside a tag ok', [], c('<a href="%s">r</a>', '<a   href="%s">r</a>'));
pin('newline inside a tag ok', [], c('<a href="%s">r</a>', '<a\nhref="%s">r</a>'));
pin('plain "reason=" is fine', [], c('reason', 'reason=none'));
pin('empty', ['Enter a text'], c('Hello', ''));
pin('blank', ['Enter a text'], c('Hello', '  \n '));

// Drawer wiring under a minimal fake DOM.
function el(id, extra) {
    const node = {
        id, dataset: {}, hidden: false, children: [], className: '', textContent: '', value: '',
        listeners: {}, rows: [], classList: { add() {}, remove() {} },
        addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); },
        appendChild(c) { this.children.push(c); },
        querySelectorAll() { return this.rows || []; },
        querySelector() { return null; },
        closest() { return null; },
        focus() {},
        ...extra,
    };
    Object.defineProperty(node, 'textContent', {
        get() { return this._t || ''; },
        set(v) { this._t = v; if (v === '') { this.children = []; } },
    });
    return node;
}
function boot(withBlock) {
    const ids = {};
    ['trDrawer', 'trBackdrop', 'trOrig', 'trCheck', 'trSave', 'trReset', 'trTitle', 'trSrc', 'trClose', 'trCancel']
        .forEach((i) => { ids[i] = el(i); });
    ids.trForms = el('trForms');
    ids.trForm = el('trForm', { elements: { context: { value: '' }, original: { value: '' } } });
    if (withBlock) {
        ids.trNewOrig = el('trNewOrig', { hidden: true });
        ids.trNewOrigText = el('trNewOrigText');
    }
    const row = el('row', { dataset: { original: 'Hi', text: '[]', shipped: '["Hi"]', newer: '["A","B"]' } });
    const form = el('f');
    const plain = el('p');
    const edit = el('edit', { closest: () => row });
    row.rows = [edit];
    const doc = {
        listeners: {},
        addEventListener(t, f) { this.listeners[t] = f; },
        getElementById: (i) => ids[i] || null,
        createElement: () => el('x'),
        querySelectorAll: (q) => (q.indexOf('tr.tr-row') === 0 ? [row] : []),
    };
    const c2 = { window: {}, document: doc };
    vm.createContext(c2);
    vm.runInContext(fs.readFileSync(file, 'utf8'), c2);
    doc.listeners.DOMContentLoaded();
    return { ids, row, form, plain, edit };
}
{
    const t = boot(true);
    const click = t.row.listeners.click[0];
    click({ target: { closest: (s) => (s === 'form' ? {} : null) } });
    pin('click inside a form leaves the drawer shut', [true], [t.ids.trNewOrig.hidden]);
    click({ target: { closest: () => null } });
    pin('row click shows the new original', [false], [t.ids.trNewOrig.hidden]);
    pin('one line per form', [2], [t.ids.trNewOrigText.children.length]);
    pin('line text', ['A', 'B'], t.ids.trNewOrigText.children.map((n) => n.textContent));
    t.ids.trNewOrig.hidden = true;
    let stopped = false;
    t.edit.listeners.click[0]({ stopPropagation() { stopped = true; } });
    pin('Edit button opens the drawer', [false], [t.ids.trNewOrig.hidden]);
    pin('Edit button stops the click', [true], [stopped]);
    t.row.dataset.newer = undefined;
    click({ target: { closest: () => null } });
    pin('no newer data hides the block', [true], [t.ids.trNewOrig.hidden]);
    t.row.dataset.newer = '[]';
    t.ids.trNewOrig.hidden = false;
    click({ target: { closest: () => null } });
    pin('empty list hides the block', [true], [t.ids.trNewOrig.hidden]);
}
{
    let threw = false;
    try {
        const t = boot(false);
        t.row.listeners.click[0]({ target: { closest: () => null } });
    } catch (e) {
        threw = true;
    }
    pin('page without the block never throws', [false], [threw]);
}

console.log(failed ? `${failed} of ${total} checks FAILED` : `${total} checks passed`);
process.exit(failed ? 1 : 0);

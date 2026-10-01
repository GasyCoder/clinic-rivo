import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync('resources/js/Pages/Administration/StaffBlockCredits/Index.vue', 'utf8');

test('the staff block credit workspace uses the shared shadcn primitives', () => {
    for (const component of ['Avatar', 'Badge', 'Button', 'Card', 'Dialog', 'FormField', 'IconInput', 'Input', 'Textarea']) {
        assert.match(source, new RegExp(`Components/Shadcn/${component}\\.vue`), `${component} doit venir de la couche Shadcn`);
    }

    assert.doesNotMatch(source, /Components\/UI\/(?:Button|Icon|Input|Avatar)\.vue/);
    assert.doesNotMatch(source, /\bni ni-|\bnk-|\b(?:bg|text|border)-(?:gray|slate)-\d/);
});

test('employee selection and the immutable ledger stay readable and accessible', () => {
    assert.match(source, /:aria-pressed="selectedEmployee\?\.uuid === employee\.uuid"/);
    assert.match(source, /aria-label="Rechercher un employé"/);
    assert.match(source, /movement\.balance_before/);
    assert.match(source, /movement\.balance_after/);
    assert.match(source, /<caption class="sr-only">/);
    assert.match(source, /<HrPagination :paginator="employees" \/>/);
    assert.match(source, /<HrPagination :paginator="movements" \/>/);
});

test('an allocation is a permission-gated confirmation dialog with server validation', () => {
    assert.match(source, /can\('staff_block_credits\.allocate'\) && selectedEmployee\.active/);
    assert.match(source, /title="Allouer un crédit Bloc"/);
    assert.match(source, /allocationForm\.post\(hrUrl\(`/);
    assert.match(source, /allocationForm\.errors\.amount/);
    assert.match(source, /allocationForm\.errors\.reason/);
    assert.match(source, /idempotency_key: newIdempotencyKey\(\)/);
    assert.match(source, /ne pourra pas être modifiée ni supprimée/);
});

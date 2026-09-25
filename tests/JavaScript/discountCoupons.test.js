import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('an archived coupon offers a trash action the server decides on, after a confirmation', () => {
    const settings = read('resources/js/Components/Settings/DiscountSettings.vue');

    assert.match(settings, /<Trash2 class="h-4 w-4" aria-hidden="true" \/>/);
    assert.match(settings, /v-if="can\('discount_coupons\.force_delete'\)"/, 'son propre droit, pas celui d’archiver');
    assert.match(settings, /:disabled="Boolean\(coupon\.deletion_blocker\)"/, 'un coupon qui a servi reste : le serveur dit pourquoi');
    assert.match(settings, /:title="coupon\.deletion_blocker \|\| /, 'la raison se lit au survol');
    assert.match(settings, /<ConfirmModal[\s\S]*?tone="danger"[\s\S]*?@confirm="submitDelete"/, 'jamais sans confirmation');
    assert.match(settings, /\.delete\(`\/super-admin\/settings\/coupons\/\$\{deleteTarget\.value\.uuid\}`/);
});

<?php

namespace Tests\Feature\Api;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\DiscountCoupon;
use App\Models\PatientVipSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-192 — les coupons d'un site et ses remises VIP / personnel, réglés depuis le
 * portail par l'API du site : réautorisés ici, validés, attribués au Super
 * Administrateur, jamais supprimés.
 */
class DiscountCouponApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/super-admin/app-settings';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
    }

    public function test_a_coupon_is_created_with_the_central_actor_and_listed(): void
    {
        $actor = (string) Str::uuid();

        $this->withHeaders($this->headers(['discount_coupons.create'], $actor))
            ->postJson(self::URL.'/coupons', ['code' => ' rentree-2026 ', 'discount_type' => 'PERCENT', 'discount_value' => 15, 'max_uses' => 50])
            ->assertCreated()
            ->assertJsonPath('data.discounts.coupons.0.code', 'RENTREE-2026')
            ->assertJsonPath('data.discounts.coupons.0.describe', '15 %')
            ->assertJsonPath('data.discounts.coupons.0.max_uses', 50);

        $coupon = DiscountCoupon::sole();
        $this->assertSame($actor, $coupon->external_created_by_uuid);
        $this->assertNull($coupon->created_by);
        $this->assertTrue(AuditLog::query()->where('action', 'discount_coupon.create')->exists());
    }

    public function test_a_code_is_unique_forever_and_rules_are_enforced(): void
    {
        DiscountCoupon::create(['code' => 'VIEUX', 'discount_type' => 'AMOUNT', 'discount_value' => 1000, 'archived_at' => now(), 'archive_reason' => 'Fin']);

        $this->withHeaders($this->headers(['discount_coupons.create']))
            ->postJson(self::URL.'/coupons', ['code' => 'vieux', 'discount_type' => 'AMOUNT', 'discount_value' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->withHeaders($this->headers(['discount_coupons.create']))
            ->postJson(self::URL.'/coupons', ['code' => 'TROP', 'discount_type' => 'PERCENT', 'discount_value' => 101])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_value');

        $this->withHeaders($this->headers(['discount_coupons.create']))
            ->postJson(self::URL.'/coupons', ['code' => 'a b!', 'discount_type' => 'PERCENT', 'discount_value' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_creating_and_archiving_need_their_own_permission(): void
    {
        $this->withHeaders($this->headers(['settings.update']))
            ->postJson(self::URL.'/coupons', ['code' => 'X', 'discount_type' => 'PERCENT', 'discount_value' => 10])
            ->assertForbidden();

        $coupon = DiscountCoupon::create(['code' => 'ARCH', 'discount_type' => 'PERCENT', 'discount_value' => 10]);

        $this->withHeaders($this->headers(['discount_coupons.create']))
            ->postJson(self::URL."/coupons/{$coupon->uuid}/archive", ['reason' => 'Campagne terminée'])
            ->assertForbidden();

        $this->withHeaders($this->headers(['discount_coupons.archive']))
            ->postJson(self::URL."/coupons/{$coupon->uuid}/archive", ['reason' => 'Campagne terminée'])
            ->assertOk()
            ->assertJsonPath('data.discounts.coupons.0.archived', true);

        $this->assertSame('Campagne terminée', $coupon->refresh()->archive_reason);
        $this->assertSame('Ce coupon est archivé.', $coupon->unusableReason());
    }

    public function test_an_archived_coupon_that_never_served_is_deleted_for_good_and_its_code_freed(): void
    {
        $actor = (string) Str::uuid();
        $coupon = DiscountCoupon::create(['code' => 'TESTC26', 'discount_type' => 'PERCENT', 'discount_value' => '20', 'archived_at' => now(), 'archive_reason' => 'Essai']);

        $this->withHeaders($this->headers(['settings.view'], $actor))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.discounts.coupons.0.deletion_blocker', null);

        // Archiver n'est pas supprimer : son propre droit.
        $this->withHeaders($this->headers(['discount_coupons.archive']))
            ->deleteJson(self::URL."/coupons/{$coupon->uuid}")
            ->assertForbidden();

        $this->withHeaders($this->headers(['discount_coupons.force_delete'], $actor))
            ->deleteJson(self::URL."/coupons/{$coupon->uuid}")
            ->assertOk()
            ->assertJsonPath('message', 'Coupon TESTC26 supprimé définitivement.');

        $this->assertSame(0, DiscountCoupon::query()->count());
        $audit = AuditLog::query()->where('action', 'discount_coupon.force_delete')->sole();
        $this->assertSame($actor, $audit->external_actor_uuid);
        $this->assertSame('TESTC26', $audit->old_values['code']);

        // Il n'a jamais servi : son code redevient libre.
        $this->withHeaders($this->headers(['discount_coupons.create']))
            ->postJson(self::URL.'/coupons', ['code' => 'TESTC26', 'discount_type' => 'PERCENT', 'discount_value' => 10])
            ->assertCreated();
    }

    public function test_a_coupon_still_in_use_or_that_served_is_never_deleted(): void
    {
        $active = DiscountCoupon::create(['code' => 'ACTIF', 'discount_type' => 'PERCENT', 'discount_value' => '10']);
        $served = DiscountCoupon::create(['code' => 'SERVI', 'discount_type' => 'AMOUNT', 'discount_value' => '5000', 'uses_count' => 2, 'archived_at' => now()]);

        $this->withHeaders($this->headers(['discount_coupons.force_delete']))
            ->deleteJson(self::URL."/coupons/{$active->uuid}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['coupon' => 'Archivez d’abord ce coupon.']);

        $this->withHeaders($this->headers(['discount_coupons.force_delete']))
            ->deleteJson(self::URL."/coupons/{$served->uuid}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['coupon' => 'Ce coupon a servi sur 2 factures : il reste archivé, pour l’historique.']);

        $this->assertSame(2, DiscountCoupon::query()->count());
        $this->assertSame('Ce coupon a servi sur 2 factures : il reste archivé, pour l’historique.', $served->refresh()->deletionBlocker());
    }

    public function test_the_staff_discount_is_saved_with_the_other_settings_and_the_vip_one_is_not(): void
    {
        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->putJson(self::URL, [
                'currency_label' => 'Ar', 'currency_position' => 'after', 'currency_decimals' => 0,
                'baby_max_age' => 1, 'child_max_age' => 15,
                'staff_discount_type' => 'AMOUNT', 'staff_discount_value' => 5000,
                // La remise VIP se règle avec les seuils VIP (ADR-133) : ignorée ici.
                'discount_type' => 'PERCENT', 'discount_value' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('data.values.staff_discount_type', 'AMOUNT')
            ->assertJsonPath('data.values.staff_discount_value', '5000.00')
            ->assertJsonMissingPath('data.values.vip_discount_type');

        $this->assertSame('5000.00', (string) AppSetting::sole()->staff_discount_value);
        $this->assertSame(0, PatientVipSetting::query()->count());
    }

    /** @return array<string, string> */
    private function headers(array $permissions, ?string $actorUuid = null): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }
}

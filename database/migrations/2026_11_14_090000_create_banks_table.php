<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-221 — le référentiel des banques du site, et la banque du compte d'un employé.
 *
 * Une banque n'est plus un texte libre répété sur chaque fiche : « BOA »,
 * « Bank of Africa » et « BANK OF AFRICA » désignaient la même. Le module
 * Banques (RH) la crée une fois ; la fiche la choisit dans la liste.
 *
 * Aucune donnée n'est migrée : jusqu'ici aucune fiche ne portait de banque,
 * seulement un numéro de compte et son titulaire (ADR-206), qui restent.
 * Les quatre banques nommées par le propriétaire sont ajoutées si elles
 * manquent, sans code banque ni SWIFT : ils ne sont pas inventés.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const BANKS = [
        'BOA' => 'Bank of Africa Madagascar',
        'BNI' => 'BNI Madagascar',
        'BMOI' => 'Banque Malgache de l’Océan Indien',
        'SBM' => 'SBM Bank Madagascar',
    ];

    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Le nom court sous lequel tout le monde la connaît : BOA, BNI…
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            // Nom sans accents, casse ni espaces doublés : deux saisies d'une
            // même banque se reconnaissent (unicité vérifiée par l'application).
            $table->string('normalized_name', 150)->index();
            // Le code banque du RIB (5 chiffres à Madagascar) et le SWIFT/BIC.
            $table->string('bank_code', 10)->nullable();
            $table->string('swift_code', 11)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('bank_id')->nullable()->after('remuneration_amount')->constrained('banks')->restrictOnDelete();
        });

        $now = now();
        $position = 0;

        foreach (self::BANKS as $code => $name) {
            $normalized = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', ' ')->squish()->toString();
            $exists = DB::table('banks')->where('code', $code)->orWhere('normalized_name', $normalized)->exists();

            if (! $exists) {
                DB::table('banks')->insert([
                    'uuid' => (string) Str::uuid(),
                    'code' => $code,
                    'name' => $name,
                    'normalized_name' => $normalized,
                    'active' => true,
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $position++;
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_id');
        });

        Schema::dropIfExists('banks');
    }
};

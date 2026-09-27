<?php

use App\Support\Hr\DefaultJobTitleAccountRoles;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-199 — chaque fonction livrée propose le rôle, et s'il le faut le profil
 * métier, du compte de celui qui l'exerce (CDC §9, ADR-033, ADR-168). Seules
 * les fonctions sur lesquelles rien n'a été décidé sont réglées.
 */
return new class extends Migration
{
    public function up(): void
    {
        DefaultJobTitleAccountRoles::apply();
    }

    /**
     * Rien à défaire : une proposition réglée depuis par la clinique ne se
     * distingue plus de celle-ci, et l'effacer retirerait sa décision.
     */
    public function down(): void {}
};

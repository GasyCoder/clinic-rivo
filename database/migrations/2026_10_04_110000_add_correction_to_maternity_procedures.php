<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un acte Maternité enregistré se corrige ou se retire (ADR-140).
 *
 * `performed_by_role` est un **instantané** du rôle de l'auteur au moment de
 * l'enregistrement : la règle « l'acte d'un médecin n'est pas modifiable par
 * le personnel » ne doit pas changer parce que ce compte a changé de rôle
 * depuis. Les actes déjà enregistrés le reçoivent de leur auteur actuel — la
 * seule information disponible, et celle qui était vraie tant que rien n'a
 * bougé.
 *
 * Le retrait est un Soft Delete avec auteur et motif (ADR-009) : rien n'est
 * détruit, l'audit garde la trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maternity_procedures', function (Blueprint $table) {
            $table->string('performed_by_role', 40)->nullable()->after('performed_by');
            $table->foreignId('edited_by')->nullable()->after('performed_at')->constrained('users')->nullOnDelete();
            $table->dateTime('edited_at')->nullable()->after('edited_by');
            $table->softDeletesWithReason();
        });

        DB::table('maternity_procedures')->update([
            'performed_by_role' => DB::raw('(select r.code from users u join roles r on r.id = u.role_id where u.id = maternity_procedures.performed_by)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('maternity_procedures', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropForeign(['edited_by']);
            $table->dropColumn(['performed_by_role', 'edited_by', 'edited_at', 'deleted_at', 'deleted_by', 'delete_reason']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions du Promoteur, 23 octobre 2026 :
 *
 * - Rubrique Achat : toute depense de l'atelier, classee par categorie (consommables, materiel,
 *   entretien et reparation, energie, transport, autre), comptee dans les charges de la semaine. Les
 *   sorties de consommables deja enregistrees en sont la categorie « consommables » ;
 * - Rubrique avance sur commission : le chef d'atelier remet a un travailleur une partie de la
 *   commission qu'il a deja gagnee dans la semaine ; elle se deduit de ce qui lui reste a payer.
 *
 * Achats et avances sont payes avec les versements des boutiques recus a l'atelier, pas avec la
 * caisse du jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consommables', function (Blueprint $table) {
            $table->string('categorie', 20)->default('CONSOMMABLES');
        });

        Schema::create('avances_commission', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // l'atelier qui verse
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('travailleur_id')->constrained('travailleurs');
            $table->decimal('montant', 12, 2);
            $table->foreignUuid('versee_par')->constrained('utilisateurs');
            $table->dateTime('versee_le');
            $table->string('motif')->nullable();
            $table->timestamps();

            $table->index(['travailleur_id', 'versee_le']);
            $table->index(['site_id', 'versee_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avances_commission');
        Schema::table('consommables', function (Blueprint $table) {
            $table->dropColumn('categorie');
        });
    }
};

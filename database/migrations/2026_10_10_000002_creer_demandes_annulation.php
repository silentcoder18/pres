<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etape 3 - annulations : demandes d'annulation des commandes (cahier des charges v2, RG-24, §10).
 *
 * La demande vient du poste de la boutique, qui la cree hors ligne avec son identifiant ; la
 * decision est prise ici par le Promoteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_annulation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes');
            $table->foreignUuid('demandee_par')->constrained('utilisateurs');
            $table->string('motif', 500);
            $table->dateTime('demandee_le');
            $table->string('etat', 20); // DEMANDEE | CONFIRMEE | REFUSEE
            $table->foreignUuid('decidee_par')->nullable()->constrained('utilisateurs');
            $table->dateTime('decidee_le')->nullable();
            $table->string('motif_refus')->nullable();
            $table->timestamps();
            $table->index('commande_id');
            $table->index(['etat', 'demandee_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_annulation');
    }
};

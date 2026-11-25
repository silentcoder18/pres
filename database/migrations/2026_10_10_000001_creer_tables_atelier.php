<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etape 3 - atelier : travailleurs, controles des commandes recues, taches et sorties de
 * consommables (cahier des charges v2, §6.3 a §6.5, §10).
 *
 * Les identifiants viennent du poste de l'atelier, qui cree ces lignes hors ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        // RG-02 : le matricule est attribue par le poste, au format VP-[alphanumerique]-T
        Schema::create('travailleurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('matricule', 20)->unique();
            $table->string('nom', 150);
            // §11 : donnees d'identite, saisies a l'atelier et consultables ici seulement
            $table->string('cni', 50)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('quartier', 100)->nullable();
            $table->boolean('actif')->default(true);
            $table->foreignUuid('cree_par')->constrained('utilisateurs');
            $table->dateTime('enrole_le');
            $table->timestamps();
        });

        // RG-08, RG-09 : un controle par commande recue ; un ecart qui modifie la facture porte
        // la correction proposee, puis ce qu'elle est devenue
        Schema::create('controles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes');
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs');
            $table->string('resultat', 20); // CONFORME | OBSERVATION | ECART
            $table->string('remarque', 500)->nullable();
            $table->dateTime('controle_le');
            // la ligne peut disparaitre si la correction la ramene a zero : pas de cle etrangere
            $table->uuid('ligne_commande_id')->nullable();
            $table->foreignUuid('tarif_id')->nullable()->constrained('tarifs');
            $table->unsignedInteger('quantite_avant')->nullable();
            $table->unsignedInteger('quantite_apres')->nullable();
            $table->string('etat', 25)->nullable(); // PROPOSEE | ACCEPTEE | APPLIQUEE_APRES_DELAI
            $table->dateTime('decide_le')->nullable();
            $table->foreignUuid('decide_par')->nullable()->constrained('utilisateurs');
            $table->decimal('montant_avant', 12, 2)->nullable();
            $table->decimal('montant_apres', 12, 2)->nullable();
            $table->timestamps();
            $table->index('commande_id');
            $table->index(['etat', 'controle_le']);
        });

        // RG-20 : la somme des quantites attribuees sur une ligne ne depasse pas celle de la ligne
        Schema::create('taches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes');
            $table->uuid('ligne_commande_id');
            $table->foreignUuid('travailleur_id')->constrained('travailleurs');
            $table->unsignedInteger('quantite');
            // RG-03 : commission de la ligne, copiee a l'attribution
            $table->decimal('commission_unitaire', 12, 2);
            $table->foreignUuid('attribuee_par')->constrained('utilisateurs');
            $table->dateTime('attribuee_le');
            // RG-12 : la commission est rattachee a la semaine ou la tache a ete terminee
            $table->dateTime('terminee_le')->nullable();
            $table->timestamps();
            $table->index('commande_id');
            $table->index('ligne_commande_id');
            $table->index(['travailleur_id', 'terminee_le']);
        });

        // §7 : une sortie de consommables est enregistree avec sa date, sa description et son montant
        Schema::create('consommables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs');
            $table->string('description');
            $table->decimal('montant', 12, 2);
            $table->dateTime('sortie_le');
            $table->timestamps();
            $table->index(['site_id', 'sortie_le']);
        });

        Schema::table('commandes', function (Blueprint $table) {
            // RG-06 : le delai de retrait court a partir du passage au statut Traitee
            $table->dateTime('traitee_le')->nullable();
            $table->dateTime('livree_le')->nullable();
            $table->uuid('livree_par')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn(['traitee_le', 'livree_le', 'livree_par']);
        });
        Schema::dropIfExists('consommables');
        Schema::dropIfExists('taches');
        Schema::dropIfExists('controles');
        Schema::dropIfExists('travailleurs');
    }
};

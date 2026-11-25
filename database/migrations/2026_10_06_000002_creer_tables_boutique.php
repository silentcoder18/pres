<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etape 2 - boutique : sessions de caisse, commandes et leurs lignes, paiements, lots et
 * file des messages WhatsApp (cahier des charges v2, §10).
 *
 * Les identifiants viennent des postes, qui creent ces lignes hors ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        // RG-18 : une seule session ouverte a la fois par site
        Schema::create('sessions_caisse', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('ouverte_par')->constrained('utilisateurs');
            $table->dateTime('ouverte_le');
            $table->decimal('fond_initial', 12, 2);
            $table->foreignUuid('cloturee_par')->nullable()->constrained('utilisateurs');
            $table->dateTime('cloturee_le')->nullable();
            $table->decimal('total_attendu', 12, 2)->nullable();
            $table->decimal('total_compte', 12, 2)->nullable();
            $table->decimal('ecart', 12, 2)->nullable();
            $table->string('observations')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'cloturee_le']);
        });

        Schema::create('commandes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique(); // RG-01 : identifiant de retrait
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('client_id')->constrained('clients');
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs');
            $table->string('statut', 25); // RG-05
            $table->dateTime('deposee_le');
            // §8 : accord du client, recueilli au depot, pour recevoir ses messages
            $table->boolean('accord_messages')->default(true);
            // RG-04 : toujours calcules par le serveur a partir des lignes et des paiements
            $table->decimal('montant', 12, 2)->default(0);
            $table->decimal('total_paye', 12, 2)->default(0);
            $table->decimal('reste', 12, 2)->default(0);
            $table->timestamps();
            $table->index(['site_id', 'statut']);
        });

        // RG-03 : prix et commission copies du catalogue du poste au moment de la creation de la ligne
        Schema::create('lignes_commande', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignUuid('tarif_id')->constrained('tarifs');
            $table->unsignedInteger('quantite');
            $table->decimal('prix_applique', 12, 2);
            $table->decimal('commission_appliquee', 12, 2);
            $table->timestamps();
        });

        // RG-17 : especes uniquement, toujours rattache a une session de caisse
        Schema::create('paiements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes');
            $table->foreignUuid('session_caisse_id')->constrained('sessions_caisse');
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs');
            $table->string('type', 20); // ACOMPTE | SOLDE | REMBOURSEMENT
            $table->decimal('montant', 12, 2);
            $table->dateTime('encaisse_le');
            // RG-14 : decidee par le serveur, jamais par le poste
            $table->string('affectation', 30)->nullable();
            $table->timestamps();
            $table->index('commande_id');
        });

        // RG-19 : lot aller (boutique vers atelier) ou retour (atelier vers boutique)
        Schema::create('lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sens', 10);
            $table->foreignUuid('site_origine_id')->constrained('sites');
            $table->foreignUuid('site_destination_id')->constrained('sites');
            $table->foreignUuid('cloture_par')->constrained('utilisateurs');
            $table->dateTime('cloture_le');
            $table->unsignedInteger('nombre_annonce');
            $table->foreignUuid('recu_par')->nullable()->constrained('utilisateurs');
            $table->dateTime('recu_le')->nullable();
            $table->unsignedInteger('nombre_recu')->nullable();
            $table->timestamps();
            $table->index(['site_destination_id', 'recu_le']);
        });

        Schema::create('lot_commandes', function (Blueprint $table) {
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->foreignUuid('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->primary(['lot_id', 'commande_id']);
        });

        // §8 : message cree sur le poste au moment de l'evenement, envoye par le serveur
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('client_id')->constrained('clients');
            $table->foreignUuid('commande_id')->nullable()->constrained('commandes');
            $table->string('declencheur', 40);
            $table->text('contenu');
            $table->string('etat', 20)->default('EN_ATTENTE'); // EN_ATTENTE | ENVOYE | ECHOUE
            $table->unsignedInteger('tentatives')->default(0);
            $table->dateTime('cree_le');
            $table->dateTime('envoye_le')->nullable();
            $table->timestamps();
            $table->index('etat');
        });

        // RG-21 : fiche creee hors ligne avec un numero deja connu ; elle est reunie a la fiche existante
        Schema::create('clients_reunis', function (Blueprint $table) {
            $table->uuid('id')->primary(); // identifiant de la fiche en double, qui disparait
            $table->foreignUuid('client_id')->constrained('clients'); // fiche conservee
            $table->foreignUuid('site_id')->constrained('sites'); // site qui avait cree le doublon
            $table->string('nom_propose', 150);
            $table->string('quartier_propose', 100)->nullable();
            // §4.4 : meme numero mais noms differents, le Promoteur tranche
            $table->boolean('a_trancher')->default(false);
            $table->dateTime('tranche_le')->nullable();
            $table->timestamps();
            $table->index('a_trancher');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients_reunis');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('lot_commandes');
        Schema::dropIfExists('lots');
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('lignes_commande');
        Schema::dropIfExists('commandes');
        Schema::dropIfExists('sessions_caisse');
    }
};

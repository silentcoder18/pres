<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Socle (cahier des charges v2, etape 1) : sites, postes, comptes,
     * donnees de reference, clients et tables de synchronisation.
     * Tous les enregistrements portent un identifiant universel (§10).
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('nom', 150);
            $table->string('adresse')->nullable();
            $table->string('type', 20); // boutique | atelier
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('postes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('nom', 100);
            // Code de mise en service a usage unique, et jeton du poste : seules leurs empreintes sont gardees
            $table->string('code_enrolement_hash', 64)->nullable()->unique();
            $table->dateTime('code_expire_le')->nullable();
            $table->string('jeton_hash', 64)->nullable()->unique();
            $table->string('nom_machine', 150)->nullable();
            $table->dateTime('enrole_le')->nullable();
            $table->dateTime('dernier_contact_le')->nullable();
            $table->dateTime('revoque_le')->nullable();
            $table->timestamps();
        });

        Schema::create('utilisateurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 150);
            $table->string('identifiant', 150)->unique();
            $table->string('mot_de_passe');
            $table->string('role', 20); // PROMOTEUR | CHEF_ATELIER | RECEPTIONNISTE
            $table->foreignUuid('site_id')->nullable()->constrained('sites');
            $table->string('telephone', 30)->nullable();
            $table->boolean('actif')->default(true);
            // Augmente a chaque changement de mot de passe ou d'acces : le poste redemande alors une connexion en ligne
            $table->unsignedInteger('version_acces')->default(1);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 100);
            $table->string('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 100);
            $table->string('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('tarifs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles');
            $table->foreignUuid('service_id')->constrained('services');
            $table->decimal('prix_vente', 12, 2);
            $table->decimal('commission_fixe', 12, 2);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['article_id', 'service_id']);
        });

        Schema::create('parametres', function (Blueprint $table) {
            $table->string('cle', 60)->primary();
            $table->string('valeur')->nullable();
            $table->string('libelle');
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 150);
            $table->string('telephone', 30)->unique(); // RG-21
            $table->string('quartier', 100)->nullable();
            $table->foreignUuid('site_origine_id')->nullable()->constrained('sites');
            $table->timestamps();
        });

        // Operations recues des postes : l'identifiant rend un envoi rejouable sans doublon (§4.3, principe 6)
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('poste_id')->constrained('postes');
            $table->string('table_cible', 60);
            $table->string('ligne_id', 64);
            $table->string('resultat', 20);
            $table->string('motif')->nullable();
            $table->dateTime('recu_le');
        });

        // Fil des changements que les postes viennent chercher, dans l'ordre de "seq"
        Schema::create('sync_changements', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('table_cible', 60);
            $table->string('ligne_id', 64);
            $table->string('operation', 20); // upsert | suppression
            $table->json('donnees')->nullable();
            $table->uuid('site_id')->nullable()->index(); // null : tous les sites
            $table->boolean('urgent')->default(false);
            $table->uuid('origine_poste_id')->nullable();
            $table->dateTime('cree_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changements');
        Schema::dropIfExists('sync_operations');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('parametres');
        Schema::dropIfExists('tarifs');
        Schema::dropIfExists('services');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('utilisateurs');
        Schema::dropIfExists('postes');
        Schema::dropIfExists('sites');
    }
};

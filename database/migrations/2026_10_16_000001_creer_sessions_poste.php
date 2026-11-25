<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Postes et personnes (decision du Promoteur, 16 octobre 2026) : la mise en service authentifie
 * l'ordinateur ; chaque personne qui s'y connecte a sa propre base locale, synchronisee sous sa
 * propre session, sur les donnees de son site de rattachement.
 *
 * Une session de poste lie un poste, un compte et le site sur lequel ce compte travaille. Son
 * jeton est remis au poste a la verification en ligne du compte ; le poste l'envoie avec le
 * jeton du poste a chaque synchronisation de la base de cette personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions_poste', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('poste_id')->constrained('postes');
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs');
            // site de travail : le site de rattachement du compte a l'ouverture de la session
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('jeton_hash', 64)->unique();
            // un mot de passe change ou un compte desactive fait monter la version : la session tombe
            $table->unsignedInteger('version_acces');
            $table->dateTime('ouverte_le');
            $table->dateTime('dernier_contact_le')->nullable();
            $table->dateTime('fermee_le')->nullable();
            $table->index(['poste_id', 'utilisateur_id']);
        });

        Schema::table('sync_changements', function (Blueprint $table) {
            // un changement fait depuis la base d'une personne ne lui est pas renvoye ; il l'est aux
            // autres personnes du meme poste
            $table->uuid('origine_session_id')->nullable();
        });

        Schema::table('sync_operations', function (Blueprint $table) {
            $table->uuid('session_poste_id')->nullable();
        });

        // Un poste longtemps sans contact ne se verrouille plus : il avertit, et continue de travailler
        DB::table('parametres')->where('cle', 'duree_max_hors_ligne_heures')
            ->update(['libelle' => "Durée sans contact avec le serveur avant qu'un poste n'avertisse (heures)"]);
    }

    public function down(): void
    {
        Schema::table('sync_operations', function (Blueprint $table) {
            $table->dropColumn('session_poste_id');
        });
        Schema::table('sync_changements', function (Blueprint $table) {
            $table->dropColumn('origine_session_id');
        });
        Schema::dropIfExists('sessions_poste');
    }
};

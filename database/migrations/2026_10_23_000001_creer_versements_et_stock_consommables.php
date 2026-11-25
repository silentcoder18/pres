<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ameliorations du 23 octobre 2026 (docs/suivi-ameliorations.md), pour qu'elles passent par le
 * serveur comme le reste du travail des sites :
 *
 * - la remarque d'une ligne de commande (tache, accroc, bouton manquant...) notee au depot, qui
 *   doit suivre la commande jusqu'a l'atelier ;
 * - le versement journalier d'une boutique : declare a la cloture de sa caisse, puis recu a
 *   l'atelier ; la reception s'ajoute au versement, elle ne le modifie pas ;
 * - le stock de consommables de l'atelier : des articles et des mouvements (entrees et sorties),
 *   le stock etant la somme des mouvements. Plusieurs personnes d'un meme atelier, chacune dans sa
 *   base, voient ainsi le meme stock une fois synchronisees.
 *
 * Les identifiants viennent des postes. Ceux des articles de depart sont calcules a partir du site
 * et du code de l'article : deux bases du meme atelier creent le meme article.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->string('remarque', 500)->nullable();
        });

        Schema::create('versements_journaliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // la boutique qui remet ses especes
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('session_caisse_id')->unique()->constrained('sessions_caisse');
            $table->date('date_versement');
            $table->decimal('montant_declare', 12, 2);
            $table->foreignUuid('remis_par')->constrained('utilisateurs');
            $table->dateTime('declare_le');
            // la reception a l'atelier
            $table->foreignUuid('site_reception_id')->nullable()->constrained('sites');
            $table->foreignUuid('recu_par')->nullable()->constrained('utilisateurs');
            $table->dateTime('recu_le')->nullable();
            $table->decimal('montant_recu', 12, 2)->nullable();
            $table->string('observation')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'date_versement']);
            $table->index('recu_le');
        });

        Schema::create('articles_consommables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('code', 30);
            $table->string('designation', 100);
            $table->string('unite', 20);
            $table->decimal('seuil_alerte', 12, 3)->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'code']);
        });

        Schema::create('mouvements_consommables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('article_id')->constrained('articles_consommables');
            // APPROVISIONNEMENT (entree) ou SORTIE
            $table->string('type', 20);
            $table->decimal('quantite', 12, 3);
            // cout d'achat d'une entree ; la charge correspondante est une sortie de consommables (§7)
            $table->decimal('cout_total', 12, 2)->nullable();
            $table->foreignUuid('utilisateur_id')->constrained('utilisateurs');
            $table->dateTime('mouvement_le');
            $table->string('remarque')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'mouvement_le']);
            $table->index(['site_id', 'mouvement_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_consommables');
        Schema::dropIfExists('articles_consommables');
        Schema::dropIfExists('versements_journaliers');
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->dropColumn('remarque');
        });
    }
};

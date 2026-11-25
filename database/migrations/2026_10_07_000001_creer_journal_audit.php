<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etape 4 - interface web du Promoteur : journal d'audit (cahier des charges v2, §10).
 *
 * Chaque action du Promoteur sur le reseau y laisse une ligne. Le nom de l'auteur et le libelle de
 * l'objet sont recopies : la ligne reste lisible meme si le compte ou l'objet change ensuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('utilisateur_id')->nullable()->constrained('utilisateurs');
            $table->string('auteur', 150);
            $table->string('action', 60);
            $table->string('objet_type', 40)->nullable();
            $table->string('objet_id', 64)->nullable();
            $table->string('objet_libelle')->nullable();
            $table->text('detail')->nullable();
            $table->decimal('montant', 12, 2)->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->dateTime('cree_le')->index();
            $table->index(['objet_type', 'objet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_audit');
    }
};

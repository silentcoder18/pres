<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deux etapes par article (decision du Promoteur, 16 octobre 2026) : un article est lave, puis
 * repasse. Chaque etape est une tache a part, confiee a un travailleur dont le role convient
 * (laveur, repasseur ou polyvalent) et commissionnee a part.
 *
 * - une prestation dit quelles etapes elle comprend (« Repassage seul » n'a pas de lavage) ;
 * - un tarif donne la commission de chaque etape ; sa commission fixe en est la somme (RG-03) ;
 * - une ligne de commande fige les etapes et leurs commissions au depot, comme son prix ;
 * - une tache porte son etape ;
 * - un travailleur a un role.
 *
 * Ce qui existait avant est repris tel quel : chaque prestation comprend les deux etapes sauf
 * celles dont le nom commence par « Repassage » ; la commission d'une prestation a deux etapes
 * est partagee par moitie, au franc pres, le lavage prenant la part arrondie vers le bas ; une
 * tache deja attribuee est une tache de la premiere etape de sa ligne ; un travailleur deja
 * enrole est polyvalent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('avec_lavage')->default(true);
            $table->boolean('avec_repassage')->default(true);
        });
        Schema::table('tarifs', function (Blueprint $table) {
            $table->decimal('commission_lavage', 12, 2)->default(0);
            $table->decimal('commission_repassage', 12, 2)->default(0);
        });
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->boolean('avec_lavage')->default(true);
            $table->boolean('avec_repassage')->default(true);
            $table->decimal('commission_lavage', 12, 2)->default(0);
            $table->decimal('commission_repassage', 12, 2)->default(0);
        });
        Schema::table('taches', function (Blueprint $table) {
            $table->string('etape', 12)->default('LAVAGE');
        });
        Schema::table('travailleurs', function (Blueprint $table) {
            $table->string('role', 12)->default('POLYVALENT');
        });

        $sansLavage = DB::table('services')->whereRaw("lower(nom) like 'repassage%'")->pluck('id')->all();
        DB::table('services')->whereIn('id', $sansLavage)->update(['avec_lavage' => false]);

        foreach (DB::table('tarifs')->get(['id', 'service_id', 'commission_fixe']) as $tarif) {
            DB::table('tarifs')->where('id', $tarif->id)
                ->update($this->partage((string) $tarif->commission_fixe, ! in_array($tarif->service_id, $sansLavage, true)));
        }

        $lignes = DB::table('lignes_commande')
            ->join('tarifs', 'tarifs.id', '=', 'lignes_commande.tarif_id')
            ->get(['lignes_commande.id', 'lignes_commande.commission_appliquee', 'tarifs.service_id']);
        foreach ($lignes as $ligne) {
            $avecLavage = ! in_array($ligne->service_id, $sansLavage, true);
            DB::table('lignes_commande')->where('id', $ligne->id)->update(
                $this->partage((string) $ligne->commission_appliquee, $avecLavage) + ['avec_lavage' => $avecLavage]
            );
            if (! $avecLavage) {
                DB::table('taches')->where('ligne_commande_id', $ligne->id)->update(['etape' => 'REPASSAGE']);
            }
        }
    }

    /** @return array{commission_lavage: string, commission_repassage: string} */
    private function partage(string $commission, bool $avecLavage): array
    {
        if (! $avecLavage) {
            return ['commission_lavage' => '0.00', 'commission_repassage' => $commission];
        }
        $lavage = bcdiv(bcdiv($commission, '2', 0), '1', 2);

        return ['commission_lavage' => $lavage, 'commission_repassage' => bcsub($commission, $lavage, 2)];
    }

    public function down(): void
    {
        Schema::table('travailleurs', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::table('taches', fn (Blueprint $table) => $table->dropColumn('etape'));
        Schema::table('lignes_commande', fn (Blueprint $table) => $table->dropColumn(
            ['avec_lavage', 'avec_repassage', 'commission_lavage', 'commission_repassage']));
        Schema::table('tarifs', fn (Blueprint $table) => $table->dropColumn(['commission_lavage', 'commission_repassage']));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['avec_lavage', 'avec_repassage']));
    }
};

<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Service;
use App\Models\Site;
use App\Models\Tarif;
use Illuminate\Database\Seeder;

/**
 * Jeu d'essai : sites et catalogue repris de l'ancienne application.
 * A lancer explicitement, jamais en production :
 *   php artisan db:seed --class=DonneesDemoSeeder
 */
class DonneesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            ['ATL', 'Atelier Central', 'Zone Industrielle', Site::ATELIER],
            ['CV', 'Centre-Ville', 'Avenue Centrale', Site::BOUTIQUE],
            ['MC', 'Marché Central', 'Rue du Marché', Site::BOUTIQUE],
            ['DL', 'Doualare', 'Carrefour Doualare', Site::BOUTIQUE],
        ];
        foreach ($sites as [$code, $nom, $adresse, $type]) {
            Site::firstOrCreate(['code' => $code], ['nom' => $nom, 'adresse' => $adresse, 'type' => $type]);
        }

        $articles = [];
        foreach ([
            'Chemise' => 'Chemise homme/femme standard',
            'Costume 2 pièces' => 'Veste et pantalon',
            'Pantalon' => 'Pantalon tissu ou jean',
            'Robe simple' => 'Robe de ville',
            'Drap de lit' => 'Linge de maison',
        ] as $nom => $description) {
            $articles[$nom] = Article::firstOrCreate(['nom' => $nom], ['description' => $description]);
        }

        $services = [];
        foreach ([
            'Nettoyage simple' => "Lavage standard à l'eau",
            'Nettoyage à sec' => 'Nettoyage professionnel sans eau',
            'Repassage seul' => 'Finition et pliage ou sur cintre',
        ] as $nom => $description) {
            // un article est lave puis repasse ; « Repassage seul » n'a pas de lavage
            $services[$nom] = Service::firstOrCreate(['nom' => $nom], [
                'description' => $description,
                'avec_lavage' => $nom !== 'Repassage seul',
            ]);
        }

        $tarifs = [
            ['Chemise', 'Nettoyage simple', 1500, 200],
            ['Chemise', 'Repassage seul', 800, 100],
            ['Costume 2 pièces', 'Nettoyage à sec', 5000, 800],
            ['Pantalon', 'Nettoyage simple', 1200, 150],
            ['Robe simple', 'Nettoyage simple', 2500, 350],
            ['Drap de lit', 'Nettoyage simple', 3000, 400],
        ];
        foreach ($tarifs as [$article, $service, $prix, $commission]) {
            Tarif::firstOrCreate(
                ['article_id' => $articles[$article]->id, 'service_id' => $services[$service]->id],
                // la commission se partage entre le lavage et le repassage
                ['prix_vente' => $prix] + Tarif::commissions($services[$service],
                    $services[$service]->avec_repassage ? (string) intdiv($commission, 2) : (string) $commission,
                    $services[$service]->avec_lavage ? (string) ($commission - intdiv($commission, 2)) : (string) $commission),
            );
        }
    }
}

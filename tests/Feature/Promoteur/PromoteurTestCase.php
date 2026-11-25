<?php

namespace Tests\Feature\Promoteur;

use App\Models\Article;
use App\Models\Client;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Paiement;
use App\Models\Service;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\Tarif;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Base des essais de l'interface web : un Promoteur connecte, et de quoi peupler le reseau. */
abstract class PromoteurTestCase extends TestCase
{
    use RefreshDatabase;

    protected Utilisateur $promoteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->promoteur = $this->creerCompte('patron', Utilisateur::PROMOTEUR);
    }

    protected function creerCompte(string $identifiant, string $role = Utilisateur::RECEPTIONNISTE, ?Site $site = null, bool $actif = true): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Nom '.$identifiant,
            'identifiant' => $identifiant,
            'mot_de_passe' => 'motdepasse1',
            'role' => $role,
            'site_id' => $site?->id,
            'actif' => $actif,
        ]);
    }

    protected function creerSite(string $code = 'CV', string $type = Site::BOUTIQUE, ?string $nom = null): Site
    {
        return Site::create(['code' => $code, 'nom' => $nom ?? 'Site '.$code, 'type' => $type]);
    }

    /** Tarif d'une prestation a deux etapes : 200 de commission, 100 au lavage et 100 au repassage. */
    protected function creerTarif(int $prix = 1500, string $article = 'Chemise', string $service = 'Nettoyage simple'): Tarif
    {
        $prestation = Service::firstOrCreate(['nom' => $service]);

        return Tarif::create([
            'article_id' => Article::firstOrCreate(['nom' => $article])->id,
            'service_id' => $prestation->id,
            'prix_vente' => $prix,
        ] + Tarif::commissions($prestation, '100', '100'));
    }

    protected function creerClient(Site $site, string $nom = 'Alice Mbia', string $telephone = '699112233'): Client
    {
        return Client::create(['nom' => $nom, 'telephone' => $telephone, 'quartier' => 'Domayo', 'site_origine_id' => $site->id]);
    }

    /** Une commande de deux chemises a 1 500, telle qu'un poste l'aurait synchronisee. */
    protected function creerCommande(Site $site, Utilisateur $auteur, Client $client, string $code, string $statut = Commande::ENREGISTREE): Commande
    {
        $tarif = Tarif::first() ?? $this->creerTarif();

        $commande = Commande::create([
            'code' => $code,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'utilisateur_id' => $auteur->id,
            'statut' => $statut,
            'deposee_le' => now()->subHour(),
        ]);
        LigneCommande::create([
            'commande_id' => $commande->id,
            'tarif_id' => $tarif->id,
            'quantite' => 2,
            'prix_applique' => 1500,
            'commission_appliquee' => 200,
        ]);
        $commande->recalculer();

        return $commande->refresh();
    }

    protected function ouvrirCaisse(Site $site, Utilisateur $auteur, int $fond = 5000): SessionCaisse
    {
        return SessionCaisse::create([
            'site_id' => $site->id,
            'ouverte_par' => $auteur->id,
            'ouverte_le' => now()->subHours(2),
            'fond_initial' => $fond,
        ]);
    }

    protected function encaisser(Commande $commande, SessionCaisse $session, int $montant, string $type = Paiement::ACOMPTE): Paiement
    {
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'session_caisse_id' => $session->id,
            'utilisateur_id' => $session->ouverte_par,
            'type' => $type,
            'montant' => $montant,
            'encaisse_le' => now()->subMinutes(30),
        ]);
        $commande->recalculer();

        return $paiement;
    }

    /** Changements publies vers les postes depuis ce numero, pour une table. */
    protected function changements(string $table, int $depuis = 0): Collection
    {
        return SyncChangement::where('table_cible', $table)->where('seq', '>', $depuis)->orderBy('seq')->get();
    }

    protected function dernierChangement(): int
    {
        return (int) SyncChangement::max('seq');
    }
}

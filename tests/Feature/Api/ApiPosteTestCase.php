<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AuthentifierPoste;
use App\Models\Article;
use App\Models\Poste;
use App\Models\Service;
use App\Models\SessionPoste;
use App\Models\Site;
use App\Models\Tarif;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class ApiPosteTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Vrai : une requete faite avec le jeton d'un poste l'est sous la session d'une personne de son
     * site (decision du 16 octobre 2026), c'est-a-dire depuis la base de cette personne. Faux : la
     * requete vient de la base de l'ordinateur, sans personne connectee.
     */
    protected bool $sessionsAutomatiques = true;

    /** @var array<string, string> jeton de session par jeton de poste */
    private array $sessions = [];

    /**
     * Jeton du poste, et session d'une personne de son site : la premiere personne active rattachee
     * au site, ou a defaut une personne creee pour l'essai, sans etre publiee.
     */
    public function withToken(string $token, string $type = 'Bearer')
    {
        parent::withToken($token, $type);
        unset($this->defaultHeaders[AuthentifierPoste::ENTETE_SESSION]);
        if ($this->sessionsAutomatiques) {
            $this->withHeader(AuthentifierPoste::ENTETE_SESSION, $this->sessionDuPoste($token));
        }

        return $this;
    }

    /** Requete faite depuis la base de l'ordinateur, sans personne connectee. */
    protected function sansSession(string $jeton): static
    {
        parent::withToken($jeton);
        unset($this->defaultHeaders[AuthentifierPoste::ENTETE_SESSION]);

        return $this;
    }

    protected function sessionDuPoste(string $jeton): string
    {
        $poste = Poste::where('jeton_hash', Poste::empreinte($jeton))->first();
        if ($poste === null) {
            return 'session-inconnue';
        }
        $session = isset($this->sessions[$jeton])
            ? SessionPoste::where('jeton_hash', Poste::empreinte($this->sessions[$jeton]))->first()
            : null;
        if ($session !== null && $session->motifDeRefus() === null) {
            return $this->sessions[$jeton];
        }
        $personne = Utilisateur::where('site_id', $poste->site_id)->where('actif', true)->orderBy('created_at')->first();
        if ($personne === null) {
            $personne = new Utilisateur([
                'nom' => 'Personne '.$poste->nom,
                'identifiant' => 'personne-'.$poste->id,
                'mot_de_passe' => 'motdepasse1',
                'role' => $poste->site?->type === Site::ATELIER ? Utilisateur::CHEF_ATELIER : Utilisateur::RECEPTIONNISTE,
                'site_id' => $poste->site_id,
            ]);
            $personne->saveQuietly();
        }
        [, $this->sessions[$jeton]] = SessionPoste::ouvrir($poste, $personne);

        return $this->sessions[$jeton];
    }

    protected function creerSite(string $code = 'CV', string $type = Site::BOUTIQUE): Site
    {
        return Site::create(['code' => $code, 'nom' => 'Site '.$code, 'type' => $type]);
    }

    /** Poste deja mis en service, joignable avec le jeton donne. */
    protected function creerPoste(Site $site, string $jeton): Poste
    {
        return Poste::create([
            'site_id' => $site->id,
            'nom' => 'Poste '.$site->code,
            'jeton_hash' => Poste::empreinte($jeton),
            'enrole_le' => now(),
        ]);
    }

    protected function creerUtilisateur(?Site $site, string $identifiant, string $role = Utilisateur::RECEPTIONNISTE): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Nom '.$identifiant,
            'identifiant' => $identifiant,
            'mot_de_passe' => 'motdepasse1',
            'role' => $role,
            'site_id' => $site?->id,
        ]);
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

    protected function operationClient(string $id, string $telephone, string $nom = 'Alice Mbia'): array
    {
        return $this->operation('clients', ['id' => $id, 'nom' => $nom, 'telephone' => $telephone, 'quartier' => 'Domayo']);
    }

    /** Une ecriture telle qu'un poste l'envoie ; la ligne visee est celle des donnees. */
    protected function operation(string $table, array $donnees): array
    {
        return [
            'id' => (string) str()->uuid(),
            'table' => $table,
            'ligne_id' => $donnees['id'],
            'operation' => 'upsert',
            'donnees' => $donnees,
            'cree_le' => now()->toIso8601String(),
        ];
    }

    protected function pousser(string $jeton, array ...$operations): TestResponse
    {
        return $this->withToken($jeton)->postJson('/api/sync/pousser', ['operations' => $operations])->assertOk();
    }

    /** Tous les changements qu'un poste recoit en tirant depuis le debut du fil. */
    protected function changementsPour(string $jeton, int $curseur = 0): Collection
    {
        return collect($this->withToken($jeton)->getJson('/api/sync/tirer?curseur='.$curseur)->assertOk()->json('changements'));
    }
}

<?php

namespace App\Services\Sync;

use App\Models\Article;
use App\Models\ArticleConsommable;
use App\Models\AvanceCommission;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Consommable;
use App\Models\Controle;
use App\Models\DemandeAnnulation;
use App\Models\Lot;
use App\Models\MouvementConsommable;
use App\Models\Paiement;
use App\Models\Parametre;
use App\Models\Poste;
use App\Models\Service;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\Tache;
use App\Models\Tarif;
use App\Models\Travailleur;
use App\Models\Utilisateur;
use App\Models\VersementJournalier;
use Illuminate\Database\Eloquent\Builder;

/** Ce que les postes viennent chercher : l'etat complet a la mise en service, puis les changements. */
class FluxChangements
{
    /**
     * Un poste remis en service retrouve ce qui s'est passe ces dernieres semaines : commandes sorties
     * du suivi, leurs encaissements et leurs taches, sorties de consommables.
     */
    private const SEMAINES_D_HISTORIQUE = 8;

    /** Ce que recoit la base de l'ordinateur : de quoi connecter les personnes. */
    private const TABLES_DE_REFERENCE = ['sites', 'articles', 'services', 'tarifs', 'parametres', 'utilisateurs'];

    /**
     * Etat courant de tout ce qui concerne le poste, dans un ordre applicable tel quel et
     * decoupe en pages : tant que "reste" est vrai, le poste rappelle avec la "suite" recue.
     *
     * Le curseur a garder est celui de la premiere page : ce qui change pendant le
     * telechargement sera simplement rejoue par les tirages suivants.
     *
     * @return array{changements: array, curseur: int, reste: bool, suite: ?string}
     */
    public function instantane(Perimetre $perimetre, ?string $suite = null): array
    {
        // Lu avant les donnees : un changement fait pendant la lecture sera simplement rejoue
        $curseur = $this->curseurStable();
        $taille = (int) config('pressing.sync.taille_lot');

        $sections = $this->sections($perimetre);
        [$indice, $apres] = $this->lireSuite($suite);

        $lignes = [];
        while ($indice < count($sections) && count($lignes) < $taille) {
            $place = $taille - count($lignes);
            $requete = $sections[$indice];
            $cle = $requete->getModel()->getQualifiedKeyName();
            if ($apres !== null) {
                $requete->where($cle, '>', $apres);
            }
            $page = $requete->orderBy($cle)->limit($place)->get();
            foreach ($page as $modele) {
                $lignes[] = $this->ligne($modele);
            }

            if ($page->count() < $place) {
                $indice++;
                $apres = null;
            } else {
                $apres = (string) $page->last()->getKey();
            }
        }

        $fini = $indice >= count($sections);

        return [
            'changements' => $lignes,
            'curseur' => $curseur,
            'reste' => ! $fini,
            'suite' => $fini ? null : $indice.':'.$apres,
        ];
    }

    /**
     * Ce que la base doit connaitre, dans l'ordre d'envoi : les donnees de reference et les comptes,
     * puis, pour la base d'une personne, les clients et le travail en cours de son site de
     * rattachement (§4.3, principe 7). La base de l'ordinateur n'a que la reference, qui sert a
     * connecter les personnes.
     *
     * @return list<Builder>
     */
    private function sections(Perimetre $perimetre): array
    {
        $reference = [
            Site::query(),
            Article::query(),
            Service::query(),
            Tarif::query(),
            Parametre::query(),
            // tous les comptes : une personne de n'importe quel site se connecte sur n'importe quel poste
            Utilisateur::query(),
        ];
        if ($perimetre->estMateriel()) {
            return $reference;
        }

        $site = $perimetre->site_id;
        // Commande encore suivie : en cours, annulee dont l'avance reste a restituer par son site (RG-24),
        // ou sortie du suivi depuis peu : un poste remis en service retrouve ainsi les encaissements de
        // sa caisse du jour et les taches dont se calculent les commissions de la semaine (RG-12)
        $suivies = fn (Builder $q) => $q
            ->whereNotIn('statut', Commande::TERMINAUX)
            ->orWhere(fn (Builder $q) => $q
                ->where('statut', Commande::ANNULEE)
                ->where('site_id', $site)
                ->where('total_paye', '>', 0))
            ->orWhere('updated_at', '>=', now()->subWeeks(self::SEMAINES_D_HISTORIQUE));
        // Commandes du site, et commandes que d'autres sites lui ont envoyees par lot
        $commandesDuPoste = fn (Builder $q) => $q
            ->where($suivies)
            ->where(fn (Builder $q) => $q
                ->where('site_id', $site)
                ->orWhereHas('lots', fn (Builder $lot) => $lot->where('site_destination_id', $site)));

        return [
            ...$reference,
            Client::query(),
            SessionCaisse::where('site_id', $site)->whereNull('cloturee_le'),
            Commande::where($commandesDuPoste),
            Paiement::whereHas('commande', fn (Builder $q) => $q
                ->where('site_id', $site)
                ->where($suivies)),
            Lot::where(fn (Builder $q) => $q->where('site_origine_id', $site)->orWhere('site_destination_id', $site))
                ->whereHas('commandes', fn (Builder $q) => $q->whereNotIn('statut', Commande::TERMINAUX)),
            // etape 3 : le travail de l'atelier sur ces commandes, et ce qui n'appartient qu'a lui
            Controle::whereHas('commande', $commandesDuPoste),
            Travailleur::where('site_id', $site),
            Tache::whereHas('commande', $commandesDuPoste)
                ->whereHas('travailleur', fn (Builder $q) => $q->where('site_id', $site)),
            Consommable::where('site_id', $site)->where('sortie_le', '>=', now()->subWeeks(self::SEMAINES_D_HISTORIQUE)),
            // RG-24 : les demandes d'annulation des commandes du site que le poste vient de recevoir,
            // en attente ou deja tranchees
            DemandeAnnulation::whereHas('commande', fn (Builder $q) => $q
                ->where('site_id', $site)
                ->where($suivies)),
            // ameliorations du 23 octobre 2026 : les versements de la boutique, ou, pour un atelier,
            // ceux de toutes les boutiques ; ceux qui attendent leur reception et les plus recents
            VersementJournalier::where(fn (Builder $q) => $q
                ->whereNull('recu_le')
                ->orWhere('date_versement', '>=', now()->subWeeks(self::SEMAINES_D_HISTORIQUE)->toDateString()))
                ->when(! $this->estAtelier($site), fn (Builder $q) => $q->where('site_id', $site)),
            // le stock de l'atelier est la somme de tous ses mouvements : ils viennent tous
            ArticleConsommable::where('site_id', $site),
            MouvementConsommable::where('site_id', $site),
            // les avances sur commission des dernieres semaines : ce qui reste a payer s'en deduit
            AvanceCommission::where('site_id', $site)->where('versee_le', '>=', now()->subWeeks(self::SEMAINES_D_HISTORIQUE)),
        ];
    }

    private function estAtelier(?string $siteId): bool
    {
        return $siteId !== null && Site::whereKey($siteId)->where('type', Site::ATELIER)->exists();
    }

    /** @return array{0: int, 1: ?string} numero de la section a reprendre et derniere cle deja envoyee */
    private function lireSuite(?string $suite): array
    {
        if ($suite === null || $suite === '') {
            return [0, null];
        }
        [$indice, $apres] = explode(':', $suite, 2) + [1 => ''];

        return [(int) $indice, $apres === '' ? null : $apres];
    }

    /**
     * Changements publies apres le curseur et destines a cette base : ceux de tout le reseau et ceux
     * du site de travail, sauf ce que la base a elle-meme envoye. Ce qu'une autre personne a fait
     * sur le meme poste, dans sa propre base, est bien transmis. La base de l'ordinateur ne recoit
     * que la reference.
     *
     * @return array{changements: array, curseur: int, reste: bool}
     */
    public function tirer(Perimetre $perimetre, int $curseur): array
    {
        $taille = (int) config('pressing.sync.taille_lot');

        $requete = SyncChangement::where('seq', '>', $curseur);
        if ($perimetre->estMateriel()) {
            $requete->whereNull('site_id')->whereIn('table_cible', self::TABLES_DE_REFERENCE);
        } else {
            $session = $perimetre->session->id;
            $requete
                ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $perimetre->site_id))
                ->where(fn ($q) => $q->whereNull('origine_session_id')->orWhere('origine_session_id', '!=', $session));
        }
        $lignes = $requete->orderBy('seq')->limit($taille + 1)->get();

        $reste = $lignes->count() > $taille;
        $lot = $lignes->take($taille);

        // S'il reste des changements, le poste reprend apres le dernier recu ;
        // sinon il avance jusqu'au dernier changement assez ancien pour etre definitif.
        $nouveauCurseur = $reste
            ? min((int) $lot->last()->seq, max($curseur, $this->curseurStable()))
            : max($curseur, $this->curseurStable());

        return [
            'changements' => $lot->map->versPoste()->values()->all(),
            'curseur' => $nouveauCurseur,
            'reste' => $reste && $nouveauCurseur > $curseur,
        ];
    }

    /** Plus grand numero de changement plus ancien que la marge de securite (voir config/pressing.php). */
    private function curseurStable(): int
    {
        $limite = now()->subSeconds((int) config('pressing.sync.marge_secondes'));

        return (int) SyncChangement::where('cree_le', '<=', $limite)->max('seq');
    }

    private function ligne($modele): array
    {
        return [
            'seq' => 0,
            'table' => $modele->tablePoste(),
            'ligne_id' => (string) $modele->getKey(),
            'operation' => SyncChangement::UPSERT,
            'donnees' => $modele->donneesPoste(),
            'urgent' => false,
        ];
    }
}

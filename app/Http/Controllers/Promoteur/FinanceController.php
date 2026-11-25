<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Finance\RapportHebdomadaire;
use App\Services\Finance\RapportPdf;
use App\Services\Finance\SemaineComptable;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Finances : situation hebdomadaire du reseau ou d'un site, calculee par le serveur a partir de
 * ce que les postes ont synchronise (§7), et son rapport en PDF. Consultation seule : la cloture,
 * les commissions et les emprunts viendront avec l'etape 5.
 */
class FinanceController extends Controller
{
    /** Semaines proposees dans la liste de choix, en remontant depuis la semaine en cours. */
    private const SEMAINES_PROPOSEES = 26;

    public function index(Request $request, RapportHebdomadaire $calcul): View
    {
        [$semaine, $site] = $this->choix($request);

        return view('promoteur.finances.index', [
            'rapport' => $calcul->calculer($semaine, $site),
            'semaine' => $semaine,
            'site' => $site,
            'sites' => Site::orderBy('nom')->get(),
            'semaines' => $this->semainesProposees($semaine),
        ]);
    }

    /** Rapport financier de la semaine en PDF ; chaque export laisse une ligne au journal d'audit. */
    public function rapport(Request $request, RapportHebdomadaire $calcul, RapportPdf $pdf): Response
    {
        [$semaine, $site] = $this->choix($request);

        $contenu = $pdf->generer($calcul->calculer($semaine, $site), $request->user()->nom);
        $fichier = $pdf->nomFichier($semaine, $site);

        // Ecrite une fois le fichier pret, et avant de le remettre : pas d'export sans sa ligne
        Audit::noter(
            Audit::RAPPORT_FINANCIER_EXPORTE,
            $site,
            $semaine->libelle().' — '.($site === null ? 'réseau entier' : $site->nom),
            'Semaine '.$semaine->iso().', '.$semaine->periode()
                .' ; périmètre : '.($site === null ? 'réseau entier' : 'site '.$site->code.' — '.$site->nom)
                .' ; fichier : '.$fichier,
        );

        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fichier.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Semaine et site demandes dans l'adresse : ?semaine=2026-W41&site=<identifiant>. Sans semaine,
     * c'est la semaine en cours ; sans site, tout le reseau.
     *
     * @return array{0: SemaineComptable, 1: ?Site}
     */
    private function choix(Request $request): array
    {
        $validation = Validator::make($request->query(), [
            'semaine' => ['bail', 'nullable', 'string', function (string $attribut, mixed $valeur, Closure $refuser) {
                if (SemaineComptable::depuisIso($valeur) === null) {
                    $refuser('validation.semaine_iso')->translate();
                }
            }],
            'site' => ['bail', 'nullable', 'uuid', Rule::exists('sites', 'id')],
        ]);

        if ($validation->fails()) {
            // Une adresse mal formee ne montre ni page vide ni chiffres d'une autre semaine : on
            // revient a la semaine en cours, avec le motif du refus
            throw (new ValidationException($validation))->redirectTo(route('finances.index'));
        }

        $choix = $validation->validated();

        return [
            isset($choix['semaine']) ? SemaineComptable::depuisIso($choix['semaine']) : SemaineComptable::courante(),
            isset($choix['site']) ? Site::findOrFail($choix['site']) : null,
        ];
    }

    /**
     * Semaines de la liste de choix : les dernieres, de la plus recente a la plus ancienne, et
     * celle qui est affichee si elle n'en fait pas partie.
     *
     * @return list<SemaineComptable>
     */
    private function semainesProposees(SemaineComptable $affichee): array
    {
        $semaines = [];
        $semaine = SemaineComptable::courante();
        for ($i = 0; $i < self::SEMAINES_PROPOSEES; $i++) {
            $semaines[$semaine->iso()] = $semaine;
            $semaine = $semaine->precedente();
        }
        $semaines[$affichee->iso()] ??= $affichee;

        // L'ecriture 2026-W41 se trie comme les dates
        krsort($semaines);

        return array_values($semaines);
    }
}

<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Service;
use App\Models\Tache;
use App\Models\Tarif;
use App\Support\Affichage;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Catalogue et tarifs. Rien ne se supprime : un article, une prestation ou un tarif retire de la
 * vente est desactive, parce que des lignes de commande s'y referent. Un prix modifie ne touche
 * pas les commandes deja enregistrees (RG-03).
 */
class CatalogueController extends Controller
{
    public const ONGLETS = ['tarifs', 'articles', 'prestations'];

    public function index(Request $request): View
    {
        $onglet = in_array($request->query('onglet'), self::ONGLETS, true) ? $request->query('onglet') : 'tarifs';

        return view('promoteur.catalogue.index', [
            'onglet' => $onglet,
            'tarifs' => Tarif::with(['article', 'service'])->get()
                ->sortBy(fn (Tarif $t) => mb_strtolower($t->article->nom.' '.$t->service->nom))->values(),
            'articles' => Article::orderBy('nom')->get(),
            'services' => Service::orderBy('nom')->get(),
        ]);
    }

    public function creerArticle(Request $request): RedirectResponse
    {
        return $this->creerElement($request, new Article, 'articles', "L'article « %s » est créé.");
    }

    public function modifierArticle(Request $request, Article $article): RedirectResponse
    {
        return $this->modifierElement($request, $article, 'articles', "L'article « %s » est mis à jour.");
    }

    public function creerService(Request $request): RedirectResponse
    {
        $service = new Service($this->etapesDuFormulaire($request, null));

        return $this->creerElement($request, $service, 'prestations', 'La prestation « %s » est créée.');
    }

    /**
     * Une prestation qui change d'etapes change aussi ses tarifs : l'etape retiree ne rapporte plus
     * de commission. Les commandes deja enregistrees gardent les etapes de leur depot.
     */
    public function modifierService(Request $request, Service $service): RedirectResponse
    {
        $service->fill($this->etapesDuFormulaire($request, $service));
        $etapesChangees = $service->isDirty(['avec_lavage', 'avec_repassage']);
        $retour = $this->modifierElement($request, $service, 'prestations', 'La prestation « %s » est mise à jour.');
        if ($etapesChangees) {
            foreach (Tarif::where('service_id', $service->id)->get() as $tarif) {
                $tarif->update(Tarif::commissions($service, (string) $tarif->commission_lavage, (string) $tarif->commission_repassage));
            }
        }

        return $retour;
    }

    /**
     * Etapes cochees sur le formulaire d'une prestation : lavage, repassage, ou les deux (decision
     * du 16 octobre 2026). Un formulaire qui ne les montre pas garde celles de la prestation, les
     * deux pour une nouvelle.
     *
     * @return array{avec_lavage: bool, avec_repassage: bool}
     */
    private function etapesDuFormulaire(Request $request, ?Service $service): array
    {
        if (! $request->has('etapes_envoyees')) {
            return [
                'avec_lavage' => $service === null ? true : (bool) $service->avec_lavage,
                'avec_repassage' => $service === null ? true : (bool) $service->avec_repassage,
            ];
        }
        $etapes = $request->validateWithBag('prestations', [
            'etapes' => ['required', 'array', 'min:1'],
            'etapes.*' => ['in:'.implode(',', Tache::ETAPES)],
        ], ['etapes.required' => 'Cochez au moins une étape : lavage, repassage, ou les deux.'])['etapes'];

        return [
            'avec_lavage' => in_array(Tache::LAVAGE, $etapes, true),
            'avec_repassage' => in_array(Tache::REPASSAGE, $etapes, true),
        ];
    }

    public function creerTarif(Request $request): RedirectResponse
    {
        $donnees = $request->validateWithBag('tarifs', [
            'article_id' => ['required', 'uuid', 'exists:articles,id'],
            'service_id' => [
                'required', 'uuid', 'exists:services,id',
                Rule::unique('tarifs')->where(fn ($q) => $q->where('article_id', $request->input('article_id'))),
            ],
            ...$this->reglesPrix(),
        ], $this->messagesPrix() + [
            'service_id.unique' => 'Ce couple article et prestation a déjà un tarif : modifiez-le dans la liste.',
        ]);

        $tarif = Tarif::create([
            'article_id' => $donnees['article_id'],
            'service_id' => $donnees['service_id'],
            'prix_vente' => $donnees['prix_vente'],
        ] + $this->commissionsDuFormulaire($request, Service::findOrFail($donnees['service_id'])));
        Audit::noter(Audit::CATALOGUE_CREE, $tarif, $this->libelleTarif($tarif),
            'Prix : '.Affichage::fcfa($tarif->prix_vente).' ; commission : '.Affichage::fcfa($tarif->commission_fixe));

        return $this->retour('tarifs', 'Le tarif est créé. Les postes le recevront à leur prochaine synchronisation.');
    }

    public function modifierTarif(Request $request, Tarif $tarif): RedirectResponse
    {
        $donnees = $request->validateWithBag('tarifs', [
            ...$this->reglesPrix(),
            'actif' => ['required', 'boolean'],
        ], $this->messagesPrix());

        $tarif->update([
            'prix_vente' => $donnees['prix_vente'],
            'actif' => $donnees['actif'],
        ] + $this->commissionsDuFormulaire($request, $tarif->service));

        if ($tarif->wasChanged()) {
            Audit::noter(Audit::CATALOGUE_MODIFIE, $tarif, $this->libelleTarif($tarif),
                Audit::changements($tarif, [
                    'prix_vente' => 'Prix',
                    'commission_lavage' => 'Commission du lavage',
                    'commission_repassage' => 'Commission du repassage',
                    'actif' => 'Actif',
                ]));
        }

        return $this->retour('tarifs', 'Le tarif est mis à jour. Les commandes déjà enregistrées gardent leur prix.');
    }

    /**
     * RG-03 : montants en francs CFA, sans centimes ; la commission ne depasse pas le prix. Elle se
     * donne etape par etape (lavage, repassage) ; une commission donnee d'un seul tenant est
     * partagee par moitie entre les deux etapes.
     */
    private function reglesPrix(): array
    {
        return [
            'prix_vente' => ['required', 'integer', 'min:0', 'max:99999999'],
            'commission_lavage' => ['required_without:commission_fixe', 'nullable', 'integer', 'min:0', 'max:99999999'],
            'commission_repassage' => ['required_without:commission_fixe', 'nullable', 'integer', 'min:0', 'max:99999999'],
            'commission_fixe' => ['nullable', 'integer', 'min:0', 'lte:prix_vente'],
        ];
    }

    private function messagesPrix(): array
    {
        return [
            'commission_fixe.lte' => 'La commission ne peut pas dépasser le prix de vente.',
            'commission_lavage.required_without' => 'Saisissez la commission du lavage (0 si la prestation n\'en comprend pas).',
            'commission_repassage.required_without' => 'Saisissez la commission du repassage (0 si la prestation n\'en comprend pas).',
        ];
    }

    /**
     * Commission de chaque etape, d'apres le formulaire, dans les limites de la prestation.
     *
     * @return array{commission_lavage: string, commission_repassage: string, commission_fixe: string}
     */
    private function commissionsDuFormulaire(Request $request, Service $service): array
    {
        if ($request->filled('commission_lavage') || $request->filled('commission_repassage')) {
            $lavage = (string) (int) $request->input('commission_lavage', 0);
            $repassage = (string) (int) $request->input('commission_repassage', 0);
            $champ = 'commission_lavage';
        } else {
            $total = (string) (int) $request->input('commission_fixe', 0);
            $lavage = $service->avec_lavage && $service->avec_repassage ? bcdiv($total, '2', 0) : $total;
            $repassage = bcsub($total, $lavage, 0);
            if (! $service->avec_lavage) {
                [$lavage, $repassage] = ['0', $total];
            }
            $champ = 'commission_fixe';
        }
        $commissions = Tarif::commissions($service, $lavage, $repassage);
        if (bccomp($commissions['commission_fixe'], (string) (int) $request->input('prix_vente'), 2) > 0) {
            throw ValidationException::withMessages([$champ => 'La commission ne peut pas dépasser le prix de vente.'])
                ->errorBag('tarifs');
        }

        return $commissions;
    }

    private function creerElement(Request $request, Model $modele, string $onglet, string $message): RedirectResponse
    {
        $donnees = $request->validateWithBag($onglet, [
            'nom' => ['required', 'string', 'max:100', Rule::unique($modele->getTable(), 'nom')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $modele->fill($donnees)->save();
        Audit::noter(Audit::CATALOGUE_CREE, $modele, $modele->nom);

        return $this->retour($onglet, sprintf($message, $modele->nom));
    }

    private function modifierElement(Request $request, Model $modele, string $onglet, string $message): RedirectResponse
    {
        $donnees = $request->validateWithBag($onglet, [
            'nom' => ['required', 'string', 'max:100', Rule::unique($modele->getTable(), 'nom')->ignore($modele->getKey())],
            'description' => ['nullable', 'string', 'max:255'],
            'actif' => ['required', 'boolean'],
        ]);

        $modele->update($donnees);

        if ($modele->wasChanged()) {
            Audit::noter(Audit::CATALOGUE_MODIFIE, $modele, $modele->nom,
                Audit::changements($modele, ['nom' => 'Nom', 'description' => 'Description', 'actif' => 'Actif']));
        }

        return $this->retour($onglet, sprintf($message, $modele->nom));
    }

    private function libelleTarif(Tarif $tarif): string
    {
        return $tarif->article->nom.' — '.$tarif->service->nom;
    }

    private function retour(string $onglet, string $message): RedirectResponse
    {
        return redirect()->route('catalogue.index', ['onglet' => $onglet])->with('succes', $message);
    }
}

<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Poste;
use App\Models\Site;
use App\Services\Postes\MiseEnService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Postes des sites (§4.2, §11 Securite). C'est ici, et seulement ici, que nait un code de mise
 * en service : le Promoteur le genere, il est montre une seule fois sur la page qui suit, et il
 * n'est jamais ecrit dans le journal d'audit. Aucune commande ne permet d'en creer un autrement.
 */
class PosteController extends Controller
{
    public function store(Request $request, Site $site, MiseEnService $miseEnService): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['nullable', 'string', 'max:100'],
        ]);
        // Depuis la liste des sites, le code se genere d'un clic : le poste prend le nom courant
        $donnees['nom'] ??= 'Poste principal';

        if (! $site->actif) {
            return back()->withErrors(['nom' => 'Ce site est désactivé : réactivez-le avant de générer un code de mise en service.']);
        }
        // Un seul ordinateur par site (§15.1)
        if ($site->postes()->whereNull('revoque_le')->exists()) {
            return back()->withErrors(['nom' => "Ce site a déjà un poste. Pour changer d'ordinateur, générez un nouveau code pour ce poste."]);
        }

        [$poste, $code] = $miseEnService->declarer($site, $donnees['nom']);
        Audit::noter(Audit::POSTE_DECLARE, $poste, $poste->nom.' — '.$site->nom);

        return $this->montrerCode($poste, $code, $miseEnService);
    }

    public function renouvelerCode(Poste $poste, MiseEnService $miseEnService): RedirectResponse
    {
        $autre = Poste::where('site_id', $poste->site_id)->whereNull('revoque_le')->whereKeyNot($poste->id)->exists();
        if ($autre) {
            return back()->withErrors(['poste' => 'Ce site a déjà un autre poste en service : révoquez-le avant de rétablir celui-ci.']);
        }

        $code = $miseEnService->renouvelerCode($poste);
        Audit::noter(Audit::POSTE_NOUVEAU_CODE, $poste, $poste->nom.' — '.$poste->site->nom);

        return $this->montrerCode($poste, $code, $miseEnService);
    }

    public function revoquer(Poste $poste): RedirectResponse
    {
        if ($poste->revoque_le === null) {
            $poste->forceFill(['revoque_le' => now()])->save();
            Audit::noter(Audit::POSTE_REVOQUE, $poste, $poste->nom.' — '.$poste->site->nom);
        }

        return redirect()->route('sites.show', $poste->site_id)
            ->with('succes', "L'accès du poste « {$poste->nom} » est révoqué. Il se verrouillera à sa prochaine synchronisation.");
    }

    private function montrerCode(Poste $poste, string $code, MiseEnService $miseEnService): RedirectResponse
    {
        return redirect()->route('sites.show', $poste->site_id)->with('code_mise_en_service', [
            'poste' => $poste->nom,
            'code' => $code,
            'validite' => $miseEnService->validiteHeures(),
        ]);
    }
}

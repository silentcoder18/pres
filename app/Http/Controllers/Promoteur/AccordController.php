<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Controle;
use App\Services\Atelier\Corrections;
use Illuminate\View\View;

/**
 * Commandes en attente de l'accord du client (RG-09) : le controle a l'atelier a releve un ecart
 * qui change la facture, et la boutique doit joindre le client. Consultation seule : l'accord
 * s'enregistre sur le poste de la boutique, et une correction restee sans accord s'applique
 * d'office apres le delai fixe dans les parametres.
 */
class AccordController extends Controller
{
    public function index(Corrections $corrections): View
    {
        return view('promoteur.accords.index', [
            'controles' => Controle::correctionsEnAttente()
                ->with(['commande.site', 'commande.client', 'tarif.article', 'tarif.service', 'utilisateur'])
                ->orderBy('controle_le')->orderBy('id')
                ->paginate(25),
            // null tant que le Promoteur n'a pas fixe de delai : rien ne s'applique d'office
            'delai' => $corrections->delaiJours(),
        ]);
    }
}

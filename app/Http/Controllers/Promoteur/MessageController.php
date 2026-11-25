<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * File des messages WhatsApp (§8). Les postes y deposent leurs messages ; l'envoi par l'API
 * officielle est prevu a l'etape 6, d'ici la ils restent « en attente ».
 */
class MessageController extends Controller
{
    public const ETATS = [Message::EN_ATTENTE, Message::ENVOYE, Message::ECHOUE];

    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'etat' => ['nullable', Rule::in(self::ETATS)],
            'site' => ['nullable', 'uuid'],
        ]);

        $compteurs = Message::selectRaw('etat, count(*) as nombre')->groupBy('etat')->pluck('nombre', 'etat');

        return view('promoteur.messages', [
            'messages' => Message::with(['site', 'client', 'commande'])
                ->when($filtres['etat'] ?? null, fn (Builder $q, $etat) => $q->where('etat', $etat))
                ->when($filtres['site'] ?? null, fn (Builder $q, $site) => $q->where('site_id', $site))
                ->orderByDesc('cree_le')
                ->paginate(25)->withQueryString(),
            'compteurs' => $compteurs,
            'sites' => Site::orderBy('nom')->get(),
            'filtres' => $filtres,
            'etats' => self::ETATS,
        ]);
    }
}

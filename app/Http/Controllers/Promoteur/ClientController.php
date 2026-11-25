<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientReuni;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Clients du reseau, et fiches en double a trancher (RG-21, §4.4) : deux sites ont enregistre le
 * meme numero sous deux noms. Le serveur a garde la premiere fiche ; le Promoteur dit quel nom
 * est le bon.
 */
class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $filtres = $request->validate(['recherche' => ['nullable', 'string', 'max:100']]);

        return view('promoteur.clients.index', [
            'clients' => Client::with('siteOrigine')->withCount('commandes')
                ->when($filtres['recherche'] ?? null, function (Builder $q, $recherche) {
                    $motif = '%'.addcslashes(trim($recherche), '%_\\').'%';
                    $q->where(fn (Builder $q) => $q->where('nom', 'like', $motif)->orWhere('telephone', 'like', $motif));
                })
                ->orderBy('nom')
                ->paginate(25)->withQueryString(),
            'filtres' => $filtres,
            'aTrancher' => ClientReuni::where('a_trancher', true)->count(),
        ]);
    }

    public function doublons(): View
    {
        return view('promoteur.clients.doublons', [
            'aTrancher' => ClientReuni::with(['client.siteOrigine', 'site'])
                ->where('a_trancher', true)->orderBy('created_at')->get(),
            'tranchees' => ClientReuni::with(['client', 'site'])
                ->whereNotNull('tranche_le')->orderByDesc('tranche_le')->limit(20)->get(),
        ]);
    }

    public function trancher(Request $request, ClientReuni $reunion): RedirectResponse
    {
        $choix = $request->validate(['nom' => ['required', Rule::in(['conserve', 'propose'])]])['nom'];

        if (! $reunion->a_trancher) {
            return redirect()->route('clients.doublons')->with('succes', 'Ce cas a déjà été tranché.');
        }

        $client = $reunion->client;
        $ancien = $client->nom;
        if ($choix === 'propose') {
            // Enregistre par le Promoteur : le nouveau nom part en priorite vers tous les postes (§4.6)
            $client->update(['nom' => $reunion->nom_propose]);
        }
        $reunion->forceFill(['a_trancher' => false, 'tranche_le' => now()])->saveQuietly();

        Audit::noter(Audit::DOUBLON_TRANCHE, $client, $client->telephone.' — '.$client->nom,
            $choix === 'propose'
                ? "Nom retenu : {$client->nom} (à la place de {$ancien})"
                : "Nom gardé : {$client->nom} (nom écarté : {$reunion->nom_propose})");

        return redirect()->route('clients.doublons')
            ->with('succes', "La fiche du {$client->telephone} porte désormais le nom « {$client->nom} ».");
    }
}

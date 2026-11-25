@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', "Commandes en attente d'accord")

@section('contenu')
    <header class="entete">
        <div>
            <h1>Commandes en attente d'accord</h1>
            <p>
                Au contrôle, l'atelier a compté autre chose que ce que la boutique avait enregistré. La commande reste
                bloquée jusqu'à ce que la réceptionniste enregistre l'accord du client sur son poste.
                @if ($delai)
                    Sans accord {{ $delai }} {{ $delai > 1 ? 'jours' : 'jour' }} après l'arrivée de la correction sur le serveur,
                    elle s'applique d'office et le client en est averti.
                @endif
            </p>
        </div>
    </header>

    @unless ($delai)
        <div class="message message-info">
            Aucun délai d'accord n'est fixé : une commande reste bloquée tant que le client n'a pas donné son accord.
            Vous pouvez fixer ce délai dans les <a href="{{ route('parametres.index') }}">Paramètres</a>.
        </div>
    @endunless

    <section class="carte">
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Commande</th>
                    <th>Client</th>
                    <th>Article</th>
                    <th class="nombre">Enregistré → constaté</th>
                    {{-- Texte libre : une largeur minimale lui evite d'etre replie mot a mot sur un ecran etroit --}}
                    <th style="min-width:220px">Motif</th>
                    <th>En attente depuis</th>
                    <th>Application d'office</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($controles as $controle)
                    @php
                        $commande = $controle->commande;
                        // comme Corrections::appliquerEchues : le delai court depuis la reception par le serveur
                        $echeance = $delai ? $controle->created_at->copy()->addDays($delai) : null;
                    @endphp
                    <tr>
                        <td class="serre">
                            <a href="{{ route('commandes.show', $commande) }}"><code>{{ $commande->code }}</code></a>
                            <div class="secondaire">{{ $commande->site?->nom }}</div>
                        </td>
                        <td>
                            {{ $commande->client?->nom }}
                            <div class="secondaire">{{ $commande->client?->telephone }}</div>
                        </td>
                        <td>
                            {{ $controle->tarif?->article?->nom ?? 'Article' }}
                            <div class="secondaire">{{ $controle->tarif?->service?->nom }}</div>
                        </td>
                        <td class="nombre">{{ $controle->quantite_avant }} → {{ $controle->quantite_apres }}</td>
                        <td>{{ $controle->remarque ?: '—' }}</td>
                        <td class="serre">
                            {{ Affichage::dateHeure($controle->controle_le) }}
                            <div class="secondaire">{{ Affichage::depuis($controle->controle_le) }}</div>
                        </td>
                        <td class="serre">
                            @if ($echeance === null)
                                <span class="secondaire">aucun délai fixé</span>
                            @elseif ($echeance->isPast())
                                <span class="pastille pastille-attention">Délai écoulé</span>
                                <div class="secondaire">appliquée à la prochaine vérification du serveur</div>
                            @else
                                le {{ Affichage::dateHeure($echeance) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide">Aucune commande n'attend l'accord d'un client.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $controles->links('promoteur.partials.pagination') }}
    </section>
@endsection

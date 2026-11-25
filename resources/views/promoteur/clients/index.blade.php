@extends('layouts.promoteur')

@section('titre', 'Clients')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Clients</h1>
            <p>Un client est connu de tout le réseau par son numéro de téléphone. Les fiches sont créées sur les sites.</p>
        </div>
        <div class="entete-actions">
            <a class="bouton @if ($aTrancher === 0) bouton-secondaire @endif" href="{{ route('clients.doublons') }}">
                Fiches en double @if ($aTrancher > 0) ({{ $aTrancher }} à trancher) @endif
            </a>
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('clients.index') }}" class="filtres">
            <div class="champ champ-large">
                <label for="recherche">Nom ou téléphone</label>
                <input type="search" id="recherche" name="recherche" value="{{ $filtres['recherche'] ?? '' }}" maxlength="100">
            </div>
            <button type="submit" class="bouton bouton-secondaire">Rechercher</button>
            @if (array_filter($filtres))
                <a class="bouton bouton-secondaire" href="{{ route('clients.index') }}">Tout afficher</a>
            @endif
        </form>

        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr><th>Nom</th><th>Téléphone</th><th>Quartier</th><th>Fiche créée à</th><th class="nombre">Commandes</th></tr>
                </thead>
                <tbody>
                @forelse ($clients as $client)
                    <tr>
                        <td>{{ $client->nom }}</td>
                        <td class="serre">{{ $client->telephone }}</td>
                        <td>{{ $client->quartier ?: '—' }}</td>
                        <td>{{ $client->siteOrigine?->nom ?? '—' }}</td>
                        <td class="nombre">
                            @if ($client->commandes_count > 0)
                                <a href="{{ route('commandes.index', ['recherche' => $client->telephone]) }}">{{ $client->commandes_count }}</a>
                            @else
                                0
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucun client ne correspond.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $clients->links('promoteur.partials.pagination') }}
    </section>
@endsection

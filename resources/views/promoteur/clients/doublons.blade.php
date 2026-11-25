@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Fiches client en double')

@section('contenu')
    <header class="entete">
        <div>
            <div class="fil"><a href="{{ route('clients.index') }}">Clients</a> ›</div>
            <h1>Fiches client en double</h1>
            <p>
                Deux sites ont enregistré le même numéro de téléphone sous deux noms différents. Le serveur a déjà réuni
                les deux fiches et leurs commandes ; il reste à dire quel nom est le bon. Votre choix part vers tous les postes.
            </p>
        </div>
    </header>

    <section class="carte">
        <h2>À trancher</h2>
        @forelse ($aTrancher as $reunion)
            <div class="alerte alerte-attention" style="margin-top:10px">
                <div>
                    <strong>{{ $reunion->client->telephone }}</strong>
                    <div>
                        Fiche gardée : « {{ $reunion->client->nom }} »
                        ({{ $reunion->client->siteOrigine?->nom ?? 'site inconnu' }})
                    </div>
                    <div>
                        Nom saisi ensuite : « {{ $reunion->nom_propose }} »
                        ({{ $reunion->site?->nom ?? 'site inconnu' }}, le {{ Affichage::date($reunion->created_at) }})
                    </div>
                </div>
                <div class="formulaire-actions">
                    <form method="POST" action="{{ route('clients.trancher', $reunion) }}">
                        @csrf
                        <input type="hidden" name="nom" value="conserve">
                        <button type="submit" class="bouton bouton-secondaire bouton-petit">Garder « {{ $reunion->client->nom }} »</button>
                    </form>
                    <form method="POST" action="{{ route('clients.trancher', $reunion) }}">
                        @csrf
                        <input type="hidden" name="nom" value="propose">
                        <button type="submit" class="bouton bouton-secondaire bouton-petit">Retenir « {{ $reunion->nom_propose }} »</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="tout-va-bien" style="margin-top:10px">✓ Aucune fiche en double n'attend votre décision.</p>
        @endforelse
    </section>

    @if ($tranchees->isNotEmpty())
        <section class="carte">
            <h2>Dernières décisions</h2>
            <div class="tableau-cadre">
                <table class="tableau">
                    <thead><tr><th>Tranché le</th><th>Téléphone</th><th>Nom de la fiche</th><th>Nom écarté ou repris</th><th>Site du doublon</th></tr></thead>
                    <tbody>
                    @foreach ($tranchees as $reunion)
                        <tr>
                            <td class="serre">{{ Affichage::dateHeure($reunion->tranche_le) }}</td>
                            <td class="serre">{{ $reunion->client->telephone }}</td>
                            <td>{{ $reunion->client->nom }}</td>
                            <td>{{ $reunion->nom_propose }}</td>
                            <td>{{ $reunion->site?->nom }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection

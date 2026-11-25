@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Lot')

@section('titre', 'Lots')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Lots</h1>
            <p>
                Les transports entre les boutiques et l'atelier. Un lot sans accusé de réception {{ $delai }} heures
                après sa clôture vous est signalé, comme tout écart entre le nombre annoncé et le nombre reçu.
            </p>
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('lots.index') }}" class="filtres">
            <div class="champ">
                <label for="etat">État</label>
                <select id="etat" name="etat">
                    <option value="">Tous</option>
                    @foreach ($etats as $code => $libelle)
                        <option value="{{ $code }}" @selected(($filtres['etat'] ?? '') === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div class="champ">
                <label for="site">Site</label>
                <select id="site" name="site">
                    <option value="">Tous</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected(($filtres['site'] ?? '') === $site->id)>{{ $site->nom }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bouton bouton-secondaire">Filtrer</button>
            @if (array_filter($filtres))
                <a class="bouton bouton-secondaire" href="{{ route('lots.index') }}">Tout afficher</a>
            @endif
        </form>

        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Lot</th>
                    <th>Sens</th>
                    <th>Trajet</th>
                    <th>Clôturé le</th>
                    <th class="nombre">Annoncé</th>
                    <th class="nombre">Reçu</th>
                    <th>État</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($lots as $lot)
                    <tr>
                        <td class="serre"><a href="{{ route('lots.show', $lot) }}"><code>{{ Affichage::referenceLot($lot->id) }}</code></a></td>
                        <td>{{ $lot->sens === Lot::RETOUR ? 'Retour' : 'Aller' }}</td>
                        <td>{{ $lot->siteOrigine?->nom }} → {{ $lot->siteDestination?->nom }}</td>
                        <td class="serre">
                            {{ Affichage::dateHeure($lot->cloture_le) }}
                            <div class="secondaire">par {{ $lot->cloturePar?->nom ?? '—' }}</div>
                        </td>
                        <td class="nombre">{{ $lot->nombre_annonce }}</td>
                        <td class="nombre">{{ $lot->nombre_recu ?? '—' }}</td>
                        <td>
                            @if ($lot->recu_le === null)
                                @if ($lot->cloture_le->lt($limiteAlerte))
                                    <span class="pastille pastille-grave">▲ Sans accusé de réception</span>
                                    <div class="secondaire">clôturé {{ Affichage::depuis($lot->cloture_le) }}</div>
                                @else
                                    <span class="pastille pastille-info">En route</span>
                                @endif
                            @elseif ($lot->aUnEcart())
                                <span class="pastille pastille-grave">▲ Écart de {{ abs($lot->nombre_recu - $lot->nombre_annonce) }}</span>
                                <div class="secondaire">reçu le {{ Affichage::dateHeure($lot->recu_le) }}</div>
                            @else
                                <span class="pastille pastille-bon">Reçu</span>
                                <div class="secondaire">le {{ Affichage::dateHeure($lot->recu_le) }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide">Aucun lot ne correspond.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $lots->links('promoteur.partials.pagination') }}
    </section>
@endsection

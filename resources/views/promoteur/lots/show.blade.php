@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Lot')

@section('titre', 'Lot '.Affichage::referenceLot($lot->id))

@section('contenu')
    <header class="entete">
        <div>
            <div class="fil"><a href="{{ route('lots.index') }}">Lots</a> ›</div>
            <h1>Lot <span class="code">{{ Affichage::referenceLot($lot->id) }}</span></h1>
            <p>
                {{ $lot->sens === Lot::RETOUR ? 'Lot retour' : 'Lot aller' }} ·
                {{ $lot->siteOrigine?->nom }} → {{ $lot->siteDestination?->nom }}
            </p>
        </div>
    </header>

    @if ($enRetard)
        <div class="message message-erreur" role="alert">
            <strong>▲ Ce lot n'a pas d'accusé de réception plus de {{ $delai }} heures après sa clôture.</strong>
            Vérifiez auprès du site de départ et du site d'arrivée où il se trouve.
        </div>
    @elseif ($lot->aUnEcart())
        <div class="message message-erreur" role="alert">
            <strong>▲ Écart de comptage :</strong>
            {{ $lot->nombre_annonce }} {{ $lot->nombre_annonce > 1 ? 'commandes annoncées' : 'commande annoncée' }},
            {{ $lot->nombre_recu }} {{ $lot->nombre_recu > 1 ? 'reçues' : 'reçue' }}.
        </div>
    @endif

    <section class="carte">
        <h2>Transport</h2>
        <dl class="fiche" style="margin-top:10px">
            <dt>Clôturé le</dt>
            <dd>{{ Affichage::dateHeure($lot->cloture_le) }} par {{ $lot->cloturePar?->nom ?? '—' }}</dd>
            <dt>Nombre annoncé</dt>
            <dd>{{ $lot->nombre_annonce }}</dd>
            <dt>Reçu le</dt>
            <dd>
                @if ($lot->recu_le)
                    {{ Affichage::dateHeure($lot->recu_le) }} par {{ $lot->recuPar?->nom ?? '—' }}
                @else
                    Pas encore reçu
                @endif
            </dd>
            <dt>Nombre reçu</dt>
            <dd>{{ $lot->nombre_recu ?? '—' }}</dd>
        </dl>
    </section>

    <section class="carte">
        <h2>Contenu</h2>
        <p class="sous-titre">Figé à la clôture du lot.</p>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead><tr><th>Code</th><th>Client</th><th>Statut actuel</th><th class="nombre">Montant</th></tr></thead>
                <tbody>
                @foreach ($commandes as $commande)
                    <tr>
                        <td class="serre"><a href="{{ route('commandes.show', $commande) }}"><code>{{ $commande->code }}</code></a></td>
                        <td>{{ $commande->client?->nom }}</td>
                        <td><span class="pastille {{ Affichage::classeStatut($commande->statut) }}">{{ Affichage::statut($commande->statut) }}</span></td>
                        <td class="nombre">{{ Affichage::fcfa($commande->montant) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection

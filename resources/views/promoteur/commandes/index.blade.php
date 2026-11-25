@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Commandes')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Commandes</h1>
            <p>Toutes les commandes que les postes ont synchronisées. Leur enregistrement et leur suivi se font sur les sites.</p>
            @if ($filtres['trop_percu'] ?? false)
                <p><strong>Seules les commandes dont le client a versé plus que la facture sont montrées :</strong>
                    le site doit lui rendre la différence (reste à payer négatif).</p>
            @endif
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('commandes.index') }}" class="filtres">
            <div class="champ champ-large">
                <label for="recherche">Code, client ou téléphone</label>
                <input type="search" id="recherche" name="recherche" value="{{ $filtres['recherche'] ?? '' }}" maxlength="100">
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
            <div class="champ">
                <label for="statut">Statut</label>
                <select id="statut" name="statut">
                    <option value="">Tous</option>
                    @foreach (Affichage::statuts() as $code => $libelle)
                        <option value="{{ $code }}" @selected(($filtres['statut'] ?? '') === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div class="champ">
                <label for="du">Déposées du</label>
                <input type="date" id="du" name="du" value="{{ $filtres['du'] ?? '' }}">
            </div>
            <div class="champ">
                <label for="au">au</label>
                <input type="date" id="au" name="au" value="{{ $filtres['au'] ?? '' }}">
            </div>
            @if ($filtres['trop_percu'] ?? false)
                {{-- filtre venu du tableau de bord : il reste actif tant qu'on ne demande pas tout --}}
                <input type="hidden" name="trop_percu" value="1">
            @endif
            <button type="submit" class="bouton bouton-secondaire">Filtrer</button>
            @if (array_filter($filtres))
                <a class="bouton bouton-secondaire" href="{{ route('commandes.index') }}">Tout afficher</a>
            @endif
        </form>

        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Site</th>
                    <th>Client</th>
                    <th>Déposée le</th>
                    <th>Statut</th>
                    <th class="nombre">Montant</th>
                    <th class="nombre">Payé</th>
                    <th class="nombre">Reste</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($commandes as $commande)
                    <tr>
                        <td class="serre"><a href="{{ route('commandes.show', $commande) }}"><code>{{ $commande->code }}</code></a></td>
                        <td>{{ $commande->site?->nom }}</td>
                        <td>
                            {{ $commande->client?->nom }}
                            <div class="secondaire">{{ $commande->client?->telephone }}</div>
                        </td>
                        <td class="serre">{{ Affichage::dateHeure($commande->deposee_le) }}</td>
                        <td><span class="pastille {{ Affichage::classeStatut($commande->statut) }}">{{ Affichage::statut($commande->statut) }}</span></td>
                        <td class="nombre">{{ Affichage::nombre($commande->montant) }}</td>
                        <td class="nombre">{{ Affichage::nombre($commande->total_paye) }}</td>
                        <td class="nombre">{{ Affichage::nombre($commande->reste) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="vide">Aucune commande ne correspond.</td></tr>
                @endforelse
                </tbody>
                @if ($totaux['nombre'] > 0)
                    <tfoot>
                    <tr>
                        <td colspan="5">
                            {{ $totaux['nombre'] }} {{ $totaux['nombre'] > 1 ? 'commandes' : 'commande' }} au total (FCFA)
                        </td>
                        <td class="nombre">{{ Affichage::nombre($totaux['montant']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($totaux['paye']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($totaux['reste']) }}</td>
                    </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        {{ $commandes->links('promoteur.partials.pagination') }}
    </section>
@endsection

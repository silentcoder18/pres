@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Poste')
@use('App\Models\Site')

@section('titre', 'Sites et postes')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Sites et postes</h1>
            <p>
                Les boutiques et l'atelier du réseau, avec l'ordinateur qui les relie au serveur. C'est ici que vous générez
                le code de mise en service d'un poste. Un site ne se supprime pas : il se désactive.
            </p>
        </div>
    </header>

    <section class="carte">
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Site</th>
                    <th>Type</th>
                    <th>État</th>
                    <th>Poste</th>
                    <th>Dernier contact</th>
                    <th class="nombre">Comptes</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($sites as $site)
                    @php $poste = $site->postes->firstWhere('revoque_le', null) ?? $site->postes->first(); @endphp
                    <tr>
                        <td><code>{{ $site->code }}</code></td>
                        <td>
                            <a href="{{ route('sites.show', $site) }}">{{ $site->nom }}</a>
                            @if ($site->adresse)<div class="secondaire">{{ $site->adresse }}</div>@endif
                        </td>
                        <td>{{ Affichage::typeSite($site->type) }}</td>
                        <td>
                            @if ($site->actif)
                                <span class="pastille pastille-bon">Actif</span>
                            @else
                                <span class="pastille pastille-terne">Désactivé</span>
                            @endif
                        </td>
                        <td>@include('promoteur.partials.etat-poste', ['poste' => $poste])</td>
                        <td class="serre">
                            @if ($poste && $poste->etat() === Poste::EN_SERVICE)
                                {{ Affichage::depuis($poste->dernier_contact_le) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="nombre">{{ $site->utilisateurs_count }}</td>
                        <td class="serre">
                            @if ($site->actif && $site->postes->where('revoque_le', null)->isEmpty())
                                {{-- Le code de mise en service nait ici, d'un clic ; il s'affiche sur la page du site --}}
                                <form method="POST" action="{{ route('postes.store', $site) }}" style="display:inline">
                                    @csrf
                                    <button type="submit" class="bouton bouton-petit">Générer le code de mise en service</button>
                                </form>
                            @endif
                            <a class="bouton bouton-secondaire bouton-petit" href="{{ route('sites.show', $site) }}">Ouvrir</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="vide">Aucun site pour l'instant. Créez l'atelier et les boutiques ci-dessous.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="carte">
        <h2>Nouveau site</h2>
        <p class="sous-titre">Le code précède chaque code de commande du site (par exemple « CV-S41-10-2026-84AK ») : il ne change plus ensuite.</p>
        <form method="POST" action="{{ route('sites.store') }}" class="formulaire">
            @csrf
            <div class="formulaire-ligne">
                <div class="champ">
                    <label for="code">Code</label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}" maxlength="10" required
                           autocapitalize="characters" placeholder="CV">
                    <small>2 à 10 lettres ou chiffres</small>
                </div>
                <div class="champ">
                    <label for="type">Type</label>
                    <select id="type" name="type" required>
                        <option value="{{ Site::BOUTIQUE }}" @selected(old('type') !== Site::ATELIER)>Boutique</option>
                        <option value="{{ Site::ATELIER }}" @selected(old('type') === Site::ATELIER)>Atelier</option>
                    </select>
                </div>
            </div>
            <div class="champ">
                <label for="nom">Nom</label>
                <input type="text" id="nom" name="nom" value="{{ old('nom') }}" maxlength="150" required placeholder="Centre-Ville">
            </div>
            <div class="champ">
                <label for="adresse">Adresse</label>
                <input type="text" id="adresse" name="adresse" value="{{ old('adresse') }}" maxlength="255">
            </div>
            <div class="formulaire-actions">
                <button type="submit" class="bouton">Créer le site</button>
            </div>
        </form>
    </section>
@endsection

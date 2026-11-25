@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Catalogue et tarifs')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Catalogue et tarifs</h1>
            <p>
                Ce que vous changez ici part vers les postes à leur prochaine synchronisation.
                Une commande déjà enregistrée garde le prix du jour de son dépôt.
            </p>
        </div>
    </header>

    <nav class="onglets" aria-label="Parties du catalogue">
        <a class="onglet @if ($onglet === 'tarifs') actif @endif" href="{{ route('catalogue.index', ['onglet' => 'tarifs']) }}">Tarifs ({{ $tarifs->count() }})</a>
        <a class="onglet @if ($onglet === 'articles') actif @endif" href="{{ route('catalogue.index', ['onglet' => 'articles']) }}">Articles ({{ $articles->count() }})</a>
        <a class="onglet @if ($onglet === 'prestations') actif @endif" href="{{ route('catalogue.index', ['onglet' => 'prestations']) }}">Prestations ({{ $services->count() }})</a>
    </nav>

    @if ($onglet === 'tarifs')
        <section class="carte">
            <h2>Tarifs</h2>
            <p class="sous-titre">
                Un prix de vente par couple article et prestation, et la commission de chaque étape : le travailleur qui lave
                touche celle du lavage, celui qui repasse celle du repassage. Modifiez une ligne puis enregistrez-la.
            </p>
            <div class="tableau-cadre">
                <table class="tableau">
                    <thead>
                    <tr>
                        <th>Article</th>
                        <th>Prestation</th>
                        <th class="nombre">Prix de vente (FCFA)</th>
                        <th class="nombre">Commission du lavage (FCFA)</th>
                        <th class="nombre">Commission du repassage (FCFA)</th>
                        <th>État</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($tarifs as $tarif)
                        @php $formulaire = 'tarif-'.$tarif->id; @endphp
                        <tr>
                            <td>
                                {{ $tarif->article->nom }}
                                @unless ($tarif->article->actif)<span class="pastille pastille-terne">article retiré</span>@endunless
                            </td>
                            <td>
                                {{ $tarif->service->nom }}
                                @unless ($tarif->service->actif)<span class="pastille pastille-terne">prestation retirée</span>@endunless
                            </td>
                            <td class="nombre">
                                <input class="prix" type="number" name="prix_vente" form="{{ $formulaire }}" min="0" step="1" required
                                       value="{{ (int) $tarif->prix_vente }}" aria-label="Prix de vente de {{ $tarif->article->nom }}, {{ $tarif->service->nom }}">
                            </td>
                            @foreach (['lavage' => $tarif->service->avec_lavage, 'repassage' => $tarif->service->avec_repassage] as $etape => $comprise)
                                <td class="nombre">
                                    @if ($comprise)
                                        <input class="prix" type="number" name="commission_{{ $etape }}" form="{{ $formulaire }}" min="0" step="1" required
                                               value="{{ (int) $tarif->{'commission_'.$etape} }}"
                                               aria-label="Commission du {{ $etape }} de {{ $tarif->article->nom }}, {{ $tarif->service->nom }}">
                                    @else
                                        <input type="hidden" name="commission_{{ $etape }}" form="{{ $formulaire }}" value="0">
                                        <span class="secondaire">pas de {{ $etape }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td>
                                <select name="actif" form="{{ $formulaire }}" aria-label="État du tarif">
                                    <option value="1" @selected($tarif->actif)>En vente</option>
                                    <option value="0" @selected(! $tarif->actif)>Retiré</option>
                                </select>
                            </td>
                            <td class="serre">
                                <form id="{{ $formulaire }}" method="POST" action="{{ route('catalogue.tarifs.update', $tarif) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="bouton bouton-secondaire bouton-petit">Enregistrer</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="vide">Aucun tarif. Créez d'abord les articles et les prestations, puis leurs tarifs.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="carte">
            <h2>Nouveau tarif</h2>
            @if ($articles->where('actif', true)->isEmpty() || $services->where('actif', true)->isEmpty())
                <p class="sous-titre">Il faut au moins un article et une prestation en vente pour créer un tarif.</p>
            @else
                <form method="POST" action="{{ route('catalogue.tarifs.store') }}" class="formulaire">
                    @csrf
                    <div class="formulaire-ligne">
                        <div class="champ">
                            <label for="article_id">Article</label>
                            <select id="article_id" name="article_id" required>
                                @foreach ($articles->where('actif', true) as $article)
                                    <option value="{{ $article->id }}" @selected(old('article_id') === $article->id)>{{ $article->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="champ">
                            <label for="service_id">Prestation</label>
                            <select id="service_id" name="service_id" required>
                                @foreach ($services->where('actif', true) as $service)
                                    <option value="{{ $service->id }}" @selected(old('service_id') === $service->id)>{{ $service->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="formulaire-ligne">
                        <div class="champ">
                            <label for="prix_vente">Prix de vente (FCFA)</label>
                            <input type="number" id="prix_vente" name="prix_vente" value="{{ old('prix_vente') }}" min="0" step="1" required>
                        </div>
                        <div class="champ">
                            <label for="commission_lavage">Commission du lavage (FCFA)</label>
                            <input type="number" id="commission_lavage" name="commission_lavage" value="{{ old('commission_lavage', 0) }}" min="0" step="1" required>
                        </div>
                        <div class="champ">
                            <label for="commission_repassage">Commission du repassage (FCFA)</label>
                            <input type="number" id="commission_repassage" name="commission_repassage" value="{{ old('commission_repassage', 0) }}" min="0" step="1" required>
                        </div>
                    </div>
                    <p class="sous-titre">
                        Une étape que la prestation ne comprend pas ne rapporte rien, quel que soit le montant saisi.
                        Ensemble, les deux commissions ne dépassent pas le prix de vente.
                    </p>
                    <div class="formulaire-actions">
                        <button type="submit" class="bouton">Créer le tarif</button>
                    </div>
                </form>
            @endif
        </section>
    @else
        @php
            $articlesOuverts = $onglet === 'articles';
            $elements = $articlesOuverts ? $articles : $services;
            $routeModification = $articlesOuverts ? 'catalogue.articles.update' : 'catalogue.services.update';
            $routeCreation = $articlesOuverts ? 'catalogue.articles.store' : 'catalogue.services.store';
            $singulier = $articlesOuverts ? 'article' : 'prestation';
        @endphp
        <section class="carte">
            <h2>{{ $articlesOuverts ? 'Articles' : 'Prestations' }}</h2>
            <p class="sous-titre">
                {{ $articlesOuverts
                    ? "Les pièces de linge que les boutiques enregistrent. Un article retiré n'est plus proposé à la saisie."
                    : "Les traitements proposés, et les étapes que chacun comprend : lavage, repassage, ou les deux. Une prestation retirée n'est plus proposée à la saisie." }}
            </p>
            <div class="tableau-cadre">
                <table class="tableau">
                    <thead>
                    <tr><th>Nom</th><th>Description</th>@unless ($articlesOuverts)<th>Étapes</th>@endunless<th>État</th><th></th></tr>
                    </thead>
                    <tbody>
                    @forelse ($elements as $element)
                        @php $formulaire = $singulier.'-'.$element->id; @endphp
                        <tr>
                            <td><input type="text" name="nom" form="{{ $formulaire }}" value="{{ $element->nom }}" maxlength="100" required aria-label="Nom"></td>
                            <td><input type="text" name="description" form="{{ $formulaire }}" value="{{ $element->description }}" maxlength="255" aria-label="Description de {{ $element->nom }}"></td>
                            @unless ($articlesOuverts)
                                <td class="serre">
                                    <input type="hidden" name="etapes_envoyees" form="{{ $formulaire }}" value="1">
                                    <label><input type="checkbox" name="etapes[]" value="LAVAGE" form="{{ $formulaire }}" @checked($element->avec_lavage)> Lavage</label>
                                    <label><input type="checkbox" name="etapes[]" value="REPASSAGE" form="{{ $formulaire }}" @checked($element->avec_repassage)> Repassage</label>
                                </td>
                            @endunless
                            <td>
                                <select name="actif" form="{{ $formulaire }}" aria-label="État de {{ $element->nom }}">
                                    <option value="1" @selected($element->actif)>En vente</option>
                                    <option value="0" @selected(! $element->actif)>{{ $articlesOuverts ? 'Retiré' : 'Retirée' }}</option>
                                </select>
                            </td>
                            <td class="serre">
                                <form id="{{ $formulaire }}" method="POST" action="{{ route($routeModification, $element) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="bouton bouton-secondaire bouton-petit">Enregistrer</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $articlesOuverts ? 4 : 5 }}" class="vide">{{ $articlesOuverts ? 'Aucun article.' : 'Aucune prestation.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="carte">
            <h2>{{ $articlesOuverts ? 'Nouvel article' : 'Nouvelle prestation' }}</h2>
            <form method="POST" action="{{ route($routeCreation) }}" class="formulaire">
                @csrf
                <div class="champ">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" value="{{ old('nom') }}" maxlength="100" required
                           placeholder="{{ $articlesOuverts ? 'Chemise' : 'Nettoyage à sec' }}">
                </div>
                <div class="champ">
                    <label for="description">Description</label>
                    <input type="text" id="description" name="description" value="{{ old('description') }}" maxlength="255">
                </div>
                @unless ($articlesOuverts)
                    <fieldset class="champ">
                        <legend>Étapes de l'article</legend>
                        <input type="hidden" name="etapes_envoyees" value="1">
                        <label><input type="checkbox" name="etapes[]" value="LAVAGE" @checked(in_array('LAVAGE', old('etapes', ['LAVAGE', 'REPASSAGE']), true))> Lavage</label>
                        <label><input type="checkbox" name="etapes[]" value="REPASSAGE" @checked(in_array('REPASSAGE', old('etapes', ['LAVAGE', 'REPASSAGE']), true))> Repassage</label>
                        <small>Un article est lavé puis repassé ; chaque étape est confiée à un travailleur qui sait la faire.</small>
                    </fieldset>
                @endunless
                <div class="formulaire-actions">
                    <button type="submit" class="bouton">{{ $articlesOuverts ? "Créer l'article" : 'Créer la prestation' }}</button>
                </div>
            </form>
        </section>
    @endif
@endsection

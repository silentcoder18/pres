@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', "Journal d'audit")

@section('contenu')
    <header class="entete">
        <div>
            <h1>Journal d'audit</h1>
            <p>Chaque action faite dans cet espace y laisse une ligne : qui, quand, sur quoi. Une ligne ne se modifie ni ne se supprime.</p>
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('journal.index') }}" class="filtres">
            <div class="champ">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">Toutes</option>
                    @foreach (Affichage::actions() as $code => $libelle)
                        <option value="{{ $code }}" @selected(($filtres['action'] ?? '') === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bouton bouton-secondaire">Filtrer</button>
            @if (array_filter($filtres))
                <a class="bouton bouton-secondaire" href="{{ route('journal.index') }}">Tout afficher</a>
            @endif
        </form>

        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr><th>Date</th><th>Auteur</th><th>Action</th><th>Sur</th><th>Détail</th></tr>
                </thead>
                <tbody>
                @forelse ($lignes as $ligne)
                    <tr>
                        <td class="serre">{{ Affichage::dateHeure($ligne->cree_le) }}</td>
                        <td>{{ $ligne->auteur }}</td>
                        <td>{{ Affichage::action($ligne->action) }}</td>
                        <td>{{ $ligne->objet_libelle ?? '—' }}</td>
                        <td class="secondaire">{{ $ligne->detail }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucune ligne.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $lignes->links('promoteur.partials.pagination') }}
    </section>
@endsection

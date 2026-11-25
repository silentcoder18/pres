@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Travailleurs')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Travailleurs</h1>
            <p>
                Enrôlés par le chef d'atelier sur le poste de l'atelier. Leurs données d'identité ne se consultent
                qu'ici. Ce que chacun a gagné porte sur la {{ mb_strtolower($semaine->libelle()) }}
                ({{ $semaine->periode() }}) : les tâches terminées dans la semaine.
            </p>
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('travailleurs.index') }}" class="filtres">
            <div class="champ champ-large">
                <label for="recherche">Nom, matricule ou téléphone</label>
                <input type="search" id="recherche" name="recherche" value="{{ $filtres['recherche'] ?? '' }}" maxlength="100">
            </div>
            <button type="submit" class="bouton bouton-secondaire">Rechercher</button>
            @if (array_filter($filtres))
                <a class="bouton bouton-secondaire" href="{{ route('travailleurs.index') }}">Tout afficher</a>
            @endif
        </form>

        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Matricule</th>
                    <th>Nom</th>
                    <th>Pièce d'identité</th>
                    <th>Téléphone</th>
                    <th>Quartier</th>
                    <th>Enrôlé le</th>
                    <th class="nombre">Articles terminés</th>
                    <th class="nombre">Commission de la semaine</th>
                    <th class="nombre">Avances remises</th>
                    <th class="nombre">Reste à payer</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($travailleurs as $travailleur)
                    <tr>
                        <td class="serre"><code>{{ $travailleur->matricule }}</code></td>
                        <td>
                            {{ $travailleur->nom }}
                            <div class="secondaire">{{ Affichage::roleTravailleur($travailleur->role) }} · {{ $travailleur->site?->nom }}</div>
                        </td>
                        <td class="serre">{{ $travailleur->cni ?: '—' }}</td>
                        <td class="serre">{{ $travailleur->telephone ?: '—' }}</td>
                        <td>{{ $travailleur->quartier ?: '—' }}</td>
                        <td class="serre">
                            {{ Affichage::date($travailleur->enrole_le) }}
                            <div class="secondaire">par {{ $travailleur->creePar?->nom ?? '—' }}</div>
                        </td>
                        <td class="nombre">{{ $articles[$travailleur->id] ?? 0 }}</td>
                        <td class="nombre">{{ Affichage::fcfa($commissions[$travailleur->id] ?? 0) }}</td>
                        <td class="nombre">{{ Affichage::fcfa($avances[$travailleur->id] ?? 0) }}</td>
                        <td class="nombre">{{ Affichage::fcfa(bcsub((string) ($commissions[$travailleur->id] ?? '0'), (string) ($avances[$travailleur->id] ?? '0'), 2)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="vide">Aucun travailleur ne correspond. Ils sont enrôlés sur le poste de l'atelier.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $travailleurs->links('promoteur.partials.pagination') }}
    </section>
@endsection

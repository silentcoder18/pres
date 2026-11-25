@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Écritures refusées')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Écritures refusées</h1>
            <p>
                Ce qu'un poste a envoyé et que le serveur n'a pas accepté. L'opération a bien eu lieu sur le site,
                mais elle ne figure pas dans les comptes du réseau : voyez avec le site ce qu'il s'est passé.
            </p>
        </div>
    </header>

    <section class="carte">
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr><th>Reçue le</th><th>Site</th><th>Poste</th><th>Nature</th><th>Motif du refus</th></tr>
                </thead>
                <tbody>
                @forelse ($ecritures as $ecriture)
                    <tr>
                        <td class="serre">{{ Affichage::dateHeure($ecriture->recu_le) }}</td>
                        {{-- le site est celui pour lequel la personne travaillait, pas forcement celui du poste --}}
                        <td>{{ $ecriture->sessionPoste?->site?->nom ?? $ecriture->poste?->site?->nom ?? '—' }}</td>
                        <td>
                            {{ $ecriture->poste?->nom ?? '—' }}
                            @if ($ecriture->sessionPoste?->utilisateur)
                                <div class="secondaire">par {{ $ecriture->sessionPoste->utilisateur->nom }}</div>
                            @endif
                        </td>
                        <td>{{ Affichage::table($ecriture->table_cible) }}</td>
                        <td>{{ Affichage::motif($ecriture->motif) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucune écriture refusée : tout ce que les postes ont envoyé a été accepté.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $ecritures->links('promoteur.partials.pagination') }}
    </section>
@endsection

@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Utilisateur')

@section('titre', 'Comptes')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Comptes</h1>
            <p>Vous seul créez un compte, le désactivez ou réinitialisez son mot de passe. Un compte a un seul rôle, fixé à sa création.</p>
        </div>
        <div class="entete-actions">
            <a class="bouton" href="{{ route('comptes.create') }}">Nouveau compte</a>
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('comptes.index') }}" class="filtres">
            <div class="champ">
                <label for="role">Rôle</label>
                <select id="role" name="role">
                    <option value="">Tous</option>
                    @foreach (Utilisateur::ROLES as $role)
                        <option value="{{ $role }}" @selected(($filtres['role'] ?? '') === $role)>{{ Affichage::role($role) }}</option>
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
        </form>

        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Site</th><th>Téléphone</th><th>État</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($comptes as $compte)
                    <tr>
                        <td>{{ $compte->nom }}</td>
                        <td><code>{{ $compte->identifiant }}</code></td>
                        <td>{{ Affichage::role($compte->role) }}</td>
                        <td>{{ $compte->site?->nom ?? '—' }}</td>
                        <td class="serre">{{ $compte->telephone ?: '—' }}</td>
                        <td>
                            @if ($compte->actif)
                                <span class="pastille pastille-bon">Actif</span>
                            @else
                                <span class="pastille pastille-terne">Désactivé</span>
                            @endif
                        </td>
                        <td class="serre"><a class="bouton bouton-secondaire bouton-petit" href="{{ route('comptes.edit', $compte) }}">Modifier</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide">Aucun compte ne correspond.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

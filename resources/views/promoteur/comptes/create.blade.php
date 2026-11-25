@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Utilisateur')

@section('titre', 'Nouveau compte')

@section('contenu')
    <header class="entete">
        <div>
            <div class="fil"><a href="{{ route('comptes.index') }}">Comptes</a> ›</div>
            <h1>Nouveau compte</h1>
            <p>
                Un compte peut se connecter sur n'importe quel poste du réseau. Sa première connexion sur un poste se fait
                avec Internet ; les suivantes fonctionnent sans connexion.
            </p>
        </div>
    </header>

    <section class="carte">
        <form method="POST" action="{{ route('comptes.store') }}" class="formulaire" autocomplete="off">
            @csrf
            <div class="formulaire-ligne">
                <div class="champ">
                    <label for="nom">Nom complet</label>
                    <input type="text" id="nom" name="nom" value="{{ old('nom') }}" maxlength="150" required>
                </div>
                <div class="champ">
                    <label for="telephone">Téléphone</label>
                    <input type="text" id="telephone" name="telephone" value="{{ old('telephone') }}" maxlength="30">
                </div>
            </div>
            <div class="formulaire-ligne">
                <div class="champ">
                    <label for="role">Rôle</label>
                    <select id="role" name="role" required>
                        @foreach ([Utilisateur::RECEPTIONNISTE, Utilisateur::CHEF_ATELIER, Utilisateur::PROMOTEUR] as $role)
                            <option value="{{ $role }}" @selected(old('role') === $role)>{{ Affichage::role($role) }}</option>
                        @endforeach
                    </select>
                    <small>Le rôle ne se change plus ensuite.</small>
                </div>
                <div class="champ">
                    <label for="site_id">Site de rattachement</label>
                    <select id="site_id" name="site_id">
                        <option value="">— aucun (Promoteur) —</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected(old('site_id') === $site->id)>
                                {{ $site->nom }} ({{ Affichage::typeSite($site->type) }})
                            </option>
                        @endforeach
                    </select>
                    <small>Son site habituel : une boutique pour une réceptionniste, l'atelier pour un chef d'atelier. Il ne limite pas les postes où le compte peut se connecter.</small>
                </div>
            </div>
            <div class="champ">
                <label for="identifiant">Identifiant de connexion</label>
                <input type="text" id="identifiant" name="identifiant" value="{{ old('identifiant') }}" maxlength="100" required
                       autocapitalize="none" autocomplete="off">
                <small>Sans espace ni accent ; par exemple « aicha.bello » ou une adresse de courriel. C'est avec lui que la personne se connecte sur le poste de son site.</small>
            </div>
            <div class="formulaire-ligne">
                <div class="champ">
                    <label for="mot_de_passe">Mot de passe</label>
                    <input type="password" id="mot_de_passe" name="mot_de_passe" required autocomplete="new-password">
                    <small>Au moins 8 caractères, dont une lettre et un chiffre.</small>
                </div>
                <div class="champ">
                    <label for="mot_de_passe_confirmation">Mot de passe, une seconde fois</label>
                    <input type="password" id="mot_de_passe_confirmation" name="mot_de_passe_confirmation" required autocomplete="new-password">
                </div>
            </div>
            <div class="formulaire-actions">
                <button type="submit" class="bouton">Créer le compte</button>
                <a class="bouton bouton-secondaire" href="{{ route('comptes.index') }}">Annuler</a>
            </div>
        </form>
    </section>
@endsection

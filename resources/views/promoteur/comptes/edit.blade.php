@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Utilisateur')

@section('titre', $compte->nom)

@section('contenu')
    <header class="entete">
        <div>
            <div class="fil"><a href="{{ route('comptes.index') }}">Comptes</a> ›</div>
            <h1>{{ $compte->nom }}</h1>
            <p>
                {{ Affichage::role($compte->role) }} · identifiant <code class="code">{{ $compte->identifiant }}</code>
                @if ($compte->is(auth()->user())) · c'est votre compte @endif
            </p>
        </div>
    </header>

    <div class="colonnes">
        <section class="carte">
            <h2>Fiche du compte</h2>
            <p class="sous-titre">
                @if ($compte->role === Utilisateur::PROMOTEUR)
                    Un Promoteur n'est rattaché à aucun site.
                @else
                    Désactiver le compte ferme son accès sur tous les postes, chacun dès sa prochaine synchronisation.
                @endif
            </p>
            <form method="POST" action="{{ route('comptes.update', $compte) }}" class="formulaire">
                @csrf
                @method('PUT')
                <div class="champ">
                    <label for="nom">Nom complet</label>
                    <input type="text" id="nom" name="nom" value="{{ old('nom', $compte->nom) }}" maxlength="150" required>
                </div>
                <div class="champ">
                    <label for="telephone">Téléphone</label>
                    <input type="text" id="telephone" name="telephone" value="{{ old('telephone', $compte->telephone) }}" maxlength="30">
                </div>
                @if ($compte->role !== Utilisateur::PROMOTEUR)
                    <div class="champ">
                        <label for="site_id">Site de rattachement</label>
                        <select id="site_id" name="site_id" required>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected(old('site_id', $compte->site_id) === $site->id)>
                                    {{ $site->nom }} ({{ Affichage::typeSite($site->type) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="champ">
                    <label for="actif">État</label>
                    <select id="actif" name="actif">
                        <option value="1" @selected(old('actif', (int) $compte->actif) == 1)>Actif</option>
                        <option value="0" @selected(old('actif', (int) $compte->actif) == 0)>Désactivé</option>
                    </select>
                </div>
                <div class="formulaire-actions">
                    <button type="submit" class="bouton">Enregistrer</button>
                </div>
            </form>
        </section>

        <section class="carte">
            <h2>Réinitialiser le mot de passe</h2>
            <p class="sous-titre">
                @if ($compte->role === Utilisateur::PROMOTEUR)
                    Le nouveau mot de passe sert dès la prochaine connexion à cet espace.
                @else
                    La prochaine connexion sur le poste devra se faire avec Internet.
                @endif
            </p>
            <form method="POST" action="{{ route('comptes.mot-de-passe', $compte) }}" class="formulaire" autocomplete="off"
                  data-confirmer="Remplacer le mot de passe de ce compte ?">
                @csrf
                @method('PUT')
                <div class="champ">
                    <label for="mot_de_passe">Nouveau mot de passe</label>
                    <input type="password" id="mot_de_passe" name="mot_de_passe" required autocomplete="new-password">
                    <small>Au moins 8 caractères, dont une lettre et un chiffre.</small>
                </div>
                <div class="champ">
                    <label for="mot_de_passe_confirmation">Nouveau mot de passe, une seconde fois</label>
                    <input type="password" id="mot_de_passe_confirmation" name="mot_de_passe_confirmation" required autocomplete="new-password">
                </div>
                <div class="formulaire-actions">
                    <button type="submit" class="bouton bouton-secondaire">Réinitialiser le mot de passe</button>
                </div>
            </form>
        </section>
    </div>
@endsection

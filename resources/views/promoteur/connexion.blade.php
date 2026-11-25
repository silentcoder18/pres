<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connexion — GePressing</title>
    <link rel="stylesheet" href="{{ asset('css/promoteur.css') }}?v={{ filemtime(public_path('css/promoteur.css')) }}">
</head>
<body>
<main class="connexion">
    <div class="connexion-carte">
        <img src="{{ asset('images/logo-visuelplus.png') }}" alt="VisuelPlus Pressing" width="190" height="75">
        <h1>Espace du Promoteur</h1>

        @if ($errors->any())
            <div class="message message-erreur" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('connexion') }}" class="formulaire">
            @csrf
            <div class="champ">
                <label for="identifiant">Identifiant</label>
                <input type="text" id="identifiant" name="identifiant" value="{{ old('identifiant') }}"
                       autocomplete="username" autocapitalize="none" required autofocus>
            </div>
            <div class="champ">
                <label for="mot_de_passe">Mot de passe</label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="current-password" required>
            </div>
            <button type="submit" class="bouton">Se connecter</button>
        </form>

        <p class="connexion-note">
            Les réceptionnistes et le chef d'atelier se connectent sur le poste de leur site.
        </p>
    </div>
</main>
</body>
</html>

{{-- Page autonome : elle s'affiche avant toute session, quand la base n'est pas a niveau. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Mise à niveau nécessaire — GePressing</title>
    <link rel="stylesheet" href="{{ asset('css/promoteur.css') }}">
</head>
<body>
<main class="connexion">
    <div class="connexion-carte">
        <img src="{{ asset('images/logo-visuelplus.png') }}" alt="VisuelPlus Pressing" width="190" height="75">
        <h1>Mise à niveau nécessaire</h1>
        <div class="message message-erreur" role="alert">{{ $message }}</div>
        <p>
            Le programme du serveur a été mis à jour, mais pas encore sa base de données.
            Sur l'ordinateur du serveur, fermez la fenêtre du serveur puis relancez <strong>run-serveur.bat</strong> :
            il met la base à niveau avant de démarrer.
        </p>
        <p class="connexion-note">
            Ou, depuis le dossier « server » : <span class="code">php artisan migrate --force --seed</span>
        </p>
    </div>
</main>
</body>
</html>

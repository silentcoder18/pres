{{-- Gabarit des pages du Promoteur : menu a gauche, page a droite. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre') — GePressing</title>
    <link rel="stylesheet" href="{{ asset('css/promoteur.css') }}?v={{ filemtime(public_path('css/promoteur.css')) }}">
</head>
<body>
<div class="cadre">
    <nav class="menu" aria-label="Menu principal">
        <a class="menu-marque" href="{{ route('tableau-de-bord') }}">
            <img src="{{ asset('images/logo-visuelplus.png') }}" alt="VisuelPlus Pressing" width="170" height="67">
        </a>

        <div class="menu-liens">
            @php
                // Un compteur dit ce qui attend : une decision du Promoteur, ou seulement son regard
                $lien = fn (string $route, string $texte, string $motif, ?int $compteur = null, string $bulle = 'À trancher') => [
                    'url' => route($route), 'texte' => $texte, 'actif' => request()->routeIs($motif),
                    'compteur' => $compteur, 'bulle' => $bulle,
                ];
                $groupes = [
                    'Suivi' => [
                        $lien('tableau-de-bord', 'Tableau de bord', 'tableau-de-bord'),
                        $lien('commandes.index', 'Commandes', 'commandes.*'),
                        $lien('accords.index', "En attente d'accord", 'accords.*', $menuAccords, "En attente de l'accord du client"),
                        $lien('annulations.index', "Demandes d'annulation", 'annulations.*', $menuAnnulations),
                        $lien('lots.index', 'Lots', 'lots.*'),
                        $lien('caisses.index', 'Caisses des sites', 'caisses.*'),
                        $lien('finances.index', 'Finances', 'finances.*'),
                        $lien('messages.index', 'Messages WhatsApp', 'messages.*'),
                    ],
                    'Réseau' => [
                        $lien('sites.index', 'Sites et postes', 'sites.*'),
                        $lien('comptes.index', 'Comptes', 'comptes.*'),
                        $lien('clients.index', 'Clients', 'clients.*', $menuDoublons),
                        $lien('travailleurs.index', 'Travailleurs', 'travailleurs.*'),
                        $lien('ecritures.index', 'Écritures refusées', 'ecritures.*'),
                    ],
                    'Réglages' => [
                        $lien('catalogue.index', 'Catalogue et tarifs', 'catalogue.*'),
                        $lien('parametres.index', 'Paramètres', 'parametres.*'),
                        $lien('journal.index', "Journal d'audit", 'journal.*'),
                    ],
                ];
            @endphp
            @foreach ($groupes as $titre => $liens)
                <div class="menu-titre">{{ $titre }}</div>
                @foreach ($liens as $l)
                    <a class="menu-lien @if ($l['actif']) actif @endif" href="{{ $l['url'] }}" @if ($l['actif']) aria-current="page" @endif>
                        <span>{{ $l['texte'] }}</span>
                        @if ($l['compteur'])
                            <span class="menu-compteur" title="{{ $l['bulle'] }}">{{ $l['compteur'] }}</span>
                        @endif
                    </a>
                @endforeach
            @endforeach
        </div>

        <div class="menu-pied">
            <div>
                <strong>{{ auth()->user()->nom }}</strong>
                <span>Promoteur</span>
            </div>
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button type="submit" class="bouton bouton-menu bouton-petit">Se déconnecter</button>
            </form>
        </div>
    </nav>

    <main class="page">
        @include('promoteur.partials.messages')
        @yield('contenu')
    </main>
</div>

<script>
    // Une action sans retour possible demande confirmation : data-confirmer porte la question
    document.addEventListener('submit', function (evenement) {
        var question = evenement.target.getAttribute('data-confirmer');
        if (question && !window.confirm(question)) {
            evenement.preventDefault();
        }
    });
</script>
</body>
</html>

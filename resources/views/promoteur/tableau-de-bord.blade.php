@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Tableau de bord')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Tableau de bord du réseau</h1>
            <p>
                Situation au {{ $maintenant->format('d/m/Y à H:i') }}, d'après ce que les postes ont synchronisé.
                Un site hors ligne n'apparaît à jour qu'après sa prochaine synchronisation.
            </p>
        </div>
    </header>

    <section class="tuiles" aria-label="Chiffres clés">
        <div class="tuile tuile-principale">
            <div class="tuile-libelle">Encaissé aujourd'hui</div>
            <div class="tuile-valeur">{{ Affichage::nombre($chiffres['encaisse_jour']) }} <small>FCFA</small></div>
            <div class="tuile-note">avances et soldes, moins les avances restituées</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Encaissé cette semaine</div>
            <div class="tuile-valeur">{{ Affichage::nombre($chiffres['encaisse_semaine']) }} <small>FCFA</small></div>
            <div class="tuile-note">
                depuis le lundi {{ Affichage::date($debutSemaine) }} ·
                <a href="{{ route('finances.index') }}">Finances de la semaine →</a>
            </div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Reste à recouvrer</div>
            <div class="tuile-valeur">{{ Affichage::nombre($chiffres['reste_a_recouvrer']) }} <small>FCFA</small></div>
            <div class="tuile-note">sur les commandes en cours</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Commandes en cours</div>
            <div class="tuile-valeur">{{ Affichage::nombre($chiffres['commandes_en_cours']) }}</div>
            <div class="tuile-note">ni livrées, ni annulées, ni données</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Lots en route</div>
            <div class="tuile-valeur">{{ Affichage::nombre($chiffres['lots_en_route']) }}</div>
            <div class="tuile-note">clôturés, pas encore reçus</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Caisses ouvertes</div>
            <div class="tuile-valeur">{{ Affichage::nombre($chiffres['caisses_ouvertes']) }}</div>
            <div class="tuile-note">sessions du jour non clôturées</div>
        </div>
    </section>

    <section class="carte">
        <div class="carte-entete">
            <h2>À traiter</h2>
        </div>
        @if ($alertes === [])
            <p class="tout-va-bien">✓ Rien à signaler : aucun lot en retard, aucun écart, aucune demande ni fiche à trancher.</p>
        @else
            <ul class="alertes">
                @foreach ($alertes as $alerte)
                    <li class="alerte alerte-{{ $alerte['niveau'] }}">
                        <span class="alerte-texte">
                            <span class="alerte-signe" aria-hidden="true">{{ $alerte['niveau'] === 'grave' ? '▲' : '●' }}</span>
                            <span>{{ $alerte['niveau'] === 'grave' ? 'Urgent' : 'À voir' }} — {{ $alerte['texte'] }}</span>
                        </span>
                        <a class="bouton bouton-secondaire bouton-petit" href="{{ $alerte['lien'] }}">{{ $alerte['action'] }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="carte">
        <div class="carte-entete">
            <h2>Par site</h2>
            <a href="{{ route('sites.index') }}">Sites et postes →</a>
        </div>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Site</th>
                    <th class="nombre">Commandes en cours</th>
                    <th class="nombre">À envoyer</th>
                    <th class="nombre">À l'atelier</th>
                    <th class="nombre">À retirer</th>
                    <th class="nombre">Encaissé aujourd'hui</th>
                    <th>Caisse</th>
                    <th>Dernier contact du poste</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($sites as $ligne)
                    <tr>
                        <td>
                            <a href="{{ route('sites.show', $ligne['site']) }}">{{ $ligne['site']->nom }}</a>
                            <div class="secondaire">{{ Affichage::typeSite($ligne['site']->type) }} · {{ $ligne['site']->code }}</div>
                        </td>
                        <td class="nombre">
                            <a href="{{ route('commandes.index', ['site' => $ligne['site']->id]) }}">{{ $ligne['en_cours'] }}</a>
                        </td>
                        <td class="nombre">{{ $ligne['etapes']['a_envoyer'] }}</td>
                        <td class="nombre">{{ $ligne['etapes']['a_l_atelier'] }}</td>
                        <td class="nombre">{{ $ligne['etapes']['a_retirer'] }}</td>
                        <td class="nombre">{{ $ligne['encaisse_jour'] === null ? '—' : Affichage::fcfa($ligne['encaisse_jour']) }}</td>
                        <td class="serre">
                            @if ($ligne['caisse'])
                                <a href="{{ route('caisses.show', $ligne['caisse']) }}" class="pastille pastille-info">Ouverte</a>
                                <span class="secondaire">depuis le {{ Affichage::dateHeure($ligne['caisse']->ouverte_le) }}</span>
                            @else
                                <span class="pastille pastille-terne">Fermée</span>
                            @endif
                        </td>
                        <td class="serre">
                            @if ($ligne['poste'] === null)
                                <span class="pastille pastille-attention">Aucun poste</span>
                            @elseif ($ligne['poste']->etat() === \App\Models\Poste::EN_SERVICE)
                                {{ Affichage::depuis($ligne['poste']->dernier_contact_le) }}
                                <div class="secondaire">{{ Affichage::dateHeure($ligne['poste']->dernier_contact_le) }}</div>
                            @else
                                <span class="pastille pastille-attention">Pas encore mis en service</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="vide">Aucun site. Commencez par <a href="{{ route('sites.index') }}">créer les sites du réseau</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

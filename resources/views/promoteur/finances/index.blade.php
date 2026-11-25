@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Finances — '.$semaine->libelle())

@section('contenu')
    @php
        // Tout est calcule par App\Services\Finance\RapportHebdomadaire ; ici, seulement l'affichage
        $total = $rapport['total'];
        $lignes = $rapport['sites'];
        $plusieursSites = count($lignes) > 1;
        $adresse = fn (?string $iso) => route('finances.index', ['semaine' => $iso, 'site' => $site?->id]);
        $suivante = $semaine->suivante();
    @endphp

    <header class="entete">
        <div>
            <h1>Finances — {{ $semaine->libelle() }}</h1>
            <p>
                Du {{ Affichage::dateEnClair($semaine->debut()) }} au {{ Affichage::dateEnClair($semaine->dernierJour()) }} ·
                {{ $site ? $site->nom : 'tout le réseau' }}.
                Situation calculée par le serveur d'après ce que les postes ont synchronisé.
            </p>
        </div>
        <div class="entete-actions">
            <a class="bouton" href="{{ route('finances.rapport', ['semaine' => $semaine->iso(), 'site' => $site?->id]) }}">
                Télécharger le rapport (PDF)
            </a>
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('finances.index') }}" class="filtres">
            <div class="champ">
                <label for="semaine">Semaine</label>
                <select id="semaine" name="semaine">
                    @foreach ($semaines as $proposee)
                        <option value="{{ $proposee->iso() }}" @selected($proposee->egale($semaine))>
                            Semaine {{ $proposee->numero() }} — {{ $proposee->periode() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="champ">
                <label for="site">Site</label>
                <select id="site" name="site">
                    <option value="">Tout le réseau</option>
                    @foreach ($sites as $unSite)
                        <option value="{{ $unSite->id }}" @selected($site?->id === $unSite->id)>{{ $unSite->nom }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bouton bouton-secondaire">Afficher</button>
        </form>

        <nav class="semaines" aria-label="Changer de semaine">
            <a class="bouton bouton-secondaire bouton-petit" href="{{ $adresse($semaine->precedente()->iso()) }}" rel="prev">← Semaine précédente</a>
            @if ($semaine->estEnCours())
                <span class="bouton bouton-secondaire bouton-petit bouton-inactif" aria-current="true">Cette semaine</span>
            @else
                <a class="bouton bouton-secondaire bouton-petit" href="{{ $adresse(null) }}">Cette semaine</a>
            @endif
            @if ($suivante->estAVenir())
                <span class="bouton bouton-secondaire bouton-petit bouton-inactif" aria-disabled="true">Semaine suivante →</span>
            @else
                <a class="bouton bouton-secondaire bouton-petit" href="{{ $adresse($suivante->iso()) }}" rel="next">Semaine suivante →</a>
            @endif
        </nav>
    </section>

    @if ($semaine->estEnCours())
        <div class="message message-info">
            Semaine en cours : ces chiffres bougent encore. Ils sont arrêtés à la dernière synchronisation de chaque poste.
        </div>
    @elseif ($semaine->estAVenir())
        <div class="message message-info">Cette semaine n'a pas encore commencé.</div>
    @endif

    <section class="tuiles" aria-label="Chiffres clés de la semaine">
        <div class="tuile tuile-principale">
            <div class="tuile-libelle">Recette de la semaine</div>
            <div class="tuile-valeur">{{ Affichage::nombre($total['recette']) }} <small>FCFA</small></div>
            <div class="tuile-note">avances et soldes des commandes déposées cette semaine, hors sommes rendues aux clients</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Recouvrements tardifs</div>
            <div class="tuile-valeur">{{ Affichage::nombre($total['recouvrements_tardifs']) }} <small>FCFA</small></div>
            <div class="tuile-note">encaissés cette semaine sur des commandes de semaines antérieures</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Net encaissé</div>
            <div class="tuile-valeur">{{ Affichage::nombre($total['net_encaisse']) }} <small>FCFA</small></div>
            <div class="tuile-note">
                avances {{ Affichage::nombre($total['avances']) }} + soldes {{ Affichage::nombre($total['soldes']) }}
                − rendu aux clients {{ Affichage::nombre($total['restitutions']) }}
            </div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Reste à recouvrer</div>
            <div class="tuile-valeur">{{ Affichage::nombre($total['reste_semaine']) }} <small>FCFA</small></div>
            <div class="tuile-note">
                @if ($total['commandes_nombre'] === 0)
                    aucune commande déposée cette semaine ;
                @elseif ($total['commandes_nombre'] === 1)
                    sur la commande déposée cette semaine ;
                @else
                    sur les {{ $total['commandes_nombre'] }} commandes déposées cette semaine ;
                @endif
                {{ Affichage::fcfa($total['reste_total']) }} sur toutes les commandes en cours
            </div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Commissions prévues <span class="pastille pastille-attention">Prévision</span></div>
            <div class="tuile-valeur">{{ Affichage::nombre($total['commissions_prevues']) }} <small>FCFA</small></div>
            <div class="tuile-note">sur les articles déposés cette semaine ; les commissions dues sont dans « Charges de l'atelier »</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Solde prévisionnel <span class="pastille pastille-attention">Provisoire</span></div>
            <div class="tuile-valeur">{{ Affichage::nombre($total['solde_previsionnel']) }} <small>FCFA</small></div>
            <div class="tuile-note">
                {{ Affichage::sensSolde($total['solde_previsionnel']) }}, provisoire et partiel :
                recette de la semaine − commissions prévues ; charges réelles de l'atelier non déduites
            </div>
        </div>
    </section>

    <section class="carte">
        <div class="carte-entete">
            <h2>Par site — commandes déposées et encaissements</h2>
            @if ($site)
                <a href="{{ route('finances.index', ['semaine' => $semaine->iso()]) }}">Voir tout le réseau →</a>
            @endif
        </div>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Site</th>
                    <th class="nombre">Commandes déposées</th>
                    <th class="nombre">Montant facturé</th>
                    <th class="nombre">Avances reçues</th>
                    <th class="nombre">Soldes encaissés</th>
                    <th class="nombre">Rendu aux clients</th>
                    <th class="nombre">Net encaissé</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($lignes as $ligne)
                    <tr>
                        <td class="serre">
                            <a href="{{ route('finances.index', ['semaine' => $semaine->iso(), 'site' => $ligne['site']->id]) }}">{{ $ligne['site']->nom }}</a>
                            <div class="secondaire">{{ Affichage::typeSite($ligne['site']->type) }} · {{ $ligne['site']->code }}</div>
                        </td>
                        <td class="nombre">{{ $ligne['commandes_nombre'] }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne['montant_facture']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne['avances']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne['soldes']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne['restitutions']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne['net_encaisse']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide">Aucun site. Commencez par <a href="{{ route('sites.index') }}">créer les sites du réseau</a>.</td></tr>
                @endforelse
                </tbody>
                @if ($plusieursSites)
                    <tfoot>
                    <tr>
                        <td class="serre">Réseau entier (FCFA)</td>
                        <td class="nombre">{{ $total['commandes_nombre'] }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['montant_facture']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['avances']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['soldes']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['restitutions']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['net_encaisse']) }}</td>
                    </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <p class="legende">
            Montants en FCFA. Net encaissé = avances reçues + soldes encaissés − rendu aux clients.
            Rendu aux clients : l'avance reçue cette semaine sur une commande annulée depuis et rendue au client, quelle que soit
            la date de la restitution ; et ce qui a été rendu cette semaine à un client qui avait versé plus que sa facture,
            celle-ci ayant baissé après une correction.
        </p>
    </section>

    <section class="carte">
        <div class="carte-entete">
            <h2>Par site — recette, recouvrement et prévision</h2>
        </div>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Site</th>
                    <th class="nombre">Recette de la semaine</th>
                    <th class="nombre">Recouvrements tardifs</th>
                    <th class="nombre">Commissions prévues</th>
                    <th class="nombre">Solde prévisionnel</th>
                    <th class="nombre">Reste à recouvrer (semaine)</th>
                    <th class="nombre">Reste à recouvrer (total en cours)</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($lignes as $ligne)
                    <tr>
                        <td class="serre">
                            {{ $ligne['site']->nom }}
                            <div class="secondaire">{{ Affichage::typeSite($ligne['site']->type) }} · {{ $ligne['site']->code }}</div>
                        </td>
                        <td class="nombre">{{ Affichage::nombre($ligne['recette']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne['recouvrements_tardifs']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne['commissions_prevues']) }}</td>
                        <td class="nombre">
                            {{ Affichage::nombre($ligne['solde_previsionnel']) }}
                            <div class="secondaire">{{ Affichage::sensSolde($ligne['solde_previsionnel']) }}</div>
                        </td>
                        <td class="nombre">{{ Affichage::nombre($ligne['reste_semaine']) }}</td>
                        <td class="nombre">
                            <a href="{{ route('commandes.index', ['site' => $ligne['site']->id]) }}">{{ Affichage::nombre($ligne['reste_total']) }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide">Aucun site.</td></tr>
                @endforelse
                </tbody>
                @if ($plusieursSites)
                    <tfoot>
                    <tr>
                        <td class="serre">Réseau entier (FCFA)</td>
                        <td class="nombre">{{ Affichage::nombre($total['recette']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['recouvrements_tardifs']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['commissions_prevues']) }}</td>
                        <td class="nombre">
                            {{ Affichage::nombre($total['solde_previsionnel']) }}
                            <div class="secondaire">{{ Affichage::sensSolde($total['solde_previsionnel']) }}</div>
                        </td>
                        <td class="nombre">{{ Affichage::nombre($total['reste_semaine']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($total['reste_total']) }}</td>
                    </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <p class="legende">
            Montants en FCFA. Recette de la semaine + recouvrements tardifs = net encaissé.
            Commissions et solde sont des prévisions : voir l'encadré en bas de page.
            Reste à recouvrer total : commandes ni livrées, ni annulées, ni données, au {{ $rapport['calcule_le']->format('d/m/Y à H:i') }}.
        </p>
    </section>

    @include('promoteur.finances.partials.charges')

    @include('promoteur.finances.partials.exploitation')

    <section class="carte">
        <div class="carte-entete">
            <h2>Par jour</h2>
        </div>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Jour</th>
                    <th class="nombre">Commandes déposées</th>
                    <th class="nombre">Montant facturé</th>
                    <th class="nombre">Avances reçues</th>
                    <th class="nombre">Soldes encaissés</th>
                    <th class="nombre">Rendu aux clients</th>
                    <th class="nombre">Net encaissé</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rapport['jours'] as $jour)
                    <tr>
                        <td class="serre">{{ Affichage::jour($jour['date']) }}</td>
                        <td class="nombre">{{ $jour['commandes_nombre'] }}</td>
                        <td class="nombre">{{ Affichage::nombre($jour['montant_facture']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($jour['avances']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($jour['soldes']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($jour['restitutions']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($jour['net_encaisse']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                @php $totalJours = $rapport['total_jours']; @endphp
                <tfoot>
                <tr>
                    <td class="serre">Total des journées (FCFA)</td>
                    <td class="nombre">{{ $totalJours['commandes_nombre'] }}</td>
                    <td class="nombre">{{ Affichage::nombre($totalJours['montant_facture']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($totalJours['avances']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($totalJours['soldes']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($totalJours['restitutions']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($totalJours['net_encaisse']) }}</td>
                </tr>
                </tfoot>
            </table>
        </div>
        <p class="legende">
            Montants en FCFA. Comptes de journée, comme ceux des caisses : une somme rendue au client y est déduite le jour où elle sort de la caisse.
            Dans les chiffres de la semaine, l'avance d'une commande annulée est reprise sur la semaine où elle avait été reçue :
            pour le rendu aux clients et le net encaissé, le total des journées et la semaine peuvent donc différer.
        </p>
    </section>

    <section class="carte">
        <div class="carte-entete">
            <h2>Caisses de la semaine</h2>
            <a href="{{ route('caisses.index', ['site' => $site?->id]) }}">Caisses des sites →</a>
        </div>
        @if ($rapport['caisses'] !== [])
            <p class="sous-titre">
                {{ $total['caisses_nombre'] }} {{ $total['caisses_nombre'] > 1 ? 'sessions ouvertes' : 'session ouverte' }} dans la semaine :
                {{ $total['caisses_cloturees'] }} {{ $total['caisses_cloturees'] > 1 ? 'clôturées' : 'clôturée' }},
                {{ $total['caisses_ouvertes'] }} encore {{ $total['caisses_ouvertes'] > 1 ? 'ouvertes' : 'ouverte' }}.
                Manques : {{ Affichage::fcfa($total['manques']) }}. Excédents : {{ Affichage::fcfa($total['excedents']) }}.
            </p>
        @endif
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Site</th>
                    <th>Ouverte le</th>
                    <th>Clôturée le</th>
                    <th class="nombre">Fond initial</th>
                    <th class="nombre">Encaissé</th>
                    <th class="nombre">Attendu</th>
                    <th class="nombre">Compté</th>
                    <th>Écart</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rapport['caisses'] as $caisse)
                    @php $session = $caisse['session']; @endphp
                    <tr>
                        <td class="serre">{{ $session->site?->nom }}</td>
                        <td class="serre">
                            {{ Affichage::dateHeure($session->ouverte_le) }}
                            <div class="secondaire">par {{ $session->ouvertePar?->nom ?? '—' }}</div>
                        </td>
                        <td class="serre">
                            @if ($session->estOuverte())
                                <span class="pastille pastille-info">Ouverte</span>
                            @else
                                {{ Affichage::dateHeure($session->cloturee_le) }}
                                <div class="secondaire">par {{ $session->clotureePar?->nom ?? '—' }}</div>
                            @endif
                        </td>
                        <td class="nombre">{{ Affichage::nombre($session->fond_initial) }}</td>
                        <td class="nombre">{{ Affichage::nombre($caisse['encaisse']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($caisse['attendu']) }}</td>
                        <td class="nombre">{{ $session->estOuverte() ? '—' : Affichage::nombre($session->total_compte) }}</td>
                        <td class="serre">@include('promoteur.partials.ecart-caisse', ['session' => $session])</td>
                        <td class="serre"><a class="bouton bouton-secondaire bouton-petit" href="{{ route('caisses.show', $session) }}">Détail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="vide">Aucune session de caisse n'a été ouverte cette semaine.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="carte">
        <div class="carte-entete">
            <h2>Recouvrements tardifs</h2>
        </div>
        <p class="sous-titre">
            Encaissements de la semaine sur des commandes déposées une semaine antérieure : ils ne comptent pas dans la recette de la semaine.
        </p>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Encaissé le</th>
                    <th>Site</th>
                    <th>Commande</th>
                    <th>Client</th>
                    <th>Nature</th>
                    <th>Semaine d'origine</th>
                    <th>Affectation</th>
                    <th class="nombre">Montant</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rapport['recouvrements'] as $recouvrement)
                    <tr>
                        <td class="serre">{{ Affichage::dateHeure($recouvrement['encaisse_le']) }}</td>
                        <td class="serre">{{ $recouvrement['site']?->nom }}</td>
                        <td class="serre">
                            @if ($recouvrement['commande'])
                                <a href="{{ route('commandes.show', $recouvrement['commande']) }}"><code>{{ $recouvrement['commande']->code }}</code></a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="serre">{{ $recouvrement['client'] }}</td>
                        <td>{{ Affichage::typePaiement($recouvrement['type']) }}</td>
                        <td class="serre">
                            <a href="{{ $adresse($recouvrement['semaine_origine']->iso()) }}">{{ $recouvrement['semaine_origine']->reference() }}</a>
                        </td>
                        <td class="serre">{{ Affichage::affectation($recouvrement['affectation']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($recouvrement['montant']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="vide">Aucun recouvrement tardif cette semaine.</td></tr>
                @endforelse
                </tbody>
                @if ($rapport['recouvrements'] !== [])
                    <tfoot>
                    <tr>
                        <td colspan="7">
                            {{ count($rapport['recouvrements']) }} {{ count($rapport['recouvrements']) > 1 ? 'recouvrements tardifs' : 'recouvrement tardif' }} (FCFA)
                        </td>
                        <td class="nombre">{{ Affichage::nombre($total['recouvrements_tardifs']) }}</td>
                    </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    <section class="encadre" aria-labelledby="titre-limites">
        <h2 id="titre-limites">Ce qui est provisoire ou pas encore compté</h2>
        @include('promoteur.finances.partials.limites')
    </section>
@endsection

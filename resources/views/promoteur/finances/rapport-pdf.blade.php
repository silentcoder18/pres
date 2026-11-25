{{-- Rapport financier hebdomadaire, mis en PDF par dompdf (format A4, portrait).
     Mise en page par tableaux : dompdf ne connait ni flex ni grid. Police DejaVu Sans, qui porte
     les accents et l'espace insecable des montants. Le pied de page (numeros) est pose par
     App\Services\Finance\RapportPdf. Aucun calcul ici : tout vient de RapportHebdomadaire. --}}
@use('App\Support\Affichage')
@php
    $semaine = $rapport['semaine'];
    $total = $rapport['total'];
    $perimetre = $rapport['perimetre'];
    $lignes = $rapport['sites'];
    $plusieursSites = count($lignes) > 1;
    // Un tableau par site court n'est pas coupe entre deux pages ; un long reseau, lui, doit pouvoir l'etre
    $tientSurUnePage = count($lignes) <= 12;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport financier — {{ $semaine->libelle() }}</title>
    <style>
        @page { margin: 13mm 14mm 17mm 14mm; }

        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.35; color: #16233A; }
        h1 { margin: 0 0 2pt; font-size: 15pt; color: #0F3A71; }
        h2 { margin: 13pt 0 4pt; padding-bottom: 2pt; font-size: 10.5pt; color: #0F3A71; border-bottom: 1pt solid #C3D0E3; page-break-after: avoid; }
        h3 { margin: 0 0 4pt; font-size: 9.5pt; color: #0F3A71; }
        p { margin: 0 0 4pt; }
        table { width: 100%; border-collapse: collapse; }
        tr { page-break-inside: avoid; }

        .entete { margin-bottom: 7pt; border-bottom: 2.5pt solid #E03E11; }
        .entete td { padding: 0 0 6pt; vertical-align: bottom; }
        .entete-logo { width: 48mm; }
        .entete-logo img { width: 44mm; }
        .marque { font-size: 13pt; font-weight: bold; color: #0F3A71; }
        .entete-titre { text-align: right; }
        .entete-semaine { font-size: 11pt; font-weight: bold; }

        .identite { margin-bottom: 6pt; }
        .identite th { width: 24mm; padding: 1.5pt 0; text-align: left; font-weight: normal; color: #4B5B76; vertical-align: top; }
        .identite td { padding: 1.5pt 0; font-weight: bold; }

        .avis { margin: 6pt 0; padding: 5pt 8pt; border: 0.75pt solid #C2D8F5; background: #E3EEFC; color: #134C92; }

        .synthese td { width: 33.33%; padding: 6pt 8pt; border: 0.75pt solid #C3D0E3; vertical-align: top; }
        .synthese .libelle { font-size: 8pt; color: #4B5B76; }
        .synthese .valeur { font-size: 14pt; font-weight: bold; white-space: nowrap; }
        .synthese .valeur span { font-size: 8pt; font-weight: normal; color: #4B5B76; }
        .synthese .note { font-size: 7pt; color: #4B5B76; }
        .provisoire { font-size: 7pt; font-weight: bold; color: #8A5200; }

        .chiffres { font-size: 8pt; }
        .chiffres th { padding: 4pt 5pt; text-align: left; font-size: 7.5pt; color: #0F3A71; background: #E8F0FB; border-bottom: 1pt solid #C3D0E3; vertical-align: bottom; }
        .chiffres td { padding: 3.5pt 5pt; border-bottom: 0.5pt solid #DCE5F2; vertical-align: top; }
        .chiffres .nombre { text-align: right; white-space: nowrap; }
        .chiffres .serre { white-space: nowrap; }
        .chiffres .total td { font-weight: bold; background: #F3F6FB; border-top: 1pt solid #0F3A71; border-bottom: 0; }
        .secondaire { font-size: 7pt; color: #4B5B76; }
        .legende { margin-top: 3pt; font-size: 7pt; color: #4B5B76; }
        .vide { padding: 6pt 0; color: #4B5B76; }

        /* Etats : le libelle dit tout, la couleur ne fait que l'accompagner */
        .pastille { font-weight: bold; white-space: nowrap; }
        .pastille-bon { color: #0B6B2B; }
        .pastille-attention { color: #8A5200; }
        .pastille-grave { color: #A3231C; }

        /* Un bloc court (titre, tableau, legende) passe entier a la page suivante plutot que d'etre coupe */
        .bloc-entier { page-break-inside: avoid; }

        .encadre { margin-top: 14pt; padding: 8pt 10pt; border: 1pt solid #F2D49A; background: #FFF8E8; page-break-inside: avoid; }
        .encadre ul { margin: 0; padding-left: 13pt; }
        .encadre li { margin-bottom: 3pt; }
    </style>
</head>
<body>
    <table class="entete">
        <tr>
            <td class="entete-logo">
                @if ($logo)
                    <img src="{{ $logo }}" alt="VisuelPlus Pressing">
                @else
                    <span class="marque">VisuelPlus Pressing</span>
                @endif
            </td>
            <td class="entete-titre">
                <h1>Rapport financier hebdomadaire</h1>
                <div class="entete-semaine">{{ $semaine->libelle() }}</div>
                <div>du {{ Affichage::dateEnClair($semaine->debut()) }} au {{ Affichage::dateEnClair($semaine->dernierJour()) }}</div>
            </td>
        </tr>
    </table>

    <table class="identite">
        <tr>
            <th>Périmètre</th>
            <td>
                @if ($perimetre)
                    {{ $perimetre->nom }} ({{ Affichage::typeSite($perimetre->type) }} {{ $perimetre->code }})
                @else
                    Réseau entier — {{ count($lignes) }} {{ count($lignes) > 1 ? 'sites' : 'site' }}
                @endif
            </td>
        </tr>
        <tr>
            <th>Édition</th>
            <td>Édité le {{ $rapport['calcule_le']->format('d/m/Y à H:i') }} par {{ $auteur }}</td>
        </tr>
    </table>

    @if ($semaine->estEnCours())
        <div class="avis">
            Semaine en cours : ces chiffres bougent encore. Ils sont arrêtés à la dernière synchronisation de chaque poste.
        </div>
    @elseif ($semaine->estAVenir())
        <div class="avis">Cette semaine n'a pas encore commencé.</div>
    @endif

    <div class="bloc-entier">
    <h2>Synthèse</h2>
    <table class="synthese">
        <tr>
            <td>
                <div class="libelle">Recette de la semaine</div>
                <div class="valeur">{{ Affichage::nombre($total['recette']) }} <span>FCFA</span></div>
                <div class="note">avances et soldes des commandes déposées cette semaine, hors sommes rendues aux clients</div>
            </td>
            <td>
                <div class="libelle">Recouvrements tardifs</div>
                <div class="valeur">{{ Affichage::nombre($total['recouvrements_tardifs']) }} <span>FCFA</span></div>
                <div class="note">encaissés cette semaine sur des commandes de semaines antérieures</div>
            </td>
            <td>
                <div class="libelle">Net encaissé</div>
                <div class="valeur">{{ Affichage::nombre($total['net_encaisse']) }} <span>FCFA</span></div>
                <div class="note">
                    avances {{ Affichage::nombre($total['avances']) }} + soldes {{ Affichage::nombre($total['soldes']) }}
                    − rendu aux clients {{ Affichage::nombre($total['restitutions']) }}
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="libelle">Reste à recouvrer</div>
                <div class="valeur">{{ Affichage::nombre($total['reste_semaine']) }} <span>FCFA</span></div>
                <div class="note">
                    @if ($total['commandes_nombre'] === 0)
                        aucune commande déposée cette semaine.
                    @elseif ($total['commandes_nombre'] === 1)
                        sur la commande déposée cette semaine, facturée {{ Affichage::fcfa($total['montant_facture']) }}.
                    @else
                        sur les {{ $total['commandes_nombre'] }} commandes déposées cette semaine, facturées {{ Affichage::fcfa($total['montant_facture']) }}.
                    @endif
                    Toutes semaines confondues : {{ Affichage::fcfa($total['reste_total']) }} sur les commandes en cours.
                </div>
            </td>
            <td>
                <div class="libelle">Commissions prévues</div>
                <div class="valeur">{{ Affichage::nombre($total['commissions_prevues']) }} <span>FCFA</span></div>
                <div class="note">
                    <span class="provisoire">Prévision.</span>
                    Sur les articles déposés cette semaine ; les commissions dues sont dans « Charges de l'atelier ».
                </div>
            </td>
            <td>
                <div class="libelle">Solde prévisionnel</div>
                <div class="valeur">{{ Affichage::nombre($total['solde_previsionnel']) }} <span>FCFA</span></div>
                <div class="note">
                    <span class="provisoire">Provisoire et partiel.</span>
                    {{ Affichage::sensSolde($total['solde_previsionnel']) }} :
                    recette de la semaine − commissions prévues ; charges réelles de l'atelier non déduites.
                </div>
            </td>
        </tr>
    </table>
    </div>

    @if (isset($rapport['sections_consolidees']))
        @php $sec = $rapport['sections_consolidees']; @endphp
        <h2>Indicateurs consolidés d'exploitation (4 Sections)</h2>
        <table class="synthese" style="margin-bottom: 10pt;">
            <tr>
                <td style="width: 25%;">
                    <div class="libelle"><strong>1. Activité Physique</strong></div>
                    <div style="font-size: 7.5pt; margin-top: 4pt; line-height: 1.4;">
                        • Commandes : <strong>{{ $sec['section_1_activite']['commandes_enregistrees'] }}</strong><br>
                        • Pièces traitées : <strong>{{ $sec['section_1_activite']['pieces_traitees'] }}</strong><br>
                        • Remises au client : <strong>{{ $sec['section_1_activite']['commandes_retirees'] }}</strong><br>
                        • En penderie depuis plus de 14 j au {{ $sec['reference']->format('d/m') }} : <strong class="{{ $sec['section_1_activite']['penderie_plus_14j'] > 0 ? 'pastille-attention' : '' }}">{{ $sec['section_1_activite']['penderie_plus_14j'] }}</strong>
                    </div>
                </td>
                <td style="width: 25%;">
                    <div class="libelle"><strong>2. Encaissements &amp; Créances</strong></div>
                    <div style="font-size: 7.5pt; margin-top: 4pt; line-height: 1.4;">
                        • Boutiques : <strong>{{ Affichage::nombre($sec['section_2_creances']['encaisse_boutiques']) }}</strong><br>
                        • Atelier direct : <strong>{{ Affichage::nombre($sec['section_2_creances']['encaisse_atelier']) }}</strong><br>
                        • Linge prêt non retiré, reste dû : <strong>{{ Affichage::nombre($sec['section_2_creances']['creances_linge_pret']) }}</strong><br>
                        • Total à recouvrer : <strong>{{ Affichage::nombre($sec['section_2_creances']['creances_totales']) }}</strong><br>
                        <span style="color: #64748B;">Créances à la date du calcul.</span>
                    </div>
                </td>
                <td style="width: 25%;">
                    <div class="libelle"><strong>3. Charges d'Exploitation</strong></div>
                    <div style="font-size: 7.5pt; margin-top: 4pt; line-height: 1.4;">
                        • Laveurs : <strong>{{ Affichage::nombre($sec['section_3_charges']['commissions_laveurs']) }}</strong><br>
                        • Repasseurs : <strong>{{ Affichage::nombre($sec['section_3_charges']['commissions_repasseurs']) }}</strong><br>
                        • Polyvalents : <strong>{{ Affichage::nombre($sec['section_3_charges']['commissions_polyvalents']) }}</strong><br>
                        • Consommables : <strong>{{ Affichage::nombre($sec['section_3_charges']['consommables']) }}</strong><br>
                        • Autres achats : <strong>{{ Affichage::nombre($sec['section_3_charges']['achats']) }}</strong><br>
                        • Avances versées : <strong>{{ Affichage::nombre($sec['section_3_charges']['avances_commission']) }}</strong><br>
                        • Total charges : <strong>{{ Affichage::nombre($sec['section_3_charges']['total_charges']) }}</strong>
                    </div>
                </td>
                <td style="width: 25%;">
                    <div class="libelle"><strong>4. Marge &amp; Versements</strong></div>
                    <div style="font-size: 7.5pt; margin-top: 4pt; line-height: 1.4;">
                        • Net encaissé − charges :<br>
                        <strong style="font-size: 9pt; color: #0F3A71;">{{ Affichage::nombre($sec['section_4_marge']['marge_nette']) }} FCFA</strong><br>
                        • Versements déclarés ({{ $sec['section_4_marge']['versements_nombre'] }}) : <strong>{{ Affichage::nombre($sec['section_4_marge']['versements_declares']) }}</strong><br>
                        • Reçus à l'atelier : <strong>{{ Affichage::nombre($sec['section_4_marge']['versements_recus']) }}</strong>,
                        écart <strong class="{{ bccomp($sec['section_4_marge']['versements_ecart'], '0', 2) !== 0 ? 'pastille-attention' : '' }}">{{ Affichage::nombre($sec['section_4_marge']['versements_ecart']) }}</strong><br>
                        @if ($sec['section_4_marge']['versements_en_attente_nombre'] > 0)
                            • En route : <strong>{{ Affichage::nombre($sec['section_4_marge']['versements_en_attente']) }}</strong> ({{ $sec['section_4_marge']['versements_en_attente_nombre'] }})<br>
                        @endif
                        • Fonds de l'atelier : reçus <strong>{{ Affichage::nombre($sec['section_4_marge']['fonds_recus']) }}</strong>,
                        achats et avances <strong>{{ Affichage::nombre($sec['section_4_marge']['fonds_depenses']) }}</strong>,
                        reste <strong>{{ Affichage::nombre($sec['section_4_marge']['fonds_reste']) }}</strong><br>
                        • Écarts caisses : <strong>-{{ Affichage::nombre($sec['section_4_marge']['manques']) }} / +{{ Affichage::nombre($sec['section_4_marge']['excedents']) }}</strong>
                    </div>
                </td>
            </tr>
        </table>
    @endif

    <div @class(['bloc-entier' => $tientSurUnePage])>
    <h2>Par site — commandes déposées et encaissements</h2>
    <table class="chiffres">
        <thead>
        <tr>
            <th>Site</th>
            <th class="nombre">Commandes<br>déposées</th>
            <th class="nombre">Montant<br>facturé</th>
            <th class="nombre">Avances<br>reçues</th>
            <th class="nombre">Soldes<br>encaissés</th>
            <th class="nombre">Rendu aux<br>clients</th>
            <th class="nombre">Net<br>encaissé</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($lignes as $ligne)
            <tr>
                <td>
                    {{ $ligne['site']->nom }}
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
            <tr><td colspan="7" class="vide">Aucun site.</td></tr>
        @endforelse
        @if ($plusieursSites)
            <tr class="total">
                <td>Réseau entier</td>
                <td class="nombre">{{ $total['commandes_nombre'] }}</td>
                <td class="nombre">{{ Affichage::nombre($total['montant_facture']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['avances']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['soldes']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['restitutions']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['net_encaisse']) }}</td>
            </tr>
        @endif
        </tbody>
    </table>
    <div class="legende">
        Montants en FCFA. Net encaissé = avances reçues + soldes encaissés − rendu aux clients.
        Rendu aux clients : l'avance reçue cette semaine sur une commande annulée depuis et rendue au client, quelle que soit
        la date de la restitution ; et ce qui a été rendu cette semaine à un client qui avait versé plus que sa facture,
        celle-ci ayant baissé après une correction.
    </div>
    </div>

    <div @class(['bloc-entier' => $tientSurUnePage])>
    <h2>Par site — recette, recouvrement et prévision</h2>
    <table class="chiffres">
        <thead>
        <tr>
            <th>Site</th>
            <th class="nombre">Recette de<br>la semaine</th>
            <th class="nombre">Recouvrements<br>tardifs</th>
            <th class="nombre">Commissions<br>prévues</th>
            <th class="nombre">Solde<br>prévisionnel</th>
            <th class="nombre">Reste à recouvrer<br>(semaine)</th>
            <th class="nombre">Reste à recouvrer<br>(total en cours)</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($lignes as $ligne)
            <tr>
                <td>
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
                <td class="nombre">{{ Affichage::nombre($ligne['reste_total']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="vide">Aucun site.</td></tr>
        @endforelse
        @if ($plusieursSites)
            <tr class="total">
                <td>Réseau entier</td>
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
        @endif
        </tbody>
    </table>
    <div class="legende">
        Montants en FCFA. Recette de la semaine + recouvrements tardifs = net encaissé.
        Commissions et solde : prévisions, voir en fin de rapport.
        Reste à recouvrer total : commandes ni livrées, ni annulées, ni données, au {{ $rapport['calcule_le']->format('d/m/Y à H:i') }}.
    </div>
    </div>

    @php
        $avecCharges = collect($lignes)
            ->filter(fn ($ligne) => $ligne['site']->estAtelier() || bccomp($ligne['charges'], '0', 2) !== 0)
            ->values();
    @endphp
    <div class="bloc-entier">
    <h2>Charges de l'atelier</h2>
    <table class="chiffres">
        <thead>
        <tr>
            <th>Site</th>
            <th class="nombre">Commissions<br>dues</th>
            <th class="nombre">Consommables</th>
            <th class="nombre">Autres<br>achats</th>
            <th class="nombre">Total<br>des charges</th>
            <th class="nombre">Avances sur<br>commission</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($avecCharges as $ligne)
            <tr>
                <td>
                    {{ $ligne['site']->nom }}
                    <div class="secondaire">{{ Affichage::typeSite($ligne['site']->type) }} · {{ $ligne['site']->code }}</div>
                </td>
                <td class="nombre">{{ Affichage::nombre($ligne['commissions_dues']) }}</td>
                <td class="nombre">{{ Affichage::nombre($ligne['consommables']) }}</td>
                <td class="nombre">{{ Affichage::nombre($ligne['achats']) }}</td>
                <td class="nombre">{{ Affichage::nombre($ligne['charges']) }}</td>
                <td class="nombre">{{ Affichage::nombre($ligne['avances_commission']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="vide">Aucune charge d'atelier pour cette semaine.</td></tr>
        @endforelse
        @if ($avecCharges->count() > 1)
            <tr class="total">
                <td>Réseau entier</td>
                <td class="nombre">{{ Affichage::nombre($total['commissions_dues']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['consommables']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['achats']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['charges']) }}</td>
                <td class="nombre">{{ Affichage::nombre($total['avances_commission']) }}</td>
            </tr>
        @endif
        </tbody>
    </table>
    <div class="legende">
        Montants en FCFA. Commissions dues : celles des tâches terminées dans la semaine, quelle que soit la semaine de dépôt de la commande.
        Consommables et autres achats : saisis à l'atelier dans la semaine. Les avances sur commission se déduisent de ce qui reste à payer aux travailleurs ;
        elles ne s'ajoutent pas aux charges.
    </div>
    </div>

    <div class="bloc-entier">
    <h2>Par jour</h2>
    <table class="chiffres">
        <thead>
        <tr>
            <th>Jour</th>
            <th class="nombre">Commandes<br>déposées</th>
            <th class="nombre">Montant<br>facturé</th>
            <th class="nombre">Avances<br>reçues</th>
            <th class="nombre">Soldes<br>encaissés</th>
            <th class="nombre">Rendu aux<br>clients</th>
            <th class="nombre">Net<br>encaissé</th>
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
        @php $totalJours = $rapport['total_jours']; @endphp
        <tr class="total">
            <td>Total des journées</td>
            <td class="nombre">{{ $totalJours['commandes_nombre'] }}</td>
            <td class="nombre">{{ Affichage::nombre($totalJours['montant_facture']) }}</td>
            <td class="nombre">{{ Affichage::nombre($totalJours['avances']) }}</td>
            <td class="nombre">{{ Affichage::nombre($totalJours['soldes']) }}</td>
            <td class="nombre">{{ Affichage::nombre($totalJours['restitutions']) }}</td>
            <td class="nombre">{{ Affichage::nombre($totalJours['net_encaisse']) }}</td>
        </tr>
        </tbody>
    </table>
    <div class="legende">
        Montants en FCFA. Les jours sont ceux du réseau ({{ Affichage::fuseau() }}).
        Comptes de journée, comme ceux des caisses : une somme rendue au client y est déduite le jour où elle sort de la caisse.
        Dans les chiffres de la semaine, l'avance d'une commande annulée est reprise sur la semaine où elle avait été reçue :
        pour le rendu aux clients et le net encaissé, le total des journées et la semaine peuvent donc différer.
    </div>
    </div>

    <h2>Caisses de la semaine</h2>
    @if ($rapport['caisses'] === [])
        <p class="vide">Aucune session de caisse n'a été ouverte cette semaine.</p>
    @else
        <p>
            {{ $total['caisses_nombre'] }} {{ $total['caisses_nombre'] > 1 ? 'sessions ouvertes' : 'session ouverte' }} dans la semaine :
            {{ $total['caisses_cloturees'] }} {{ $total['caisses_cloturees'] > 1 ? 'clôturées' : 'clôturée' }},
            {{ $total['caisses_ouvertes'] }} encore {{ $total['caisses_ouvertes'] > 1 ? 'ouvertes' : 'ouverte' }}.
            Manques : {{ Affichage::fcfa($total['manques']) }}. Excédents : {{ Affichage::fcfa($total['excedents']) }}.
        </p>
        <table class="chiffres">
            <thead>
            <tr>
                <th>Site</th>
                <th>Ouverte le</th>
                <th>Clôturée le</th>
                <th class="nombre">Fond<br>initial</th>
                <th class="nombre">Encaissé</th>
                <th class="nombre">Attendu</th>
                <th class="nombre">Compté</th>
                <th>Écart</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rapport['caisses'] as $caisse)
                @php $session = $caisse['session']; @endphp
                <tr>
                    <td>{{ $session->site?->nom }}</td>
                    <td class="serre">
                        {{ Affichage::dateHeure($session->ouverte_le) }}
                        <div class="secondaire">par {{ $session->ouvertePar?->nom ?? '—' }}</div>
                    </td>
                    <td class="serre">
                        @if ($session->estOuverte())
                            Encore ouverte
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
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="legende">Montants en FCFA. Attendu = fond initial + encaissé ; écart = compté − attendu.</div>
    @endif

    <h2>Recouvrements tardifs</h2>
    @if ($rapport['recouvrements'] === [])
        <p class="vide">Aucun encaissement de la semaine ne porte sur une commande d'une semaine antérieure.</p>
    @else
        <table class="chiffres">
            <thead>
            <tr>
                <th>Encaissé le</th>
                <th>Site</th>
                <th>Commande</th>
                <th>Client</th>
                <th>Nature</th>
                <th>Semaine<br>d'origine</th>
                <th>Affectation</th>
                <th class="nombre">Montant</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rapport['recouvrements'] as $recouvrement)
                <tr>
                    <td class="serre">{{ Affichage::dateHeure($recouvrement['encaisse_le']) }}</td>
                    <td class="serre">{{ $recouvrement['site']?->nom }}</td>
                    <td class="serre">{{ $recouvrement['commande']?->code }}</td>
                    <td>{{ $recouvrement['client'] }}</td>
                    <td>{{ Affichage::typePaiement($recouvrement['type']) }}</td>
                    <td class="serre">{{ $recouvrement['semaine_origine']->reference() }}</td>
                    <td>{{ Affichage::affectation($recouvrement['affectation']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($recouvrement['montant']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="7">
                    {{ count($rapport['recouvrements']) }} {{ count($rapport['recouvrements']) > 1 ? 'recouvrements tardifs' : 'recouvrement tardif' }}
                </td>
                <td class="nombre">{{ Affichage::nombre($total['recouvrements_tardifs']) }}</td>
            </tr>
            </tbody>
        </table>
        <div class="legende">Montants en FCFA. La semaine d'origine est celle du dépôt de la commande.</div>
    @endif

    <div class="encadre">
        <h3>Ce que ce rapport ne compte pas encore</h3>
        @include('promoteur.finances.partials.limites')
    </div>
</body>
</html>

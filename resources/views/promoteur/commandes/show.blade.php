@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Commande '.$commande->code)

@section('contenu')
    <header class="entete">
        <div>
            <div class="fil"><a href="{{ route('commandes.index') }}">Commandes</a> ›</div>
            <h1><span class="code">{{ $commande->code }}</span></h1>
            <p>
                <span class="pastille {{ Affichage::classeStatut($commande->statut) }}">{{ Affichage::statut($commande->statut) }}</span>
                · déposée le {{ Affichage::dateHeure($commande->deposee_le) }} à {{ $commande->site?->nom }}
            </p>
        </div>
    </header>

    <div class="colonnes">
        <section class="carte">
            <h2>Commande</h2>
            <dl class="fiche" style="margin-top:10px">
                <dt>Client</dt>
                <dd>{{ $commande->client?->nom }} · {{ $commande->client?->telephone }}</dd>
                <dt>Quartier</dt>
                <dd>{{ $commande->client?->quartier ?: '—' }}</dd>
                <dt>Site</dt>
                <dd><a href="{{ route('sites.show', $commande->site_id) }}">{{ $commande->site?->nom }}</a></dd>
                <dt>Enregistrée par</dt>
                <dd>{{ $commande->utilisateur?->nom ?? '—' }}</dd>
                @if ($commande->livree_le)
                    <dt>Remise au client</dt>
                    <dd>le {{ Affichage::dateHeure($commande->livree_le) }} par {{ $commande->livreePar?->nom ?? '—' }}</dd>
                @endif
                <dt>Messages WhatsApp</dt>
                <dd>{{ $commande->accord_messages ? 'Le client a donné son accord' : "Le client n'a pas donné son accord" }}</dd>
            </dl>
        </section>

        <section class="carte">
            <h2>Parcours</h2>
            <p class="sous-titre">Les lots qui ont transporté la commande entre la boutique et l'atelier.</p>
            <div class="tableau-cadre">
                <table class="tableau">
                    <thead><tr><th>Lot</th><th>Trajet</th><th>Clôturé le</th><th>Reçu le</th></tr></thead>
                    <tbody>
                    @forelse ($commande->lots as $lot)
                        <tr>
                            <td class="serre"><a href="{{ route('lots.show', $lot) }}"><code>{{ Affichage::referenceLot($lot->id) }}</code></a></td>
                            <td>{{ $lot->siteOrigine?->nom }} → {{ $lot->siteDestination?->nom }}</td>
                            <td class="serre">{{ Affichage::dateHeure($lot->cloture_le) }}</td>
                            <td class="serre">
                                @if ($lot->recu_le)
                                    {{ Affichage::dateHeure($lot->recu_le) }}
                                @else
                                    <span class="pastille pastille-info">En route</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="vide">La commande n'a pas encore quitté sa boutique.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="carte">
        <h2>Facture</h2>
        <p class="sous-titre">Prix figés au moment de l'enregistrement.</p>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Article</th>
                    <th>Prestation</th>
                    <th class="nombre">Quantité</th>
                    <th class="nombre">Prix unitaire</th>
                    <th class="nombre">Total</th>
                    <th>Remarque au dépôt</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($commande->lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->tarif?->article?->nom ?? 'Article retiré du catalogue' }}</td>
                        <td>{{ $ligne->tarif?->service?->nom ?? '—' }}</td>
                        <td class="nombre">{{ $ligne->quantite }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne->prix_applique) }}</td>
                        <td class="nombre">{{ Affichage::nombre($ligne->prix_applique * $ligne->quantite) }}</td>
                        <td>{{ $ligne->remarque ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="total-facture">
            <div><span>Montant</span><strong>{{ Affichage::fcfa($commande->montant) }}</strong></div>
            <div><span>Déjà payé</span><strong>{{ Affichage::fcfa($commande->total_paye) }}</strong></div>
            <div><span>Reste à payer</span><strong>{{ Affichage::fcfa($commande->reste) }}</strong></div>
        </div>
    </section>

    @if ($commande->demandesAnnulation->isNotEmpty())
        <section class="carte">
            <h2>Demandes d'annulation</h2>
            <p class="sous-titre">Faites par la boutique ; l'annulation n'est effective qu'après votre confirmation.</p>
            <div class="tableau-cadre">
                <table class="tableau">
                    <thead><tr><th>Demandée le</th><th style="min-width:200px">Motif</th><th style="min-width:200px">Décision</th></tr></thead>
                    <tbody>
                    @foreach ($commande->demandesAnnulation as $demande)
                        <tr>
                            <td class="serre">
                                {{ Affichage::dateHeure($demande->demandee_le) }}
                                <div class="secondaire">par {{ $demande->demandeePar?->nom ?? '—' }}</div>
                            </td>
                            <td>{{ $demande->motif }}</td>
                            <td>
                                <span class="pastille {{ Affichage::classeEtatDemande($demande->etat) }}">{{ Affichage::etatDemande($demande->etat) }}</span>
                                @if ($demande->estEnAttente())
                                    <div class="secondaire"><a href="{{ route('annulations.index') }}">Trancher la demande →</a></div>
                                @else
                                    <div class="secondaire">
                                        le {{ Affichage::dateHeure($demande->decidee_le) }} par {{ $demande->decideePar?->nom ?? '—' }}
                                        @if ($demande->motif_refus) · {{ $demande->motif_refus }} @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($commande->statut === \App\Models\Commande::ANNULEE)
                <p class="legende">
                    @if (bccomp((string) $commande->total_paye, '0', 2) > 0)
                        Commande annulée : le site doit encore restituer au client son avance de {{ Affichage::fcfa($commande->total_paye) }}.
                    @else
                        Commande annulée : aucune avance ne reste à restituer au client.
                    @endif
                </p>
            @endif
        </section>
    @endif

    @if ($commande->controles->isNotEmpty() || $commande->taches->isNotEmpty())
        <div class="colonnes">
            <section class="carte">
                <h2>Contrôle à l'atelier</h2>
                <p class="sous-titre">Articles présents contre articles enregistrés.</p>
                @forelse ($commande->controles as $controle)
                    <dl class="fiche" style="margin-top:10px">
                        <dt>Résultat</dt>
                        <dd>{{ Affichage::resultatControle($controle->resultat) }}</dd>
                        <dt>Contrôlée le</dt>
                        <dd>{{ Affichage::dateHeure($controle->controle_le) }} par {{ $controle->utilisateur?->nom ?? '—' }}</dd>
                        @if ($controle->etat)
                            <dt>Correction proposée</dt>
                            <dd>
                                {{ $controle->tarif?->article?->nom ?? 'Article' }} ({{ $controle->tarif?->service?->nom ?? '—' }}) :
                                {{ $controle->quantite_avant }} enregistré(s), {{ $controle->quantite_apres }} constaté(s)
                            </dd>
                            <dt>Suite donnée</dt>
                            <dd>
                                <span class="pastille {{ $controle->enAttenteDAccord() ? 'pastille-attention' : 'pastille-info' }}">{{ Affichage::etatCorrection($controle->etat) }}</span>
                                @if ($controle->decide_le)
                                    <div class="secondaire">
                                        le {{ Affichage::dateHeure($controle->decide_le) }} ·
                                        facture de {{ Affichage::fcfa($controle->montant_avant) }} à {{ Affichage::fcfa($controle->montant_apres) }}
                                        @if ($controle->decidePar) · accord enregistré par {{ $controle->decidePar->nom }} @endif
                                    </div>
                                @endif
                            </dd>
                        @endif
                        @if ($controle->remarque)
                            <dt>Remarque</dt>
                            <dd>{{ $controle->remarque }}</dd>
                        @endif
                    </dl>
                @empty
                    <p class="vide">Dépôt direct à l'atelier : le chef a reçu les articles en main propre.</p>
                @endforelse
            </section>

            <section class="carte">
                <h2>Tâches</h2>
                <p class="sous-titre">
                    Le traitement, travailleur par travailleur : chaque article est lavé, puis repassé.
                    @if ($commande->traitee_le) Commande traitée le {{ Affichage::dateHeure($commande->traitee_le) }}. @endif
                </p>
                <div class="tableau-cadre">
                    <table class="tableau">
                        <thead><tr><th>Travailleur</th><th>Article</th><th>Étape</th><th class="nombre">Quantité</th><th>État</th></tr></thead>
                        <tbody>
                        @forelse ($commande->taches as $tache)
                            <tr>
                                <td>
                                    {{ $tache->travailleur?->nom ?? '—' }}
                                    <div class="secondaire">{{ $tache->travailleur?->matricule }} · {{ Affichage::roleTravailleur($tache->travailleur?->role) }}</div>
                                </td>
                                <td>{{ $tache->ligne?->tarif?->article?->nom ?? 'Article' }}</td>
                                <td>{{ Affichage::etape($tache->etape) }}</td>
                                <td class="nombre">{{ $tache->quantite }}</td>
                                <td>
                                    @if ($tache->estTerminee())
                                        <span class="pastille pastille-bon">Terminée</span>
                                        <div class="secondaire">le {{ Affichage::dateHeure($tache->terminee_le) }}</div>
                                    @else
                                        <span class="pastille pastille-info">En cours</span>
                                        <div class="secondaire">depuis le {{ Affichage::dateHeure($tache->attribuee_le) }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="vide">Aucune tâche attribuée pour l'instant.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    @endif

    <div class="colonnes">
        <section class="carte">
            <h2>Encaissements</h2>
            <p class="sous-titre">En espèces, rattachés à une caisse du jour.</p>
            <div class="tableau-cadre">
                <table class="tableau">
                    <thead><tr><th>Date</th><th>Nature</th><th>Encaissé par</th><th class="nombre">Montant</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($commande->paiements as $paiement)
                        <tr>
                            <td class="serre">{{ Affichage::dateHeure($paiement->encaisse_le) }}</td>
                            <td>{{ Affichage::typePaiement($paiement->type) }}</td>
                            <td>{{ $paiement->utilisateur?->nom ?? '—' }}</td>
                            <td class="nombre">{{ Affichage::fcfa($paiement->montant) }}</td>
                            <td class="serre"><a href="{{ route('caisses.show', $paiement->session_caisse_id) }}">Caisse</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="vide">Aucun encaissement.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="carte">
            <h2>Messages au client</h2>
            <p class="sous-titre">Créés au moment de l'événement, sur le poste ou par le serveur.</p>
            @forelse ($commande->messages as $message)
                <details class="contenu" style="margin-bottom:8px">
                    <summary>
                        {{ Affichage::declencheur($message->declencheur) }} · {{ Affichage::dateHeure($message->cree_le) }}
                        · {{ Affichage::etatMessage($message->etat) }}
                    </summary>
                    <pre>{{ $message->contenu }}</pre>
                </details>
            @empty
                <p class="vide">Aucun message pour cette commande.</p>
            @endforelse
        </section>
    </div>
@endsection

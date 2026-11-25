@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\DemandeAnnulation')

@section('titre', "Demandes d'annulation")

@section('contenu')
    <header class="entete">
        <div>
            <h1>Demandes d'annulation</h1>
            <p>
                Une réceptionniste peut demander l'annulation d'une commande tant qu'elle n'est pas entrée en traitement.
                L'annulation n'est effective qu'après votre confirmation : les postes l'apprennent à leur prochaine
                synchronisation, et le site restitue au client l'avance déjà versée, sur sa caisse du jour.
            </p>
        </div>
    </header>

    <section class="carte">
        <h2>À trancher</h2>
        @forelse ($aTrancher as $demande)
            @php
                $commande = $demande->commande;
                $avance = (string) $commande->total_paye;
                $aRestituer = bccomp($avance, '0', 2) > 0;
            @endphp
            <div class="alerte alerte-attention" style="margin-top:10px; align-items:flex-start">
                <div style="flex:1; min-width:260px">
                    <strong><a href="{{ route('commandes.show', $commande) }}"><code>{{ $commande->code }}</code></a></strong>
                    <span class="pastille {{ Affichage::classeStatut($commande->statut) }}">{{ Affichage::statut($commande->statut) }}</span>
                    <dl class="fiche" style="margin-top:8px">
                        <dt>Site</dt>
                        <dd>{{ $commande->site?->nom ?? '—' }}</dd>
                        <dt>Client</dt>
                        <dd>{{ $commande->client?->nom }} · {{ $commande->client?->telephone }}</dd>
                        <dt>Montant de la commande</dt>
                        <dd>{{ Affichage::fcfa($commande->montant) }}</dd>
                        <dt>Avance à restituer</dt>
                        <dd>{{ $aRestituer ? Affichage::fcfa($avance) : 'Aucune avance versée' }}</dd>
                        <dt>Motif</dt>
                        <dd>{{ $demande->motif }}</dd>
                        <dt>Demandée</dt>
                        <dd>le {{ Affichage::dateHeure($demande->demandee_le) }} par {{ $demande->demandeePar?->nom ?? '—' }}</dd>
                    </dl>
                </div>
                <div class="formulaire" style="max-width:300px">
                    @if ($commande->estAnnulable())
                        <form method="POST" action="{{ route('annulations.confirmer', $demande) }}"
                              data-confirmer="Confirmer l'annulation de la commande {{ $commande->code }} ? Cette décision est définitive.{{ $aRestituer ? ' Le site devra restituer au client son avance de '.Affichage::fcfa($avance).'.' : '' }}">
                            @csrf
                            <button type="submit" class="bouton">Confirmer l'annulation</button>
                        </form>
                    @else
                        <p>
                            <strong>▲ Cette commande ne peut plus être annulée :</strong>
                            elle est passée au statut « {{ Affichage::statut($commande->statut) }} » depuis la demande. Refusez la demande.
                        </p>
                    @endif
                    <form method="POST" action="{{ route('annulations.refuser', $demande) }}">
                        @csrf
                        <div class="champ">
                            <label for="motif-refus-{{ $demande->id }}">Motif du refus (facultatif)</label>
                            <input type="text" id="motif-refus-{{ $demande->id }}" name="motif_refus" maxlength="255">
                        </div>
                        <button type="submit" class="bouton bouton-secondaire" style="margin-top:8px">Refuser</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="tout-va-bien" style="margin-top:10px">✓ Aucune demande d'annulation n'attend votre décision.</p>
        @endforelse
    </section>

    <section class="carte">
        <h2>Demandes décidées</h2>
        <p class="sous-titre">Les plus récentes d'abord.</p>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr>
                    <th>Décidée le</th>
                    <th>Commande</th>
                    <th>Client</th>
                    {{-- Texte libre : une largeur minimale lui evite d'etre replie mot a mot sur un ecran etroit --}}
                    <th style="min-width:220px">Demande</th>
                    <th style="min-width:220px">Décision</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($decidees as $demande)
                    @php $commande = $demande->commande; @endphp
                    <tr>
                        <td class="serre">
                            {{ Affichage::dateHeure($demande->decidee_le) }}
                            <div class="secondaire">par {{ $demande->decideePar?->nom ?? '—' }}</div>
                        </td>
                        <td class="serre">
                            <a href="{{ route('commandes.show', $commande) }}"><code>{{ $commande->code }}</code></a>
                            <div class="secondaire">{{ $commande->site?->nom }}</div>
                        </td>
                        <td>{{ $commande->client?->nom }}</td>
                        <td>
                            {{ $demande->motif }}
                            <div class="secondaire">
                                le {{ Affichage::dateHeure($demande->demandee_le) }} par {{ $demande->demandeePar?->nom ?? '—' }}
                            </div>
                        </td>
                        <td>
                            <span class="pastille {{ Affichage::classeEtatDemande($demande->etat) }}">{{ Affichage::etatDemande($demande->etat) }}</span>
                            @if ($demande->etat === DemandeAnnulation::REFUSEE && $demande->motif_refus)
                                <div class="secondaire">{{ $demande->motif_refus }}</div>
                            @elseif ($demande->etat === DemandeAnnulation::CONFIRMEE && bccomp((string) $commande->total_paye, '0', 2) > 0)
                                <div class="secondaire">Avance encore à restituer : {{ Affichage::fcfa($commande->total_paye) }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucune demande n'a encore été décidée.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $decidees->links('promoteur.partials.pagination') }}
    </section>
@endsection

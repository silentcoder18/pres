@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Caisse — '.$session->site?->nom)

@section('contenu')
    <header class="entete">
        <div>
            <div class="fil"><a href="{{ route('caisses.index') }}">Caisses des sites</a> ›</div>
            <h1>Caisse de {{ $session->site?->nom }}</h1>
            <p>
                Ouverte le {{ Affichage::dateHeure($session->ouverte_le) }} par {{ $session->ouvertePar?->nom ?? '—' }}
                @if ($session->estOuverte())
                    · <span class="pastille pastille-info">Ouverte</span>
                @else
                    · clôturée le {{ Affichage::dateHeure($session->cloturee_le) }} par {{ $session->clotureePar?->nom ?? '—' }}
                @endif
            </p>
        </div>
    </header>

    @if ($session->estOuverte())
        <div class="message message-info">
            Session en cours : les montants sont ceux de la dernière synchronisation du poste.
        </div>
    @endif

    <section class="tuiles" aria-label="Comptes de la session">
        <div class="tuile">
            <div class="tuile-libelle">Fond initial</div>
            <div class="tuile-valeur">{{ Affichage::nombre($session->fond_initial) }} <small>FCFA</small></div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Avances reçues</div>
            <div class="tuile-valeur">{{ Affichage::nombre($comptes['avances']) }} <small>FCFA</small></div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Soldes encaissés</div>
            <div class="tuile-valeur">{{ Affichage::nombre($comptes['soldes']) }} <small>FCFA</small></div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Avances restituées</div>
            <div class="tuile-valeur">{{ Affichage::nombre($comptes['restitutions']) }} <small>FCFA</small></div>
        </div>
        <div class="tuile tuile-principale">
            <div class="tuile-libelle">Total attendu</div>
            <div class="tuile-valeur">{{ Affichage::nombre($session->estOuverte() ? $comptes['attendu'] : $session->total_attendu) }} <small>FCFA</small></div>
            <div class="tuile-note">fond + avances + soldes − restitutions</div>
        </div>
        @unless ($session->estOuverte())
            <div class="tuile">
                <div class="tuile-libelle">Total compté</div>
                <div class="tuile-valeur">{{ Affichage::nombre($session->total_compte) }} <small>FCFA</small></div>
                <div class="tuile-note">@include('promoteur.partials.ecart-caisse', ['session' => $session])</div>
            </div>
        @endunless
    </section>

    @if ($session->observations)
        <section class="carte">
            <h2>Observation de la réceptionniste</h2>
            <p style="margin-top:6px">{{ $session->observations }}</p>
        </section>
    @endif

    <section class="carte">
        <h2>Encaissements</h2>
        <p class="sous-titre">{{ $comptes['nombre'] }} {{ $comptes['nombre'] > 1 ? 'écritures' : 'écriture' }}, en espèces.</p>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr><th>Heure</th><th>Commande</th><th>Client</th><th>Nature</th><th>Encaissé par</th><th class="nombre">Montant</th></tr>
                </thead>
                <tbody>
                @forelse ($session->paiements as $paiement)
                    <tr>
                        <td class="serre">{{ Affichage::dateHeure($paiement->encaisse_le) }}</td>
                        <td class="serre">
                            @if ($paiement->commande)
                                <a href="{{ route('commandes.show', $paiement->commande) }}"><code>{{ $paiement->commande->code }}</code></a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $paiement->commande?->client?->nom }}</td>
                        <td>{{ Affichage::typePaiement($paiement->type) }}</td>
                        <td>{{ $paiement->utilisateur?->nom ?? '—' }}</td>
                        <td class="nombre">{{ Affichage::fcfa($paiement->montant) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="vide">Aucun encaissement dans cette session.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

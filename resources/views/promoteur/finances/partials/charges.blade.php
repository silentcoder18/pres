{{-- Charges de la semaine portees par l'atelier (RG-11) : commissions des taches terminees dans la
     semaine (RG-12), achats de consommables (§7) et autres achats par categorie (23 octobre 2026).
     Les sites sans charge ne sont pas listes. --}}
@use('App\Support\Affichage')
@php
    $avecCharges = collect($rapport['sites'])
        ->filter(fn ($ligne) => $ligne['site']->estAtelier() || bccomp($ligne['charges'], '0', 2) !== 0)
        ->values();
@endphp
<section class="carte">
    <div class="carte-entete">
        <h2>Charges de l'atelier</h2>
    </div>
    <div class="tableau-cadre">
        <table class="tableau">
            <thead>
            <tr>
                <th>Site</th>
                <th class="nombre">Commissions dues</th>
                <th class="nombre">Consommables</th>
                <th class="nombre">Autres achats</th>
                <th class="nombre">Total des charges</th>
                <th class="nombre">Avances sur commission</th>
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
            </tbody>
            @if ($avecCharges->count() > 1)
                <tfoot>
                <tr>
                    <td class="serre">Réseau entier (FCFA)</td>
                    <td class="nombre">{{ Affichage::nombre($total['commissions_dues']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($total['consommables']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($total['achats']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($total['charges']) }}</td>
                    <td class="nombre">{{ Affichage::nombre($total['avances_commission']) }}</td>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <p class="legende">
        Montants en FCFA. Commissions dues : celles des tâches terminées dans la semaine, quelle que soit la semaine de dépôt de la commande.
        Consommables et autres achats : saisis à l'atelier dans la semaine (rubrique Achats, par catégorie).
        Les avances sur commission, déjà remises aux travailleurs, se déduisent de ce qui leur reste à payer : elles ne s'ajoutent pas aux charges.
        Le paiement des commissions et la clôture ne se font pas encore ici.
    </p>
</section>

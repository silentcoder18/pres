{{-- Ecart d'une session de caisse : compte moins attendu. Le sens est dit en toutes lettres. --}}
@use('App\Support\Affichage')
@if ($session->estOuverte())
    <span class="secondaire">à la clôture</span>
@elseif ((float) $session->ecart == 0.0)
    <span class="pastille pastille-bon">Aucun écart</span>
@elseif ((float) $session->ecart < 0)
    <span class="pastille pastille-grave">▲ Manque {{ Affichage::fcfa(abs((float) $session->ecart)) }}</span>
@else
    <span class="pastille pastille-attention">● Excédent {{ Affichage::fcfa($session->ecart) }}</span>
@endif

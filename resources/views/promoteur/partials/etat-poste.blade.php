{{-- Etat d'un poste, toujours dit en toutes lettres. --}}
@use('App\Models\Poste')
@if ($poste === null)
    <span class="pastille pastille-attention">Pas encore de poste</span>
@else
    @switch($poste->etat())
        @case(Poste::EN_SERVICE)
            <span class="pastille pastille-bon">En service</span>
            @break
        @case(Poste::EN_ATTENTE)
            <span class="pastille pastille-info">En attente de mise en service</span>
            @break
        @case(Poste::CODE_EXPIRE)
            <span class="pastille pastille-attention">Code expiré</span>
            @break
        @case(Poste::REVOQUE)
            <span class="pastille pastille-grave">Révoqué</span>
            @break
    @endswitch
@endif

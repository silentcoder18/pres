@extends('layouts.promoteur')

@section('titre', 'Paramètres')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Paramètres du réseau</h1>
            <p>Vous les fixez ici ; chaque poste les reçoit à sa prochaine synchronisation et les applique aussitôt.</p>
        </div>
    </header>

    <section class="carte">
        @if ($parametres->isEmpty())
            <p class="vide">Les paramètres de départ ne sont pas installés. Lancez « php artisan db:seed » sur le serveur.</p>
        @else
            <form method="POST" action="{{ route('parametres.update') }}" class="formulaire">
                @csrf
                @method('PUT')
                @foreach ($parametres as $parametre)
                    @php $definition = $definitions[$parametre->cle]; @endphp
                    <div class="champ">
                        <label for="valeur-{{ $parametre->cle }}">{{ $parametre->libelle }}</label>
                        <input type="number" id="valeur-{{ $parametre->cle }}" name="valeurs[{{ $parametre->cle }}]"
                               value="{{ old('valeurs.'.$parametre->cle, $parametre->valeur) }}"
                               min="{{ $definition['min'] }}" max="{{ $definition['max'] }}" step="1"
                               style="max-width: 180px" @required(! $definition['facultatif'])
                               @if ($definition['facultatif']) placeholder="non fixé" @endif>
                        <small>{{ $definition['aide'] }}</small>
                    </div>
                @endforeach
                <div class="formulaire-actions">
                    <button type="submit" class="bouton">Enregistrer les paramètres</button>
                </div>
            </form>
        @endif
    </section>
@endsection

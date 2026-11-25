{{-- Retour d'une action : confirmation, ou liste de ce qui empeche de l'enregistrer. --}}
@if (session('succes'))
    <div class="message message-succes" role="status">{{ session('succes') }}</div>
@endif

@php
    // Les formulaires du catalogue ont chacun leur sac d'erreurs ; tous s'affichent ici
    $toutes = collect($errors->getBags())->flatMap(fn ($sac) => $sac->all())->unique()->values();
@endphp
@if ($toutes->isNotEmpty())
    <div class="message message-erreur" role="alert">
        <strong>{{ $toutes->count() > 1 ? "L'enregistrement n'a pas été fait :" : $toutes->first() }}</strong>
        @if ($toutes->count() > 1)
            <ul>
                @foreach ($toutes as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

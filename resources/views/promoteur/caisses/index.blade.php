@extends('layouts.promoteur')
@use('App\Support\Affichage')

@section('titre', 'Caisses des sites')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Caisses des sites</h1>
            <p>
                Chaque session de caisse : son fond initial, ce qui a été encaissé, et à la clôture ce que la réceptionniste a compté.
                Le total attendu et l'écart sont calculés par le serveur à partir des encaissements reçus.
            </p>
        </div>
    </header>

    <section class="carte">
        <form method="GET" action="{{ route('caisses.index') }}" class="filtres">
            <div class="champ">
                <label for="etat">État</label>
                <select id="etat" name="etat">
                    <option value="">Toutes</option>
                    @foreach ($etats as $code => $libelle)
                        <option value="{{ $code }}" @selected(($filtres['etat'] ?? '') === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div class="champ">
                <label for="site">Site</label>
                <select id="site" name="site">
                    <option value="">Tous</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected(($filtres['site'] ?? '') === $site->id)>{{ $site->nom }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bouton bouton-secondaire">Filtrer</button>
            @if (array_filter($filtres))
                <a class="bouton bouton-secondaire" href="{{ route('caisses.index') }}">Tout afficher</a>
            @endif
        </form>

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
                @forelse ($sessions as $session)
                    @php $c = $comptes[$session->id]; @endphp
                    <tr>
                        <td>{{ $session->site?->nom }}</td>
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
                        <td class="nombre">{{ Affichage::nombre($c['avances'] + $c['soldes'] - $c['restitutions']) }}</td>
                        <td class="nombre">{{ Affichage::nombre($session->estOuverte() ? $c['attendu'] : $session->total_attendu) }}</td>
                        <td class="nombre">{{ $session->estOuverte() ? '—' : Affichage::nombre($session->total_compte) }}</td>
                        <td class="serre">@include('promoteur.partials.ecart-caisse', ['session' => $session])</td>
                        <td class="serre"><a class="bouton bouton-secondaire bouton-petit" href="{{ route('caisses.show', $session) }}">Détail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="vide">Aucune session de caisse ne correspond.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $sessions->links('promoteur.partials.pagination') }}
    </section>
@endsection

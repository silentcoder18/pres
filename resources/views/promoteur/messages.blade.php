@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Message')

@section('titre', 'Messages WhatsApp')

@section('contenu')
    <header class="entete">
        <div>
            <h1>Messages WhatsApp</h1>
            <p>
                Les messages que les postes ont créés pour les clients. L'envoi par l'API officielle de WhatsApp sera branché
                à la mise en production : d'ici là, ils restent en attente et aucun client ne les reçoit.
            </p>
        </div>
    </header>

    <section class="tuiles" aria-label="File d'envoi">
        <div class="tuile">
            <div class="tuile-libelle">En attente d'envoi</div>
            <div class="tuile-valeur">{{ Affichage::nombre($compteurs[Message::EN_ATTENTE] ?? 0) }}</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">Envoyés</div>
            <div class="tuile-valeur">{{ Affichage::nombre($compteurs[Message::ENVOYE] ?? 0) }}</div>
        </div>
        <div class="tuile">
            <div class="tuile-libelle">En échec</div>
            <div class="tuile-valeur">{{ Affichage::nombre($compteurs[Message::ECHOUE] ?? 0) }}</div>
        </div>
    </section>

    <section class="carte">
        <form method="GET" action="{{ route('messages.index') }}" class="filtres">
            <div class="champ">
                <label for="etat">État</label>
                <select id="etat" name="etat">
                    <option value="">Tous</option>
                    @foreach ($etats as $etat)
                        <option value="{{ $etat }}" @selected(($filtres['etat'] ?? '') === $etat)>{{ Affichage::etatMessage($etat) }}</option>
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
                <a class="bouton bouton-secondaire" href="{{ route('messages.index') }}">Tout afficher</a>
            @endif
        </form>

        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr><th>Créé le</th><th>Site</th><th>Client</th><th>Motif</th><th>État</th><th class="nombre">Tentatives</th><th>Texte</th></tr>
                </thead>
                <tbody>
                @forelse ($messages as $message)
                    <tr>
                        <td class="serre">{{ Affichage::dateHeure($message->cree_le) }}</td>
                        <td>{{ $message->site?->nom }}</td>
                        <td>
                            {{ $message->client?->nom }}
                            <div class="secondaire">{{ $message->client?->telephone }}</div>
                        </td>
                        <td>
                            {{ Affichage::declencheur($message->declencheur) }}
                            @if ($message->commande)
                                <div class="secondaire"><a href="{{ route('commandes.show', $message->commande) }}">{{ $message->commande->code }}</a></div>
                            @endif
                        </td>
                        <td>
                            <span class="pastille {{ match ($message->etat) { Message::ENVOYE => 'pastille-bon', Message::ECHOUE => 'pastille-grave', default => 'pastille-info' } }}">
                                {{ Affichage::etatMessage($message->etat) }}
                            </span>
                        </td>
                        <td class="nombre">{{ $message->tentatives }}</td>
                        <td>
                            <details class="contenu">
                                <summary>Lire</summary>
                                <pre>{{ $message->contenu }}</pre>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide">Aucun message ne correspond.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $messages->links('promoteur.partials.pagination') }}
    </section>
@endsection

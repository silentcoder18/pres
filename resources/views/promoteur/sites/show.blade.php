@extends('layouts.promoteur')
@use('App\Support\Affichage')
@use('App\Models\Poste')

@section('titre', $site->nom)

@section('contenu')
    <header class="entete">
        <div>
            <div class="fil"><a href="{{ route('sites.index') }}">Sites et postes</a> ›</div>
            <h1>{{ $site->nom }}</h1>
            <p>
                {{ Affichage::typeSite($site->type) }} · code <code class="code">{{ $site->code }}</code>
                · {{ $commandesEnCours }} {{ $commandesEnCours > 1 ? 'commandes en cours' : 'commande en cours' }}
            </p>
        </div>
        <div class="entete-actions">
            <a class="bouton bouton-secondaire" href="{{ route('commandes.index', ['site' => $site->id]) }}">Ses commandes</a>
            <a class="bouton bouton-secondaire" href="{{ route('caisses.index', ['site' => $site->id]) }}">Ses caisses</a>
        </div>
    </header>

    @if (session('code_mise_en_service'))
        @php $nouveau = session('code_mise_en_service'); @endphp
        <section class="code-mise-en-service" role="status">
            <strong>Code de mise en service du poste « {{ $nouveau['poste'] }} »</strong>
            <span class="code">{{ $nouveau['code'] }}</span>
            <p>
                Notez-le maintenant : il ne sera plus affiché. Il se saisit au premier lancement de l'application
                sur l'ordinateur du site, connecté à Internet, avec l'adresse du serveur :
                <strong class="code">{{ url('/') }}</strong>.
                Il ne sert qu'une fois et expire dans {{ $nouveau['validite'] }} heures.
            </p>
            <p style="margin-top:6px">
                Ensuite, chacun se connecte sur le poste avec le compte que vous lui créez à la page
                <a href="{{ route('comptes.index') }}">Comptes</a>.
            </p>
        </section>
    @endif

    <div class="colonnes">
        <section class="carte">
            <h2>Poste du site</h2>
            <p class="sous-titre">Un seul ordinateur par site. Son accès se retire à tout moment ; il se verrouille alors à sa prochaine synchronisation.</p>

            @forelse ($postes as $poste)
                <dl class="fiche" style="margin-bottom:12px">
                    <dt>Poste</dt>
                    <dd>{{ $poste->nom }} @include('promoteur.partials.etat-poste', ['poste' => $poste])</dd>
                    @if ($poste->nom_machine)
                        <dt>Ordinateur</dt>
                        <dd>{{ $poste->nom_machine }}</dd>
                    @endif
                    @if ($poste->etat() === Poste::EN_SERVICE)
                        <dt>Mis en service</dt>
                        <dd>{{ Affichage::dateHeure($poste->enrole_le) }}</dd>
                        <dt>Dernier contact</dt>
                        <dd>{{ Affichage::dateHeure($poste->dernier_contact_le) }} ({{ Affichage::depuis($poste->dernier_contact_le) }})</dd>
                    @elseif ($poste->etat() === Poste::EN_ATTENTE)
                        <dt>Code valable jusqu'au</dt>
                        <dd>{{ Affichage::dateHeure($poste->code_expire_le) }}</dd>
                    @elseif ($poste->etat() === Poste::REVOQUE)
                        <dt>Révoqué le</dt>
                        <dd>{{ Affichage::dateHeure($poste->revoque_le) }}</dd>
                    @endif
                </dl>
                <div class="formulaire-actions" style="margin-bottom:18px">
                    <form method="POST" action="{{ route('postes.code', $poste) }}"
                          data-confirmer="{{ $poste->etat() === Poste::EN_SERVICE
                              ? "Générer un nouveau code ? L'ordinateur actuellement relié à ce poste ne pourra plus se synchroniser."
                              : 'Générer un nouveau code de mise en service pour ce poste ?' }}">
                        @csrf
                        <button type="submit" class="bouton bouton-secondaire bouton-petit">
                            {{ $poste->etat() === Poste::REVOQUE ? "Rétablir l'accès (nouveau code)" : 'Nouveau code de mise en service' }}
                        </button>
                    </form>
                    @if ($poste->etat() !== Poste::REVOQUE)
                        <form method="POST" action="{{ route('postes.revoquer', $poste) }}"
                              data-confirmer="Retirer l'accès de ce poste ? Il se verrouillera à sa prochaine synchronisation.">
                            @csrf
                            <button type="submit" class="bouton bouton-danger bouton-petit">Révoquer l'accès</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="vide">Ce site n'a pas encore de poste. Générez son code de mise en service ci-dessous, puis saisissez-le sur l'ordinateur du site.</p>
            @endforelse

            @if ($postes->where('revoque_le', null)->isEmpty())
                <form method="POST" action="{{ route('postes.store', $site) }}" class="formulaire">
                    @csrf
                    <div class="champ">
                        <label for="nom_poste">Nom du poste</label>
                        <input type="text" id="nom_poste" name="nom" value="{{ old('nom', 'Poste principal') }}" maxlength="100" required>
                    </div>
                    <div class="formulaire-actions">
                        <button type="submit" class="bouton">Générer le code de mise en service</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="carte">
            <h2>Fiche du site</h2>
            <p class="sous-titre">Le code et le type ne changent plus : les codes de commande et les lots en dépendent.</p>
            <form method="POST" action="{{ route('sites.update', $site) }}" class="formulaire">
                @csrf
                @method('PUT')
                <div class="champ">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" value="{{ old('nom', $site->nom) }}" maxlength="150" required>
                </div>
                <div class="champ">
                    <label for="adresse">Adresse</label>
                    <input type="text" id="adresse" name="adresse" value="{{ old('adresse', $site->adresse) }}" maxlength="255">
                </div>
                <div class="champ">
                    <label for="actif">État</label>
                    <select id="actif" name="actif">
                        <option value="1" @selected(old('actif', (int) $site->actif) == 1)>Actif</option>
                        <option value="0" @selected(old('actif', (int) $site->actif) == 0)>Désactivé</option>
                    </select>
                    <small>Un site désactivé n'est plus proposé aux postes ni pour un nouveau compte.</small>
                </div>
                <div class="formulaire-actions">
                    <button type="submit" class="bouton">Enregistrer</button>
                </div>
            </form>
        </section>
    </div>

    <section class="carte">
        <div class="carte-entete">
            <h2>Comptes rattachés</h2>
            <a href="{{ route('comptes.create') }}">Nouveau compte →</a>
        </div>
        <div class="tableau-cadre">
            <table class="tableau">
                <thead>
                <tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>État</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($comptes as $compte)
                    <tr>
                        <td>{{ $compte->nom }}</td>
                        <td><code>{{ $compte->identifiant }}</code></td>
                        <td>{{ Affichage::role($compte->role) }}</td>
                        <td>
                            @if ($compte->actif)
                                <span class="pastille pastille-bon">Actif</span>
                            @else
                                <span class="pastille pastille-terne">Désactivé</span>
                            @endif
                        </td>
                        <td class="serre"><a class="bouton bouton-secondaire bouton-petit" href="{{ route('comptes.edit', $compte) }}">Modifier</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucun compte n'est rattaché à ce site.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

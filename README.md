# GePressing — Serveur central

Serveur Laravel du réseau de pressing : base de référence, service de synchronisation des postes et interface web du Promoteur. La spécification est dans `../docs/cahier-des-charges-v2.md`.

État au 16 octobre 2026 :

- Postes et personnes (décision du Promoteur, 16 octobre 2026) : la mise en service authentifie l'ordinateur ; chaque personne qui s'y connecte a sa propre base et sa propre session de synchronisation, sur les données de son site de rattachement. Le Promoteur ne se connecte pas sur un poste. Un poste longtemps hors ligne avertit sans se verrouiller. Chaque personne a sa caisse. Un article est lavé puis repassé : deux tâches, chacune commissionnée, confiées à un laveur, un repasseur ou un polyvalent.

- API des postes : socle (étape 1), boutique (étape 2 : commandes, encaissements, caisses, lots aller, messages, fiches client réunies) et étape 3 : travail du chef d'atelier (réception des lots, contrôle, tâches, lots retour, travailleurs, consommables, remise au client) et de la réceptionniste face à l'atelier (retours, accord du client, demandes d'annulation, restitution de l'avance, trop-perçu rendu).
- Interface web du Promoteur (étape 4) : en place pour tout ce que les postes synchronisent, travailleurs de l'atelier, accords clients et annulations compris. Les pages des commissions à payer, des clôtures et des emprunts viendront avec l'étape 5.
- Améliorations du 23 octobre 2026 (`docs/suivi-ameliorations.md`) : remarque d'une ligne de commande, anomalie signalée par l'atelier, versements journaliers, stock de consommables de l'atelier, exploitation de la semaine dans les finances, mise à jour des postes depuis le serveur.
- Finances : page hebdomadaire et rapport PDF calculés sur ce que les postes envoient (commandes, encaissements, caisses, tâches terminées, consommables) ; l'affectation des paiements est décidée par le serveur (RG-14). Le solde y reste une prévision tant que la finance (étape 5) n'est pas là.

## Installation en local

Prérequis : PHP 8.4 ou plus avec les extensions `pdo_sqlite`, `bcmath`, `mbstring` et `dom`, et Composer. L'extension `gd` est nécessaire pour que le logo figure sur le rapport PDF (sans elle, le rapport sort avec le nom du réseau en toutes lettres). Aucun outil de compilation n'est nécessaire : l'interface n'utilise ni Node ni Vite, sa feuille de style est `public/css/promoteur.css`.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

`migrate --seed` crée les tables et les paramètres de départ (RG-23). Aucun compte n'est créé (RG-15).

Pour un jeu d'essai (sites et catalogue de l'ancienne application) :

```sh
php artisan db:seed --class=DonneesDemoSeeder
```

En local la base est le fichier SQLite `database/database.sqlite`. En production, renseigner une base MySQL ou MariaDB dans `.env` (`DB_CONNECTION=mysql`, etc.) : les 199 tests passent aussi sur MariaDB 10.4, avec les pilotes `mysql` et `mariadb`.

Mise en ligne sur un hébergement mutualisé : `deploiement\construire-serveur.bat` prépare l'archive à déposer chez l'hébergeur (application sans les outils de développement, dossier `public_html`, configuration de production à compléter), et `docs/deploiement.md` donne la marche à suivre. Il faut PHP 8.4 ou plus récent (les bibliothèques livrées, Symfony 8, l'exigent) et l'extension `bcmath`, désormais déclarée dans `composer.json`. Quand `APP_URL` est en https, les liens et redirections le sont aussi (`AppServiceProvider`).

## Interface web du Promoteur

Lancement en local : `run-serveur.bat` à la racine du projet (il met la base à niveau puis lance le serveur), ou :

```sh
php artisan migrate --seed
php artisan serve
```

puis ouvrir `http://127.0.0.1:8000`.

Le premier compte Promoteur se crée en ligne de commande, parce qu'aucun compte n'est livré avec l'application :

```sh
php artisan pressing:utilisateur-creer <identifiant> "<Nom>"
```

Le mot de passe est demandé à l'écran (8 caractères au moins, dont une lettre et un chiffre). C'est la seule chose qui se crée hors de l'interface : tout le reste s'y fait, et y laisse une ligne au journal d'audit.

### Mettre un site en route

1. **Sites et postes** : créer le site s'il n'existe pas, puis « Générer le code de mise en service ». Le code s'affiche une seule fois, avec l'adresse du serveur. Un code ne se génère nulle part ailleurs : aucune commande ne le fait.
2. **Comptes** : créer le compte de chaque personne du site (une réceptionniste pour une boutique, le chef d'atelier pour l'atelier). L'identifiant peut être une adresse de courriel.
3. Sur l'ordinateur du site, au premier lancement du poste : saisir l'adresse du serveur et le code. Chaque personne se connecte ensuite avec son compte ; sa première connexion se fait avec Internet, les suivantes fonctionnent sans.

Un poste connecte n'importe quel compte d'un site, quel que soit le site où il se trouve (décisions du Promoteur, 9 et 16 octobre 2026). La personne y voit les menus de son rôle (réceptionniste : la boutique ; chef d'atelier : l'atelier) et travaille dans sa propre base sur le poste, sur les données de son site de rattachement ; ce qu'elle fait est enregistré pour ce site, sous son nom. En conséquence :

- une réceptionniste est rattachée à une boutique, un chef d'atelier à un atelier ; le Promoteur ne se connecte pas sur un poste ;
- tous les comptes sont transmis à tous les postes, sans mot de passe ;
- un compte désactivé, dont le mot de passe ou le site change, voit sa session refusée à la synchronisation suivante : la personne se reconnecte avec Internet, ses données sur le poste sont conservées ;
- seul un compte désactivé est refusé à la connexion, et le poste le dit.

Si le serveur est lancé alors que sa base n'a pas reçu toutes ses mises à niveau, il le dit au lieu d'échouer en cours d'action (`App\Http\Middleware\BaseAJour`) : relancer `run-serveur.bat` ou `php artisan migrate --force --seed`.

| Page | Ce qu'on y fait |
| --- | --- |
| Tableau de bord | Recette du jour et de la semaine, commandes en cours par site et par étape, ce qui demande une décision ou une vérification (dont les demandes d'annulation à trancher et les trop-perçus à rendre aux clients) |
| Commandes, Lots, Caisses des sites, Messages WhatsApp | Consultation de ce que les postes ont synchronisé ; lots en retard, écarts de comptage, écarts de caisse. `/commandes?trop_percu=1` ne montre que les commandes dont le client a versé plus que la facture |
| Accords clients | Commandes bloquées par une correction de l'atelier en attente de l'accord du client, avec la date à partir de laquelle elle s'appliquera d'office (RG-09). L'accord s'enregistre en boutique |
| Annulations | Demandes des réceptionnistes : confirmer, ou refuser avec un motif (RG-24). La décision part vers la boutique ; une annulation confirmée l'est aussi pour l'atelier si la commande y était partie |
| Finances | Situation d'une semaine, pour le réseau ou pour un site : recette, recouvrements tardifs, net encaissé, reste à recouvrer, commissions prévues, solde prévisionnel ; détail par site et par jour, caisses de la semaine, rapport en PDF |
| Sites et postes | Créer un site, générer le code de mise en service de son poste (montré une seule fois), générer un nouveau code, révoquer l'accès |
| Comptes | Créer un compte, le désactiver, réinitialiser son mot de passe (RG-15, RG-16) |
| Clients | Recherche, et fiches en double à trancher : même numéro, noms différents (RG-21, §4.4) |
| Écritures refusées | Ce qu'un poste a envoyé et que le serveur n'a pas accepté, avec le motif |
| Catalogue et tarifs, Paramètres | Articles ; prestations avec leurs étapes (lavage, repassage, ou les deux) ; tarifs avec la commission de chaque étape ; paramètres de RG-23 |
| Journal d'audit | Chaque action faite dans l'interface : auteur, date, objet, détail |

Règles de fonctionnement :

- Seul un compte Promoteur actif entre ; le compte est revérifié à chaque page (`App\Http\Middleware\ExigerPromoteur`).
- Une action est enregistrée entièrement ou pas du tout : la modification, sa publication vers les postes et sa ligne au journal vont ensemble (`App\Http\Middleware\ActionAtomique`).
- Tout ce que le Promoteur change est publié en priorité vers les postes concernés, qui le reçoivent à leur synchronisation suivante (§4.6). Rien ne se supprime : un site, un compte, un article ou un tarif se désactive.
- Les dates sont gardées en UTC et affichées dans le fuseau du réseau (`PRESSING_FUSEAU`, par défaut `Africa/Douala`).
- Les mots de passe et les codes de mise en service ne sont jamais écrits dans le journal d'audit.

Code : contrôleurs dans `app/Http/Controllers/Promoteur`, pages dans `resources/views/promoteur`, libellés et mise en forme dans `App\Support\Affichage`, journal dans `App\Support\Audit`. Aperçus des pages : `../docs/apercus/promoteur-web/`.

Avant la mise en production (étape 6) : `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, et le serveur joint uniquement en HTTPS.

## Finances hebdomadaires

Les postes envoient des faits ; le serveur est seul à calculer la situation consolidée (§7). La page « Finances » (`/finances`, menu « Suivi ») et le rapport PDF lisent le même calcul, fait par `App\Services\Finance\RapportHebdomadaire` : aucune somme n'est faite dans les pages.

### Semaine et adresse

Une semaine va du lundi 00:00 au lundi suivant 00:00 dans le fuseau du réseau (`PRESSING_FUSEAU`), alors que les dates sont gardées en UTC : un encaissement du dimanche à 23:30 et un autre du lundi à 00:30, heure de Douala, sont dans deux semaines différentes. Tout passe par `App\Services\Finance\SemaineComptable`.

| Adresse | Effet |
| --- | --- |
| `/finances` | Semaine en cours, tout le réseau |
| `/finances?semaine=2026-W41` | Semaine choisie, écrite avec l'année et le numéro de semaine ISO |
| `/finances?semaine=2026-W41&site=<identifiant du site>` | Un seul site |
| `/finances/rapport.pdf?semaine=…&site=…` | Rapport PDF de la même semaine et du même périmètre |

Une semaine mal écrite ou inexistante (`2026-41`, `2027-W53`) et un site inconnu sont refusés : on revient à la semaine en cours avec le motif.

### Ce qui est calculé

Pour un site et une semaine ; le réseau entier est la somme des sites. Les sommes sont faites sur des chaînes à deux décimales (bcmath), jamais en nombres flottants.

| Grandeur | Définition |
| --- | --- |
| Commandes déposées, montant facturé | Commandes du site déposées dans la semaine, hors annulées |
| Commissions prévues | Quantité × commission figée de leurs lignes (RG-03). Prévision faite dès le dépôt ; les commissions dues sont une grandeur à part, ci-dessous |
| Avances, soldes, rendu aux clients | Paiements encaissés dans la semaine sur une caisse du site, par nature. Rendu aux clients : les avances et soldes de la semaine dont la commande, annulée depuis, a été remboursée (quelle que soit la date de la restitution, RG-24), et les trop-perçus rendus dans la semaine. Net encaissé = avances + soldes − rendu aux clients |
| Recette de la semaine | Avances et soldes dont la commande a été déposée la même semaine, hors avances restituées, moins les trop-perçus rendus dans la semaine |
| Recouvrements tardifs | Avances et soldes dont la commande a été déposée une semaine antérieure (RG-14). Recette + recouvrements tardifs = net encaissé |
| Reste à recouvrer | De la semaine : reste des commandes déposées dans la semaine. Total : reste des commandes ni livrées, ni données, ni annulées, à la date du calcul. Un reste négatif (trop-perçu à rendre) n'y est pas compté |
| Solde prévisionnel | Recette de la semaine − commissions prévues. Provisoire : il ne déduit pas encore les charges réelles de l'atelier, dont la répartition entre les sites viendra avec la clôture hebdomadaire |
| Caisses de la semaine | Sessions ouvertes dans la semaine : nombre, clôturées, encore ouvertes ; manques et excédents sont les écarts négatifs (en valeur absolue) et positifs des sessions clôturées (RG-18) |
| Charges de l'atelier | Commissions dues : pour chaque tâche terminée dans la semaine par un travailleur du site, sa quantité × la commission copiée de la ligne à l'attribution (RG-03, RG-12), quelle que soit la semaine de dépôt de la commande. Consommables : sorties saisies à l'atelier dans la semaine (§7). Charges = commissions dues + consommables (RG-11) |

La page et le rapport donnent aussi l'exploitation de la semaine (amélioration du 23 octobre 2026) : pièces traitées (une pièce compte une fois, quand sa dernière étape est terminée), commandes remises au client, commandes en penderie depuis plus de 14 jours à la fin de la semaine (d'après la réception du lot retour et la date de remise : le chiffre d'une semaine passée ne change plus), linge prêt non retiré, commissions dues par rôle de travailleur, net encaissé moins charges, et versements journaliers des boutiques : déclarés, reçus à l'atelier, écart, encore en route.

Ne sont pas encore comptés, et la page comme le rapport le disent : les commissions payées, les emprunts et la clôture hebdomadaire (étape 5). L'avance restituée d'une commande annulée est reprise sur la semaine où elle avait été reçue (RG-24) : la recette d'une semaine passée peut donc baisser après coup. Un trop-perçu rendu sort de la semaine où il est rendu. Les comptes de journée et les caisses déduisent l'un et l'autre le jour où ils sortent de la caisse.

Pour obtenir les chiffres par programme :

```php
use App\Models\Site;
use App\Services\Finance\RapportHebdomadaire;
use App\Services\Finance\SemaineComptable;

$semaine = SemaineComptable::depuisIso('2026-W41');      // ou SemaineComptable::courante()
$calcul = app(RapportHebdomadaire::class);

$calcul->chiffresDuSite($semaine, Site::where('code', 'CV')->firstOrFail());
// ['commandes_nombre' => 7, 'montant_facture' => '86600.00', 'avances' => '61300.00', 'recette' => '61300.00', …]

$calcul->calculer($semaine);          // réseau entier : 'sites', 'total', 'jours', 'caisses', 'recouvrements'
```

### Affectation des paiements (RG-14)

Le serveur décide l'affectation de chaque paiement au moment où il le reçoit d'un poste (`ApplicateurOperations::appliquerPaiement`, règle dans `App\Services\Finance\AffectationPaiements`). Elle est gardée dans `paiements.affectation` et ne part pas vers les postes.

| Valeur | Cas |
| --- | --- |
| `RECETTE` | Avance ou solde encaissé la semaine du dépôt de la commande |
| `RESERVE` | Avance ou solde encaissé une semaine postérieure : recouvrement tardif. RG-14 veut qu'il rembourse d'abord un emprunt ; les emprunts n'existant pas encore sur le serveur (étape 5), tout va pour l'instant à la réserve |
| vide | Somme rendue au client (`REMBOURSEMENT`) : avance d'une commande annulée, ou trop-perçu |

Les paiements reçus avant cette règle se rattrapent par `php artisan pressing:paiements-affecter`, qui peut être relancée sans effet.

### Rapport PDF

Le bouton « Télécharger le rapport (PDF) » remet `rapport-financier-2026-S41.pdf` (ou `rapport-financier-2026-S41-CV.pdf` pour un site), au format A4 : synthèse, tableaux par site et par jour, caisses de la semaine avec leurs écarts, recouvrements tardifs, puis ce que le rapport ne compte pas encore. Chaque export laisse une ligne au journal d'audit, avec la semaine et le périmètre.

Le PDF est fabriqué par `dompdf/dompdf` (version 3.1.6, contrainte `^3.1` dans `composer.json`), bibliothèque en PHP pur qui convient à un hébergement mutualisé. Composer installe avec elle `dompdf/php-font-lib` 1.0.2, `dompdf/php-svg-lib` 1.0.2, `masterminds/html5` 2.11.0 et `sabberworm/php-css-parser` 9.5.0.

- Mise en page : `resources/views/promoteur/finances/rapport-pdf.blade.php`, par tableaux (dompdf ne connaît ni flex ni grid), police DejaVu Sans livrée avec dompdf. Fabrication : `App\Services\Finance\RapportPdf`.
- Rien n'est lu sur le réseau et rien ne s'exécute dans le document ; seul `public/images` est lisible, pour le logo.
- Les mesures de police sont gardées dans `storage/framework/cache/dompdf`, créé au premier rapport : `storage/` doit être accessible en écriture, comme pour le reste de l'application.
- Mémoire : environ 70 Mo pour 4 sites, 100 Mo pour une douzaine, 190 Mo pour 40 sites et leurs 240 caisses. La limite est relevée à 256 Mo le temps du rapport si l'hébergeur le permet ; au-delà, demander le rapport site par site.

## Commandes d'administration

Il y en a trois. Les postes, leurs codes de mise en service et les comptes des sites ne se créent que sur l'interface web (décision du Promoteur, 9 octobre 2026) : les anciennes commandes `pressing:poste-creer` et `pressing:poste-revoquer` ont été retirées, et `pressing:utilisateur-creer` refuse tout autre rôle que Promoteur.

```sh
# Créer un compte Promoteur (le mot de passe est demandé s'il n'est pas fourni)
php artisan pressing:utilisateur-creer <identifiant> "<Nom>"

# Affecter les encaissements qui n'ont pas d'affectation (RG-14) ; relançable sans effet
php artisan pressing:paiements-affecter

# Appliquer d'office les corrections de l'atelier restées sans accord au-delà du délai (RG-09) ; relançable sans effet
php artisan pressing:corrections-appliquer
```

### Tâches planifiées

La dernière commande est planifiée toutes les heures (`routes/console.php`). Chez l'hébergeur, une seule tâche planifiée suffit, chaque minute : `php artisan schedule:run`. Tant qu'elle n'est pas en place, ou tant que le Promoteur n'a pas saisi le délai d'accord sur anomalie à la page « Paramètres », une commande dont le contrôle a relevé un écart reste « En attente d'accord » jusqu'à l'accord du client, enregistré en boutique.

Le délai se compte depuis la réception de la correction par le serveur, et non depuis l'heure du contrôle sur le poste de l'atelier : un poste resté hors ligne ne peut pas faire appliquer une correction avant que la boutique ait eu le temps de joindre le client. Chaque application d'office est inscrite au journal d'audit.

## API des postes

C'est toujours le poste qui appelle le serveur. Toutes les routes sont sous `/api`.

| Route | Rôle |
| --- | --- |
| `POST /postes/enroler` | Échange le code de mise en service contre le jeton du poste |
| `POST /auth/verifier` | Vérifie l'identifiant d'une personne à sa première connexion sur le poste et lui ouvre une session sur ce poste (`session.jeton`, `session.site_id`) |
| `GET /sync/instantane` | État complet des données qui concernent la base, par pages : tant que `reste` est vrai, rappeler avec `?suite=` |
| `GET /sync/tirer?curseur=N` | Changements publiés après le curseur |
| `POST /sync/pousser` | Écritures faites sur le poste |
| `GET /version` | Heure du serveur, et version du poste publiée avec l'adresse, la taille et l'empreinte SHA-256 de son jar (rien tant que `PRESSING_POSTE_VERSION` n'est pas renseignée ou que `storage/app/poste/GePressing-Poste.jar` n'est pas déposé) |
| `GET /poste/telecharger` | Le jar du poste publié |

Sauf les deux dernières et la première, les routes exigent l'en-tête `Authorization: Bearer <jeton>`. Un jeton inconnu répond 401 `jeton_invalide` ; un poste révoqué répond 403 `poste_revoque`.

La base d'une personne se synchronise sous sa session, avec l'en-tête `X-Pressing-Session: <jeton de session>` en plus du jeton du poste (`App\Http\Middleware\AuthentifierPoste`, `App\Services\Sync\Perimetre`) :

- elle reçoit les données de référence et celles du site de rattachement de la personne ; ce qu'elle envoie est rattaché à ce site ;
- ce qu'elle envoie ne lui revient pas, mais arrive aux autres personnes du même site, y compris sur le même poste (`sync_changements.origine_session_id`) ;
- une session qui ne vaut plus répond 401 `session_invalide` avec la raison : compte désactivé, mot de passe ou site changé, connexion plus récente de la même personne sur ce poste (`App\Models\SessionPoste`).

Sans session, c'est la base de l'ordinateur : elle ne reçoit que la référence (sites, catalogue, tarifs, paramètres, comptes). Ce qu'elle envoie encore (écritures d'un poste mis à jour avant d'avoir tout envoyé) est rattaché, comme avant, au site du poste.

Tables acceptées des postes : `clients`, `sessions_caisse`, `commandes` (avec leurs lignes), `paiements`, `lots` (aller d'une boutique, retour de l'atelier), `messages`, et depuis l'étape 3 `receptions_lot`, `controles`, `taches`, `travailleurs`, `consommables`, `remises`, `accords`, `demandes_annulation`. Une écriture refusée est tracée avec son motif et visible sur la page « Écritures refusées ».

Améliorations du 23 octobre 2026 :

- une ligne de commande porte sa `remarque` (tache, accroc, bouton manquant…), notée au dépôt : elle est gardée et part à l'atelier avec la commande ;
- un message peut porter sur une commande que l'atelier a reçue : l'atelier annonce au client une anomalie constatée en cours de traitement, sans bloquer la commande ;
- `versements_journaliers` : le versement déclaré par une boutique à la clôture de sa caisse (`reserve_a_la_boutique` ailleurs ; un seul par caisse, `versement_deja_declare`). Il va à la boutique et à tous les ateliers. `receptions_versement` : l'atelier en accuse réception avec le montant reçu, une seule fois (`versement_deja_recu`) ; la réception revient à la boutique ;
- `consommables` porte la `categorie` de l'achat (décision du Promoteur, 23 octobre 2026) : `CONSOMMABLES`, `MATERIEL`, `ENTRETIEN`, `ENERGIE`, `TRANSPORT`, `AUTRE` ; un poste d'avant n'en envoie pas, c'est alors un achat de consommables. Les autres achats comptent dans les charges, à côté des consommables ;
- `avances_commission` : avance remise par le chef d'atelier à un travailleur de l'atelier (décision du 23 octobre 2026), réservée à l'atelier (`travailleur_inconnu` pour un travailleur d'ailleurs). Le poste la plafonne à ce qui est gagné et pas encore reçu dans la semaine ; le serveur l'enregistre comme un fait. Elle se déduit du reste à payer, elle n'est pas une charge ;
- `articles_consommables`, `mouvements_consommables` : le stock de consommables de l'atelier, réservé à l'atelier. Le stock est la somme des mouvements ; ils vont à toutes les personnes de l'atelier. Les articles de départ ont un identifiant calculé à partir du site et du code : deux bases du même atelier créent le même article. Une sortie qui dépasse le stock connu du serveur est acceptée : c'est un fait.

Encaissements et sommes rendues :

- un versement en espèces est un fait : le serveur l'enregistre même s'il dépasse le reste qu'il connaît (une correction appliquée entre-temps, par exemple). La commande porte alors un trop-perçu, c'est-à-dire un reste négatif ;
- une somme rendue (`REMBOURSEMENT`) n'est acceptée que pour l'avance entière d'une commande annulée (sinon `montant_different_du_paye`), ou dans la limite du trop-perçu d'une commande qui ne l'est pas (sinon `pas_de_trop_percu`) ;
- tout montant est plafonné à 9 999 999 999 (`ApplicateurOperations::MONTANT_MAX`), comme sur le poste.

L'accord du client (`accords`) n'est accepté que du poste du site de la commande ; il applique la correction et débloque la commande sur les deux postes. Une demande d'annulation (`demandes_annulation`) n'est acceptée que tant que la commande n'est pas entrée en traitement, et une seule à la fois ; elle se tranche sur le web. Une correction de l'atelier qui retirerait le dernier article d'une commande est refusée (`commande_sans_article`).

Caisse : une session de caisse ouverte par personne et par site (`session_deja_ouverte` sinon). Deux étapes par article : une ligne de commande porte ses étapes (`avec_lavage`, `avec_repassage`) et la commission de chacune (`commission_lavage`, `commission_repassage`, dont la somme est `commission_appliquee`) ; un poste d'avant les étapes ne les envoie pas, elles se déduisent alors de la prestation. Une tâche porte son `etape` (`LAVAGE` ou `REPASSAGE`) et rapporte la commission de cette étape ; elle est refusée si la ligne ne comprend pas l'étape (`etape_inconnue`), si le rôle du travailleur ne la permet pas (`role_incompatible`), ou si l'on confie au repassage plus que ce que le lavage a rendu (`lavage_non_termine`). Un travailleur porte son `role` (`LAVEUR`, `REPASSEUR`, `POLYVALENT`).

L'état complet envoyé à la mise en service d'une base comprend les commandes en cours, celles qui ont changé dans les huit dernières semaines et les commandes annulées dont l'avance n'est pas encore rendue.

Le poste n'envoie jamais le statut d'une commande après son enregistrement : le serveur le déduit de ce que le site a fait (RG-05). Un lot reçu rend ses commandes Réceptionnées (ou Retournées, pour un lot retour) ; un contrôle les rend Contrôlées, ou En attente d'accord s'il porte un écart de quantité ; la première tâche les met En traitement ; elles sont Traitées quand chaque étape de chaque ligne est couverte par des tâches terminées ;
 une remise les rend Livrées. Le contrôle, les tâches, les travailleurs, les consommables et les lots retour ne sont acceptés que du poste d'un atelier. Le chef d'atelier n'encaisse et ne remet que les commandes qu'il a lui-même enregistrées (RG-10) : la règle est vérifiée ici comme sur le poste.

Pour qu'une table soit synchronisée :

- du serveur vers les postes : le modèle utilise le trait `App\Models\Concerns\Synchronisable` ;
- des postes vers le serveur : la table est déclarée dans `App\Services\Sync\ApplicateurOperations`.

## Tests

```sh
php artisan test
```

Les essais de l'interface sont dans `tests/Feature/Promoteur`, ceux de l'API dans `tests/Feature/Api`, ceux du calcul financier (semaine comptable, rapport hebdomadaire, affectation) dans `tests/Feature/Finance`.

## Essai de bout en bout avec le poste

Les essais ouvrent des postes réels : la base de l'ordinateur dans le fichier donné, et à côté, dans `personnes/<poste>/`, la base de chaque personne qui s'y connecte (décision du 16 octobre 2026). Ils ont été rejoués avec succès le 16 octobre 2026, sauf celui du socle ci-dessous.

Depuis la racine du projet, serveur lancé sur le port 8765. Sur l'interface web, générer le code de mise en service du site `CV` et y créer un compte `aicha` / `motdepasse1` :

```sh
php artisan serve --host=127.0.0.1 --port=8765

mvn -Dtest='SocleBoutEnBoutE2E#phase1*' -Dsurefire.failIfNoSpecifiedTests=false \
    -Dpressing.e2e.url=http://127.0.0.1:8765 -Dpressing.e2e.code=XXXX-XXXX \
    -Dpressing.base=C:/temp/essai/poste.db test
```

Les phases suivantes se lancent de la même façon, sans le code :

| Phase | Avant de la lancer | Ce qu'elle vérifie |
| --- | --- | --- |
| 1 | — | Mise en service, première connexion, envoi d'une écriture |
| 2 | Passer un tarif à 1 750 sur le serveur | Le changement arrive sur le poste |
| 3 | Arrêter le serveur | Connexion et écriture sans Internet |
| 4 | Relancer le serveur | L'écriture en attente part |
| 5 | Révoquer le poste | Le poste se verrouille |

Le changement de tarif et la révocation se font eux aussi depuis l'interface web : c'est ainsi que les phases 1, 2 et 5 ont été rejouées le 7 octobre 2026.

### Journée complète d'une boutique

`BoutiqueBoutEnBoutE2E` met le poste en service avec le code généré sur le web, vérifie que le compte du Promoteur s'y connecte, connecte l'utilisateur avec le compte créé sur le web (n'importe lequel : `-Dpressing.e2e.role` donne son rôle s'il n'est pas réceptionniste), tient une journée entière sans synchroniser (caisse, trois commandes, avances, lot, clôture avec un manque de 500), envoie tout en une synchronisation, puis écrit les chiffres du poste dans un fichier et produit le rapport de caisse en PDF :

```sh
mvn -Dtest='BoutiqueBoutEnBoutE2E' -Dsurefire.failIfNoSpecifiedTests=false \
    -Dpressing.e2e.url=http://127.0.0.1:8765 -Dpressing.e2e.code=XXXX-XXXX \
    -Dpressing.e2e.identifiant=aicha -Dpressing.e2e.motdepasse=motdepasse1 \
    -Dpressing.e2e.promoteur=<identifiant du Promoteur> -Dpressing.e2e.promoteur.motdepasse=<son mot de passe> \
    -Dpressing.base=C:/temp/essai/poste.db -Dpressing.e2e.resume=C:/temp/essai/resume.json \
    -Dpressing.pdf.dossier=C:/temp/essai/pdf test
```

Le 9 octobre 2026, les chiffres du poste ont été comparés à ceux du serveur après cette journée : commandes, encaissements, caisse, lot et rapport financier de la semaine, 36 contrôles, aucun écart.

### Du dépôt à la remise, en passant par l'atelier

`AtelierBoutEnBoutE2E` fait travailler deux vrais postes, celui d'une boutique et celui de l'atelier, qui ne se parlent qu'à travers le serveur. Sur l'interface web, générer le code de mise en service d'une boutique et celui de l'atelier, et créer un compte de réceptionniste (`aicha`) et un compte de chef d'atelier (`chef.az`), mot de passe `motdepasse1`. Les bases des deux postes sont gardées dans le dossier donné, d'une phase à l'autre :

```sh
mvn -Dtest='AtelierBoutEnBoutE2E' -Dsurefire.failIfNoSpecifiedTests=false \
    -Dpressing.e2e.atelier.url=http://127.0.0.1:8765 -Dpressing.e2e.atelier.dossier=C:/temp/essai/postes \
    -Dpressing.e2e.atelier.phase=journee \
    -Dpressing.e2e.atelier.code.boutique=XXXX-XXXX -Dpressing.e2e.atelier.code.atelier=YYYY-YYYY test
```

| Phase | Avant de la lancer | Ce qu'elle vérifie |
| --- | --- | --- |
| `journee` | — | Trois commandes et un lot aller en boutique ; à l'atelier : réception, contrôle (conforme, écart, observation), un laveur et un repasseur enrôlés, chaque article lavé puis repassé, lot retour, consommables, dépôt direct encaissé et remis ; en boutique : accusé de réception du lot aller, correction reçue, lot retour reçu, solde encaissé, commande remise. Enfin le chef d'atelier se connecte sur le poste de la boutique : il y retrouve son atelier, dans une base à part de celle d'Aicha. Aucune écriture refusée |
| `apres-delai` | Saisir le délai d'accord sur anomalie à la page « Paramètres », puis, le délai passé, lancer `php artisan pressing:corrections-appliquer` | Les deux postes reçoivent la commande débloquée et sa facture corrigée ; l'atelier la traite |

Le 10 octobre 2026, ce que les deux postes savaient a été comparé à ce que le serveur avait enregistré après la journée : commandes, lots, travailleurs et commissions, consommables, écritures reçues, 41 contrôles, aucun écart. Les deux phases ont été rejouées avec succès le 14 octobre 2026 sur le serveur révisé, puis le 16 octobre 2026 avec une base par personne et deux étapes par article (41 contrôles, aucun écart).

### Accord du client, trop-perçu et annulations

`BoutiqueSuiteBoutEnBoutE2E` fait travailler les mêmes deux postes, avec les mêmes préparatifs sur le web, sur une base neuve, et une seconde réceptionniste de la boutique (`mariam`, mot de passe `motdepasse1`) :

```sh
mvn -Dtest='BoutiqueSuiteBoutEnBoutE2E' -Dsurefire.failIfNoSpecifiedTests=false \
    -Dpressing.e2e.suite.url=http://127.0.0.1:8765 -Dpressing.e2e.suite.dossier=C:/temp/essai/suite \
    -Dpressing.e2e.suite.phase=journee \
    -Dpressing.e2e.suite.code.boutique=XXXX-XXXX -Dpressing.e2e.suite.code.atelier=YYYY-YYYY test
```

| Phase | Avant de la lancer | Ce qu'elle vérifie |
| --- | --- | --- |
| `journee` | — | En boutique : quatre commandes, dont une payée en entier au dépôt ; deux demandes d'annulation ; un lot aller. À l'atelier : réception, écart relevé sur la commande payée. En boutique : accord du client, facture corrigée, trop-perçu constaté. L'atelier reçoit la commande débloquée. Aucune écriture refusée |
| `apres-decision` | Sur le web : le tableau de bord signale le trop-perçu ; à la page « Annulations », confirmer la première demande et refuser la seconde avec le motif « Le client a deja paye le transport » | La boutique reçoit les deux décisions, restitue l'avance de la commande annulée et rend le trop-perçu. Puis Mariam se connecte sur le même poste : sa base est créée, elle ouvre sa propre caisse pendant que celle d'Aicha est ouverte, encaisse un versement, et ce versement arrive dans la base d'Aicha. Aucune écriture refusée ; sur le serveur, plus aucun trop-perçu à rendre |

Essai réussi le 14 octobre 2026.

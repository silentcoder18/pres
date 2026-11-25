<?php

use App\Http\Controllers\Promoteur\AccordController;
use App\Http\Controllers\Promoteur\AnnulationController;
use App\Http\Controllers\Promoteur\CaisseController;
use App\Http\Controllers\Promoteur\CatalogueController;
use App\Http\Controllers\Promoteur\ClientController;
use App\Http\Controllers\Promoteur\CommandeController;
use App\Http\Controllers\Promoteur\CompteController;
use App\Http\Controllers\Promoteur\ConnexionController;
use App\Http\Controllers\Promoteur\EcritureController;
use App\Http\Controllers\Promoteur\FinanceController;
use App\Http\Controllers\Promoteur\JournalController;
use App\Http\Controllers\Promoteur\LotController;
use App\Http\Controllers\Promoteur\MessageController;
use App\Http\Controllers\Promoteur\ParametreController;
use App\Http\Controllers\Promoteur\PosteController;
use App\Http\Controllers\Promoteur\SiteController;
use App\Http\Controllers\Promoteur\TableauDeBordController;
use App\Http\Controllers\Promoteur\TravailleurController;
use Illuminate\Support\Facades\Route;

// Interface web du Promoteur (cahier des charges v2, §9.3). Tout se fait en ligne ; ce qu'il
// change ici est transmis en priorite aux postes a leur synchronisation suivante (§4.6).

Route::redirect('/', '/tableau-de-bord');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [ConnexionController::class, 'formulaire'])->name('connexion');
    Route::post('/connexion', [ConnexionController::class, 'connecter'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'promoteur', 'atomique'])->group(function () {
    Route::post('/deconnexion', [ConnexionController::class, 'deconnecter'])->name('deconnexion');

    Route::get('/tableau-de-bord', TableauDeBordController::class)->name('tableau-de-bord');

    // Suivi du reseau : consultation seule
    Route::get('/commandes', [CommandeController::class, 'index'])->name('commandes.index');
    Route::get('/commandes/{commande}', [CommandeController::class, 'show'])->name('commandes.show');
    // Commandes bloquees dans l'attente de l'accord du client sur une correction (RG-09)
    Route::get('/accords', [AccordController::class, 'index'])->name('accords.index');
    Route::get('/lots', [LotController::class, 'index'])->name('lots.index');
    Route::get('/lots/{lot}', [LotController::class, 'show'])->name('lots.show');
    Route::get('/caisses', [CaisseController::class, 'index'])->name('caisses.index');
    Route::get('/caisses/{session}', [CaisseController::class, 'show'])->name('caisses.show');
    // Finances : situation hebdomadaire calculee par le serveur (§7), et son rapport en PDF
    Route::get('/finances', [FinanceController::class, 'index'])->name('finances.index');
    Route::get('/finances/rapport.pdf', [FinanceController::class, 'rapport'])->name('finances.rapport');
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/ecritures-refusees', [EcritureController::class, 'index'])->name('ecritures.index');
    Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');

    // Demandes d'annulation des receptionnistes : le Promoteur confirme ou refuse (RG-24)
    Route::get('/annulations', [AnnulationController::class, 'index'])->name('annulations.index');
    Route::post('/annulations/{demande}/confirmation', [AnnulationController::class, 'confirmer'])->name('annulations.confirmer');
    Route::post('/annulations/{demande}/refus', [AnnulationController::class, 'refuser'])->name('annulations.refuser');

    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clients/doublons', [ClientController::class, 'doublons'])->name('clients.doublons');
    Route::post('/clients/doublons/{reunion}', [ClientController::class, 'trancher'])->name('clients.trancher');
    // Travailleurs de l'atelier, avec leurs donnees d'identite (§9.3, §11)
    Route::get('/travailleurs', [TravailleurController::class, 'index'])->name('travailleurs.index');

    // Sites et postes
    Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::put('/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    Route::post('/sites/{site}/postes', [PosteController::class, 'store'])->name('postes.store');
    Route::post('/postes/{poste}/code', [PosteController::class, 'renouvelerCode'])->name('postes.code');
    Route::post('/postes/{poste}/revocation', [PosteController::class, 'revoquer'])->name('postes.revoquer');

    // Comptes (RG-15, RG-16)
    Route::get('/comptes', [CompteController::class, 'index'])->name('comptes.index');
    Route::get('/comptes/nouveau', [CompteController::class, 'create'])->name('comptes.create');
    Route::post('/comptes', [CompteController::class, 'store'])->name('comptes.store');
    Route::get('/comptes/{compte}', [CompteController::class, 'edit'])->name('comptes.edit');
    Route::put('/comptes/{compte}', [CompteController::class, 'update'])->name('comptes.update');
    Route::put('/comptes/{compte}/mot-de-passe', [CompteController::class, 'motDePasse'])->name('comptes.mot-de-passe');

    // Catalogue et tarifs
    Route::get('/catalogue', [CatalogueController::class, 'index'])->name('catalogue.index');
    Route::post('/catalogue/articles', [CatalogueController::class, 'creerArticle'])->name('catalogue.articles.store');
    Route::put('/catalogue/articles/{article}', [CatalogueController::class, 'modifierArticle'])->name('catalogue.articles.update');
    Route::post('/catalogue/prestations', [CatalogueController::class, 'creerService'])->name('catalogue.services.store');
    Route::put('/catalogue/prestations/{service}', [CatalogueController::class, 'modifierService'])->name('catalogue.services.update');
    Route::post('/catalogue/tarifs', [CatalogueController::class, 'creerTarif'])->name('catalogue.tarifs.store');
    Route::put('/catalogue/tarifs/{tarif}', [CatalogueController::class, 'modifierTarif'])->name('catalogue.tarifs.update');

    // Parametres (RG-23)
    Route::get('/parametres', [ParametreController::class, 'index'])->name('parametres.index');
    Route::put('/parametres', [ParametreController::class, 'update'])->name('parametres.update');
});

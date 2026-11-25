<?php

namespace App\Console\Commands;

use App\Models\Utilisateur;
use Illuminate\Console\Command;

/**
 * Cree un compte Promoteur. C'est la seule creation de compte qui se fait hors de l'interface
 * web : aucun compte n'est livre avec l'application (RG-15), il faut donc bien un premier
 * Promoteur pour y entrer. Tous les autres comptes, comme les postes et leurs codes de mise en
 * service, se creent sur l'interface web, ou chaque action est verifiee et notee au journal.
 */
class UtilisateurCreer extends Command
{
    protected $signature = 'pressing:utilisateur-creer
        {identifiant : Identifiant de connexion}
        {nom : Nom affiché}
        {role=PROMOTEUR : Toujours PROMOTEUR ; les autres comptes se créent sur l\'interface web}
        {--site= : Sans objet, un Promoteur n\'est rattaché à aucun site}
        {--mot-de-passe= : Mot de passe ; demandé à l\'écran s\'il est absent}';

    protected $description = "Crée un compte Promoteur (les autres comptes se créent sur l'interface web)";

    public function handle(): int
    {
        if (strtoupper($this->argument('role')) !== Utilisateur::PROMOTEUR || $this->option('site')) {
            $this->error('Cette commande ne crée que des comptes Promoteur.');
            $this->line("Les comptes des réceptionnistes et du chef d'atelier se créent sur l'interface web, page « Comptes ».");

            return self::FAILURE;
        }

        if (Utilisateur::where('identifiant', $this->argument('identifiant'))->exists()) {
            $this->error('Cet identifiant est déjà utilisé.');

            return self::FAILURE;
        }

        $motDePasse = $this->option('mot-de-passe') ?: (string) $this->secret('Mot de passe');
        if (strlen($motDePasse) < 8 || ! preg_match('/\pL/u', $motDePasse) || ! preg_match('/\d/', $motDePasse)) {
            $this->error('Le mot de passe doit comporter au moins 8 caractères, dont une lettre et un chiffre.');

            return self::FAILURE;
        }

        Utilisateur::create([
            'identifiant' => $this->argument('identifiant'),
            'nom' => $this->argument('nom'),
            'role' => Utilisateur::PROMOTEUR,
            'site_id' => null,
            'mot_de_passe' => $motDePasse,
        ]);

        $this->info("Compte Promoteur « {$this->argument('identifiant')} » créé.");
        $this->line("Connectez-vous sur l'interface web pour créer les sites, générer les codes de mise en service des postes et créer les comptes.");

        return self::SUCCESS;
    }
}

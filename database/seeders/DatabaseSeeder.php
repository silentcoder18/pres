<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Donnees indispensables a toute installation. Aucun compte n'est cree
     * ici (RG-15) : voir la commande pressing:utilisateur-creer.
     */
    public function run(): void
    {
        $this->call(ParametresSeeder::class);
    }
}

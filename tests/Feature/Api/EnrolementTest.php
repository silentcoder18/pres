<?php

namespace Tests\Feature\Api;

use App\Models\Poste;

class EnrolementTest extends ApiPosteTestCase
{
    private function posteAEnroler(string $code = 'ABCD2345', ?\DateTimeInterface $expireLe = null): Poste
    {
        return Poste::create([
            'site_id' => $this->creerSite()->id,
            'nom' => 'Poste principal',
            'code_enrolement_hash' => Poste::empreinte($code),
            'code_expire_le' => $expireLe ?? now()->addDay(),
        ]);
    }

    public function test_un_code_valide_donne_un_jeton_et_l_identite_du_site(): void
    {
        $poste = $this->posteAEnroler();

        $reponse = $this->postJson('/api/postes/enroler', ['code' => 'abcd-2345', 'nom_machine' => 'PC-BOUTIQUE']);

        $reponse->assertOk()
            ->assertJsonPath('poste.id', $poste->id)
            ->assertJsonPath('site.code', 'CV')
            ->assertJsonStructure(['jeton', 'heure_serveur']);

        $poste->refresh();
        $this->assertSame(Poste::empreinte($reponse->json('jeton')), $poste->jeton_hash);
        $this->assertNull($poste->code_enrolement_hash);
        $this->assertSame('PC-BOUTIQUE', $poste->nom_machine);
    }

    public function test_le_code_ne_sert_qu_une_fois(): void
    {
        $this->posteAEnroler();

        $this->postJson('/api/postes/enroler', ['code' => 'ABCD2345'])->assertOk();

        $this->postJson('/api/postes/enroler', ['code' => 'ABCD2345'])
            ->assertStatus(422)
            ->assertJsonPath('erreur', 'code_invalide');
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $this->posteAEnroler('ABCD2345', now()->subMinute());

        $this->postJson('/api/postes/enroler', ['code' => 'ABCD2345'])
            ->assertStatus(422)
            ->assertJsonPath('erreur', 'code_invalide');
    }

    public function test_un_code_inconnu_est_refuse(): void
    {
        $this->posteAEnroler();

        $this->postJson('/api/postes/enroler', ['code' => 'ZZZZ9999'])
            ->assertStatus(422)
            ->assertJsonPath('erreur', 'code_invalide');
    }
}

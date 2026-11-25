<?php

namespace App\Services\Finance;

use App\Models\Site;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Rapport financier hebdomadaire en PDF (format A4), a partir de ce que calcule
 * RapportHebdomadaire. La mise en page est dans la vue ; ici, seulement la fabrication du fichier.
 *
 * dompdf est en PHP pur : il convient a un hebergement mutualise, sans programme a installer.
 */
class RapportPdf
{
    public const VUE = 'promoteur.finances.rapport-pdf';

    /** Police livree avec dompdf : elle porte les accents et l'espace insecable des montants. */
    private const POLICE = 'DejaVu Sans';

    /** Marges laterales et hauteur du pied de page, en points (1 mm = 2,835 points). */
    private const MARGE = 39.7;

    private const PIED = 30.0;

    /** Memoire qu'il faut au rapport d'un grand reseau. */
    private const MEMOIRE = '256M';

    /** La page telle qu'elle sera imprimee, avant sa mise en PDF : c'est elle que les essais relisent. */
    public function html(array $rapport, string $auteur): string
    {
        return view(self::VUE, [
            'rapport' => $rapport,
            'auteur' => $auteur,
            'logo' => $this->logo(),
        ])->render();
    }

    /** @return string le contenu du fichier PDF */
    public function generer(array $rapport, string $auteur): string
    {
        $this->reserverLaMemoire();
        $dossier = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($dossier);

        $options = new Options;
        $options->setDefaultFont(self::POLICE);
        $options->setDefaultPaperSize('a4');
        // Rien ne vient de l'exterieur et rien ne s'execute dans le document
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        // Seul le dossier du logo est lisible depuis le document
        $options->setChroot([public_path('images')]);
        // Les mesures de police et les fichiers de travail vont dans storage/, jamais dans vendor/
        $options->setFontDir($dossier);
        $options->setFontCache($dossier);
        $options->setTempDir($dossier);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml($this->html($rapport, $auteur), 'UTF-8');
        $dompdf->render();
        $this->numeroterLesPages($dompdf, $rapport);

        return (string) $dompdf->output();
    }

    /** rapport-financier-2026-S41.pdf pour le reseau, rapport-financier-2026-S41-CV.pdf pour un site. */
    public function nomFichier(SemaineComptable $semaine, ?Site $site = null): string
    {
        $suffixe = $site === null ? '' : '-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $site->code);

        return 'rapport-financier-'.$semaine->reference().$suffixe.'.pdf';
    }

    /**
     * dompdf garde tout le document en memoire : environ 70 Mo pour 4 sites, 100 Mo pour une
     * douzaine, 190 Mo pour 40 sites et leurs 240 caisses. La limite du serveur est relevee
     * jusqu'a MEMOIRE pour cette seule requete, la ou l'hebergeur le permet.
     */
    private function reserverLaMemoire(): void
    {
        $limite = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limite > 0 && $limite < ini_parse_quantity(self::MEMOIRE)) {
            @ini_set('memory_limit', self::MEMOIRE);
        }
    }

    /** Pied de chaque page : ce qu'est le document a gauche, "Page 1 / 2" a droite. */
    private function numeroterLesPages(Dompdf $dompdf, array $rapport): void
    {
        $canevas = $dompdf->getCanvas();
        $mesures = $dompdf->getFontMetrics();
        $police = $mesures->getFont(self::POLICE, 'normal');
        $taille = 7.5;
        $gris = [0.29, 0.36, 0.46];
        $hauteur = $canevas->get_height() - self::PIED;

        $perimetre = $rapport['perimetre'] === null ? 'Réseau entier' : $rapport['perimetre']->nom;
        $canevas->page_text(
            self::MARGE, $hauteur,
            'GePressing · Rapport financier hebdomadaire · '.$rapport['semaine']->libelle().' · '.$perimetre,
            $police, $taille, $gris,
        );
        // Place gardee pour le plus long numero attendu : le texte reste aligne a droite
        $largeur = $mesures->getTextWidth('Page 00 / 00', $police, $taille);
        $canevas->page_text(
            $canevas->get_width() - self::MARGE - $largeur, $hauteur,
            'Page {PAGE_NUM} / {PAGE_COUNT}', $police, $taille, $gris,
        );
    }

    /**
     * Chemin du logo sur le disque, ou null s'il ne peut pas etre insere : il est en PNG avec
     * transparence, que dompdf ne sait lire qu'avec l'extension gd (ou imagick). Le rapport sort
     * alors sans logo, avec le nom du reseau en toutes lettres.
     */
    private function logo(): ?string
    {
        $fichier = public_path('images/logo-visuelplus.png');

        return is_file($fichier) && (extension_loaded('gd') || extension_loaded('imagick')) ? $fichier : null;
    }
}

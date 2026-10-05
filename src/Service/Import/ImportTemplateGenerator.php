<?php

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Génère le tableur modèle téléchargeable depuis l'écran d'import.
 *
 * - Onglet unique "Prospects"
 * - Ligne 1 : en-têtes (Organisation, Nom, Prénom, Courriel, ...)
 * - 5 lignes d'exemple (fond gris) avec valeurs fictives
 * - Aucune formule, aucun total
 * - Téléphone + Code postal formatés en texte pour préserver le "0" initial
 */
final class ImportTemplateGenerator
{
    public const COLUMNS = [
        'Organisation',
        'Nom',
        'Prénom',
        'Courriel',
        'Fonction',
        'Téléphone',
        'Adresse',
        'Code postal',
        'Ville',
        'Site web',
    ];

    /**
     * Lignes d'exemple (utilise des données fictives @example.com).
     * Couvre : email + téléphone français + code postal avec zéro initial.
     */
    public const EXAMPLES = [
        ['Conseil Départemental du Cher', 'DUPONT', 'Marie', 'marie.dupont@example.com', 'Directrice', '02 48 00 00 00', '1 place de la Préfecture', '18000', 'Bourges', 'https://www.example.fr'],
        ['Lycée Jacques Cœur', 'MARTIN', 'Pierre', 'pierre.martin@example.com', 'Proviseur', '02 54 00 00 00', '10 rue des Capucins', '18000', 'Bourges', 'https://www.example.fr'],
        ['Université de Tours', 'BERNARD', 'Sophie', 'sophie.bernard@example.com', 'Présidente', '02 47 00 00 00', '60 rue du Plat d\'Étain', '37000', 'Tours', 'https://www.example.fr'],
        ['Conseil Départemental du Cher', 'PETIT', 'Jean', 'jean.petit@example.com', 'Responsable formation', '06 00 00 00 00', '', '18000', 'Bourges', ''],
        ['Mairie de Bourges', 'ROBERT', 'Anne', 'anne.robert@example.com', 'Adjointe au maire', '02 48 00 00 00', '11 rue Jacques Rémy', '18000', 'Bourges', 'https://www.example.fr'],
    ];

    /**
     * Génère le contenu XLSX et le retourne sous forme de chaîne binaire.
     */
    public function generate(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Prospects');

        // 1) En-têtes
        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $headerStyle = $sheet->getStyle('A1:J1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFont()->setColor(new Color('FFFFFF'));
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID);
        $headerStyle->getFill()->getStartColor()->setRGB('0D6EFD');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 2) Exemples (lignes 2 à 6)
        $sheet->fromArray(self::EXAMPLES, null, 'A2');

        // 3) Format texte pour Téléphone (col F) et Code postal (col H) — préserve le "0"
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle('F2:F'.$highestRow)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('H2:H'.$highestRow)->getNumberFormat()->setFormatCode('@');

        // 4) Style des exemples (fond gris clair + italique pour montrer que ce sont des exemples)
        $exampleStyle = $sheet->getStyle('A2:J'.$highestRow);
        $exampleStyle->getFill()->setFillType(Fill::FILL_SOLID);
        $exampleStyle->getFill()->getStartColor()->setRGB('F8F9FA');
        $exampleStyle->getFont()->setItalic(true);

        // 5) Largeurs de colonnes
        $sheet->getColumnDimension('A')->setWidth(35); // Organisation
        $sheet->getColumnDimension('B')->setWidth(18); // Nom
        $sheet->getColumnDimension('C')->setWidth(18); // Prénom
        $sheet->getColumnDimension('D')->setWidth(30); // Courriel
        $sheet->getColumnDimension('E')->setWidth(22); // Fonction
        $sheet->getColumnDimension('F')->setWidth(18); // Téléphone
        $sheet->getColumnDimension('G')->setWidth(32); // Adresse
        $sheet->getColumnDimension('H')->setWidth(12); // Code postal
        $sheet->getColumnDimension('I')->setWidth(18); // Ville
        $sheet->getColumnDimension('J')->setWidth(30); // Site web

        // 6) Génération du binaire xlsx
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}

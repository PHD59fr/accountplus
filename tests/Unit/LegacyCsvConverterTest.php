<?php

declare(strict_types=1);

use App\Service\LegacyCsvConverter;
use App\Support\Archive;

/**
 * @return array<string, callable>
 */
return [
    'converts a minimal legacy CSV set into a compte-import archive' => static function (): void {
        $dir = sys_get_temp_dir() . '/csv_conv_' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/BANQUE.csv', "NUMERO,LIBELLE,TITULAIRE1,NOMBANQUE,COMPTE,ARCHIVE\n0,\"Compte courant\",\"FINANCIERE SD\",SG,\"123456789\",0\n");
        file_put_contents($dir . '/ECRITURE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n0,\"2026-06-30 00:00:00\",\"VIR\",,-291.3800,-44.4200,E,\"KPMG\",,\"VIR KPMG\",0,0\n");
        file_put_contents($dir . '/ARCHIVE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n0,\"2025-01-05 00:00:00\",,,,-1697.8100,-258.8300,E,\"URSSAF\",,,1,0\n");
        file_put_contents($dir . '/THEME1.csv', "THEME1\n\"KPMG\"\n\"URSSAF\"\n");
        file_put_contents($dir . '/THEME2.csv', "THEME1,THEME2\n");
        file_put_contents($dir . '/CASH.csv', "PASSE,PASSECHAINE,VERSION_4,TAUX_EURO\n0,,\"5.00\",6.55957\n");
        file_put_contents($dir . '/TYPE.csv', "TYPE\n\"VIR\"\n\"PRE\"\n");
        file_put_contents($dir . '/VIREMENT.csv', "NUM_COMPTE,TYPE_VIREMENT,DATE_DEBUT,DATE_FIN,DATE_PROCHAIN,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT\n");

        $output = $dir . '/out.zip';
        $result = (new LegacyCsvConverter())->convert($dir, $output);

        $entries = Archive::read($output);
        foreach (['manifest.json', 'accounts.ndjson', 'categories.ndjson', 'transactions.ndjson'] as $required) {
            if (!isset($entries[$required])) {
                throw new RuntimeException('Archive is missing entry ' . $required);
            }
        }

        $accounts = array_map('json_decode', array_filter(explode("\n", trim($entries['accounts.ndjson']))));
        $transactions = array_map('json_decode', array_filter(explode("\n", trim($entries['transactions.ndjson']))));

        if ($result['counts']['accounts'] !== 1) {
            throw new RuntimeException('Expected 1 account, got ' . $result['counts']['accounts'] . '.');
        }
        if ($accounts[0]->legacy_id !== '0' || $accounts[0]->currency !== 'EUR') {
            throw new RuntimeException('Account mapping is incorrect.');
        }
        if (!empty($accounts[0]->is_archived)) {
            throw new RuntimeException('Imported accounts must not be archived.');
        }
        if ($result['counts']['transactions'] !== 2) {
            throw new RuntimeException('Expected 2 transactions, got ' . $result['counts']['transactions'] . '.');
        }
        if ($transactions[0]->amount !== '-44.42' || $transactions[0]->transaction_date !== '2026-06-30') {
            throw new RuntimeException('Transaction amount or date is not normalized.');
        }
        if (!isset($transactions[0]->type) || $transactions[0]->type !== 'VIR') {
            throw new RuntimeException('Transaction TYPE was not mapped to a top-level field.');
        }

        @unlink($output);
        foreach (glob($dir . '/*.csv') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    },

    'skips the year-end Archivage carry-forward rows' => static function (): void {
        $dir = sys_get_temp_dir() . '/csv_conv_' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/BANQUE.csv', "NUMERO,LIBELLE,ARCHIVE\n0,\"Compte courant\",0\n");
        file_put_contents($dir . '/ECRITURE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n0,\"2026-05-31 00:00:00\",,,83890.6300,12789.0300,2,\"Banque\",\"Archivage\",\"Archivage\",1,1\n0,\"2026-06-01 00:00:00\",\"VIR\",,-291.3800,-44.4200,E,\"KPMG\",,\"VIR KPMG\",0,0\n");
        file_put_contents($dir . '/ARCHIVE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n");
        file_put_contents($dir . '/THEME1.csv', "THEME1\n\"KPMG\"\n");
        file_put_contents($dir . '/THEME2.csv', "THEME1,THEME2\n\"Banque\",\"Archivage\"\n");
        file_put_contents($dir . '/CASH.csv', "PASSE,PASSECHAINE,VERSION_4,TAUX_EURO\n0,,\"5.00\",6.55957\n");
        file_put_contents($dir . '/TYPE.csv', "TYPE\n\"VIR\"\n");
        file_put_contents($dir . '/VIREMENT.csv', "NUM_COMPTE,TYPE_VIREMENT,DATE_DEBUT,DATE_FIN,DATE_PROCHAIN,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT\n");

        $output = $dir . '/out.zip';
        $result = (new LegacyCsvConverter())->convert($dir, $output);

        $entries = Archive::read($output);
        $transactions = array_map('json_decode', array_filter(explode("\n", trim($entries['transactions.ndjson']))));

        if (count($transactions) !== 1) {
            throw new RuntimeException('Expected 1 transaction after skipping the Archivage row, got ' . count($transactions) . '.');
        }
        if ($transactions[0]->label !== 'VIR KPMG') {
            throw new RuntimeException('The wrong transaction survived.');
        }

        @unlink($output);
        foreach (glob($dir . '/*.csv') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    },

    'maps THEME2 pairs as child categories' => static function (): void {
        $dir = sys_get_temp_dir() . '/csv_conv_' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/BANQUE.csv', "NUMERO,LIBELLE,ARCHIVE\n0,\"Compte courant\",0\n");
        file_put_contents($dir . '/ECRITURE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n");
        file_put_contents($dir . '/ARCHIVE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n");
        file_put_contents($dir . '/THEME1.csv', "THEME1\n\"Banque\"\n\"Course\"\n");
        file_put_contents($dir . '/THEME2.csv', "THEME1,THEME2\n\"Banque\",\"Agios\"\n\"Banque\",\"Archivage\"\n");
        file_put_contents($dir . '/CASH.csv', "PASSE,PASSECHAINE,VERSION_4,TAUX_EURO\n0,,\"5.00\",6.55957\n");
        file_put_contents($dir . '/TYPE.csv', "TYPE\n");
        file_put_contents($dir . '/VIREMENT.csv', "NUM_COMPTE,TYPE_VIREMENT,DATE_DEBUT,DATE_FIN,DATE_PROCHAIN,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT\n");

        $output = $dir . '/out.zip';
        $result = (new LegacyCsvConverter())->convert($dir, $output);

        $entries = Archive::read($output);
        $categories = array_map('json_decode', array_filter(explode("\n", trim($entries['categories.ndjson']))));

        if ($result['counts']['categories'] !== 4) {
            throw new RuntimeException('Expected 4 categories (2 parents + 2 children), got ' . $result['counts']['categories'] . '.');
        }
        $children = array_values(array_filter($categories, static fn ($c): bool => $c->parent_legacy_id !== null));
        if (count($children) !== 2 || $children[0]->parent_legacy_id !== 'Banque') {
            throw new RuntimeException('THEME2 pairs were not mapped to child categories.');
        }
        foreach ($categories as $category) {
            if (($category->category_type ?? '') !== '') {
                throw new RuntimeException('Categories must import with an empty category_type for manual assignment.');
            }
        }

        @unlink($output);
        foreach (glob($dir . '/*.csv') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    },

    'maps the legacy TYPE reference into transaction types' => static function (): void {
        $dir = sys_get_temp_dir() . '/csv_types_' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/BANQUE.csv', "NUMERO,LIBELLE,TITULAIRE1,NOMBANQUE,COMPTE,ARCHIVE\n0,\"Compte courant\",\"FINANCIERE SD\",SG,\"123\",0\n");
        file_put_contents($dir . '/ECRITURE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n");
        file_put_contents($dir . '/ARCHIVE.csv', "NUM_COMPTE,DT_ECRITURE,TYPE,PIECE,MONTANT,MONTANT_EURO,SAISIE_EURO,THEME1,THEME2,COMMENT,COCHE,ARCHIVE\n");
        file_put_contents($dir . '/THEME1.csv', "THEME1\n\"KPMG\"\n");
        file_put_contents($dir . '/THEME2.csv', "THEME1,THEME2\n");
        file_put_contents($dir . '/TYPE.csv', "TYPE\n\"CB\"\n\"CH\"\n\"VIR\"\n");

        $output = $dir . '/out.zip';
        $result = (new LegacyCsvConverter())->convert($dir, $output);
        if ($result['counts']['transaction_types'] !== 3) {
            throw new RuntimeException('Expected 3 transaction types, got ' . $result['counts']['transaction_types'] . '.');
        }

        $entries = Archive::read($output);
        $types = array_map('json_decode', array_filter(explode("\n", trim($entries['transaction_types.ndjson']))));
        $codes = array_map(static fn ($t): string => $t->code, $types);
        sort($codes);
        if ($codes !== ['CB', 'CH', 'VIR']) {
            throw new RuntimeException('Transaction types were not mapped from the legacy TYPE table.');
        }

        @unlink($output);
        foreach (glob($dir . '/*.csv') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    },
];

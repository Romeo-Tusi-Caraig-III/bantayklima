<?php
// Run with:  php tests/pagasa_test.php
// The samples below are text extracted from PAGASA's public "Drought and Dry Spell Assessment" PDFs.
define('TEST_ONLY', true);
require __DIR__ . '/../lib/bootstrap.php';

$fail = 0;
function check(string $name, $expected, $actual): void {
    global $fail;
    if ($expected === $actual) { echo "PASS $name\n"; return; }
    $fail++;
    echo "FAIL $name: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
}

// May 2026 table (issued 2026-06-02), followed by the outlook table that must be ignored
$may2026 = <<<'T'
OVER DIFFERENT PROVINCES 
As of May 2026 (ACTUAL)

Issuance: 2026-06-02

AREA DRY CONDITION DRY SPELL DROUGHT

LUZON

0 20 19

None

Aurora, Batanes, Batangas, 
Bulacan, Cagayan, Camarines 
Norte, Cavite, Marinduque, 

Masbate, Metropolitan 
Manila, Occidental Mindoro, 
Oriental Mindoro, Palawan, 

Palawan (Kalayaan), 
Pampanga, Quezon, Rizal, 

Sorsogon, Tarlac, Zambales

Abra, Albay, Apayao, Bataan, 
Benguet, Catanduanes, Ifugao, 
Ilocos Norte, Ilocos Sur, Isabela, 

Kalinga, La Union, Laguna, 
Mountain Province, Nueva Ecija, 

Nueva Vizcaya, Pangasinan, 
Quirino, Romblon

VISAYAS

4 9 0

Bohol, Cebu, Negros Oriental, 
Siquijor

Aklan, Antique, Biliran, Capiz, 
Guimaras, Iloilo, Negros 

Occidental, Northern Samar, 
Samar (Western Samar)

None

MINDANAO
0 0 0

None None None

TOTAL 4 29 19

OUTLOOK OVER DIFFERENT PROVINCES 
by end of Nov 2026

Issuance: 2026-06-02

AREA DRY CONDITION DRY SPELL DROUGHT

LUZON

36 1 0

Abra, Albay, Apayao, Aurora, 
Bataan, Batangas, Benguet, 
Bulacan, Camarines Norte, 

Catanduanes, Cavite, Ifugao, 
Ilocos Norte, Ilocos Sur, 

Isabela, Kalinga, La Union, 
Laguna, Marinduque, 

Masbate, Metropolitan 
Manila, Mountain Province, 
Nueva Ecija, Nueva Vizcaya, 

Occidental Mindoro, Oriental 
Mindoro, Palawan (Kalayaan), 

Pampanga, Pangasinan, 
Quezon, Quirino, Rizal, 

Romblon, Sorsogon, Tarlac, 
Zambales

Camarines Sur None
T;

// June 2026 table (issued 2026-06-22)
$jun2026 = <<<'T'
DRY CONDITION – DRY SPELL –
DROUGHT OUTLOOK

JULY 2026 – DECEMBER 2026

OVER DIFFERENT PROVINCES 

As of Jun 2026 (ACTUAL)

Issuance: 2026-06-22

AREA DRY CONDITION DRY SPELL DROUGHT

LUZON

0 19 16

None

Albay, Aurora, Bataan, 
Batanes, Bulacan, Camarines 

Norte, Camarines Sur, 
Catanduanes, Laguna, 
Marinduque, Masbate, 
Metropolitan Manila, 

Occidental Mindoro, Oriental 
Mindoro, Palawan, Palawan 
(Kalayaan), Quezon, Rizal, 

Romblon

Apayao, Batangas, Benguet, 
Cagayan, Cavite, Ilocos Norte, 
Ilocos Sur, Isabela, La Union, 
Nueva Ecija, Nueva Vizcaya, 

Pampanga, Pangasinan, 
Quirino, Tarlac, Zambales

VISAYAS

0 12 1

None

Aklan, Antique, Biliran, 
Bohol, Capiz, Cebu, 

Guimaras, Iloilo, Negros

OUTLOOK OVER DIFFERENT PROVINCES

by end of Jul 2026

Issuance: 2026-06-22

AREA DRY CONDITION DRY SPELL DROUGHT
T;

// March 2024 table (issued 2024-04-03): a different Laguna column, and "Spratly Islands"
$mar2024 = <<<'T'
OVER DIFFERENT PROVINCES 
As of Mar 2024 (ACTUAL)

Issuance: 2024-04-03

AREA DRY CONDITION DRY SPELL DROUGHT

LUZON

9 3 19

Batanes, Camarines Norte, 
Camarines Sur, Catanduanes, 

Marinduque, Quezon, 
Romblon, Sorsogon, Spratly 
Islands

Batangas, Laguna, Masbate

Abra, Apayao, Benguet, 
Cagayan, Cavite, Ifugao, Ilocos 

Norte, Ilocos Sur, Isabela, 
Kalinga, La Union, Metropolitan 
Manila, Mountain Province, 
Nueva Ecija, Nueva Vizcaya, 

Occidental Mindoro, Oriental 
Mindoro, Palawan, Rizal

VISAYAS

2 3 11
T;

// March 2026 table (Laguna not listed), then an April table that got cut off while copying
$mar2026 = <<<'T'
OVER DIFFERENT PROVINCES 
As of Mar 2026 (ACTUAL)

Issuance: 2026-04-21

AREA DRY CONDITION DRY SPELL DROUGHT

LUZON

5 8 7

Batangas, Cagayan, Cavite, 
Pampanga, Zambales

Apayao, Benguet, Isabela, 
Nueva Ecija, Nueva Vizcaya, 
Pangasinan, Quirino, Tarlac

Abra, Ifugao, Ilocos Norte, Ilocos 
Sur, Kalinga, La Union, Mountain 

Province

VISAYAS
0 0 0
T;
$cutOff = $mar2026 . <<<'T'


OVER DIFFERENT PROVINCES 
As of Apr 2026 (ACTUAL)

Issuance: 2026-04-21

AREA DRY CONDITION DRY SPELL DROUGHT

LUZON

1 22 15

Aurora

Albay, Bataan, Batangas, 
Bulacan, Cagayan, Camarines 

Norte, Camarines Sur, 
Catanduanes, Cavite, Laguna, 

Marinduque, Masbate, 
Metropolitan Manila, 

Occidental Mindoro, Oriental 
Mindoro, Palawan, 

Pampanga, Quezon, Rizal,
T;

$r = parse_dds($may2026);
check('May 2026: Laguna is in drought', ['drought', '2026-05-01', '2026-06-02', [0, 20, 19]], [$r['status'], $r['month'], $r['issued'], $r['counts']]);
check('May 2026: outlook table is ignored', 19, count($r['lists']['drought']));

$r = parse_dds($jun2026);
check('June 2026: Laguna is in dry spell', ['dry_spell', '2026-06-01', '2026-06-22', [0, 19, 16]], [$r['status'], $r['month'], $r['issued'], $r['counts']]);
check('June 2026: Palawan (Kalayaan) counted separately', true, in_array('Palawan (Kalayaan)', $r['lists']['dry_spell'], true) && in_array('Palawan', $r['lists']['dry_spell'], true));

$r = parse_dds($mar2024);
check('March 2024: Laguna dry spell, Spratly Islands recognised', ['dry_spell', 3, '2024-03-01'], [$r['status'], count($r['lists']['dry_spell']), $r['month']]);

$r = parse_dds($mar2026);
check('Laguna not listed means normal', ['normal', 20], [$r['status'], array_sum($r['counts'])]);

$r = parse_dds($cutOff);
check('cut-off paste is rejected (counts do not add up)', false, $r['ok']);
check('...with a helpful message', true, str_contains($r['error'], 'counts add up'));

check('empty paste is rejected', false, parse_dds('   ')['ok']);
check('random text is rejected', false, parse_dds('El Niño Advisory No. 2 ... nothing useful here')['ok']);
check('uppercase 2023-style table is rejected, not guessed', false, parse_dds("As of October 31, 2023 (ACTUAL) LUZON (5) KALINGA APAYAO CAGAYAN LAGUNA PALAWAN")['ok']);

// October 2026 "PH Meteorological Drought Monitor" PDF: each count is followed by its own names
$oct2026 = <<<'T'
PH Meteorological Drought Monitor
Dry Condition / Dry Spell / Drought Assessment · as of October 2026
Area Dry condition Dry spell Drought
LUZON
10
Abra, Batanes, Cagayan,
Isabela, Kalinga, Marinduque,
Oriental Mindoro, Palawan,
Quezon, Romblon
2
Masbate, Sorsogon
4
Albay, Camarines Norte,
Camarines Sur, Catanduanes
VISAYAS
4
Aklan, Antique, Guimaras, Iloilo
7
Biliran, Bohol, Capiz, Cebu,
Negros Occidental, Northern
Samar, Southern Leyte
4
Leyte, Negros Oriental, Samar,
Siquijor
MINDANAO
None
23
Agusan del Norte, Basilan, Misamis Occidental
1
Misamis Oriental
TOTAL 14 32 9
T;
$r = parse_dds($oct2026);
check('Oct 2026 (new layout): Laguna not listed means normal', ['normal', '2026-10-01', [10, 2, 4]], [$r['status'], $r['month'], $r['counts']]);
check('Oct 2026: Palawan and Quezon are dry condition', true, in_array('Palawan', $r['lists']['dry_condition'], true) && in_array('Quezon', $r['lists']['dry_condition'], true));
check('Oct 2026: Albay is drought, Sorsogon dry spell', [true, true], [in_array('Albay', $r['lists']['drought'], true), in_array('Sorsogon', $r['lists']['dry_spell'], true)]);
check('Oct 2026: another province can be asked for', 'dry_condition', parse_dds($oct2026, 'Quezon')['status']);
check('Oct 2026: Visayas names are not mixed into Luzon', 16, count(array_merge(...array_values($r['lists']))));

$r = parse_dds(str_replace('Masbate, Sorsogon', 'Masbate, Sorsogon, Laguna', str_replace("\n2\n", "\n3\n", $oct2026)));
check('new layout: Laguna in the dry spell cell', ['dry_spell', [10, 3, 4]], [$r['status'], $r['counts']]);

$r = parse_dds(preg_replace('/LUZON\n10\n.*?\n2\nMasbate/s', "LUZON\nNone\n2\nMasbate", $oct2026));
check('new layout: "None" cell means zero', [true, [0, 2, 4]], [$r['ok'], $r['counts'] ?? null]);

$r = parse_dds(str_replace('Isabela, Kalinga, ', '', $oct2026));
check('new layout: missing province is rejected', false, $r['ok']);
check('new layout: outlook heading is not accepted', false, parse_dds(str_replace('as of October 2026', 'as of October 2026 (OUTLOOK)', $oct2026))['ok']);

// staleness warnings
$old = ['issued' => date('Y-m-d', strtotime('-60 days')), 'created_at' => date('Y-m-d H:i:s')];
$fresh = ['issued' => date('Y-m-d', strtotime('-10 days')), 'created_at' => date('Y-m-d H:i:s')];
check('60-day-old status triggers a warning', 1, count(advisory_warnings($old, true)));
check('10-day-old status is fine', 0, count(advisory_warnings($fresh, true)));
check('missing advisory warns in live mode only', [1, 0], [count(advisory_warnings(null, true)), count(advisory_warnings(null, false))]);

// monthly reminder
$at = fn ($date) => strtotime($date . ' 12:00:00 Asia/Manila');
check('mid-month, last month\'s table imported: not due', null, pagasa_due(['month' => '2026-09-01'], $at('2026-10-18')));
check('mid-month, only August imported: September is due', 'September 2026', pagasa_due(['month' => '2026-08-01'], $at('2026-10-18')));
check('first week: the month before last is enough', null, pagasa_due(['month' => '2026-08-01'], $at('2026-10-03')));
check('first week, July imported: August is due', 'August 2026', pagasa_due(['month' => '2026-07-01'], $at('2026-10-03')));
check('nothing imported yet', 'September 2026', pagasa_due(null, $at('2026-10-18')));
check('year boundary', 'December 2025', pagasa_due(['month' => '2025-11-01'], $at('2026-01-15')));

exit($fail ? 1 : 0);

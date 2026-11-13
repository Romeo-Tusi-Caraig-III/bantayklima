<?php
/** Luzon provinces as PAGASA writes them in its drought / dry spell tables (plus an alias for older files). */
function pagasa_luzon_names(): array {
    return ['Abra', 'Albay', 'Apayao', 'Aurora', 'Bataan', 'Batanes', 'Batangas', 'Benguet', 'Bulacan', 'Cagayan',
        'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Cavite', 'Ifugao', 'Ilocos Norte', 'Ilocos Sur', 'Isabela',
        'Kalinga', 'La Union', 'Laguna', 'Marinduque', 'Masbate', 'Metropolitan Manila', 'Metro Manila', 'Mountain Province',
        'Nueva Ecija', 'Nueva Vizcaya', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan (Kalayaan)', 'Palawan',
        'Pampanga', 'Pangasinan', 'Quezon', 'Quirino', 'Rizal', 'Romblon', 'Sorsogon', 'Spratly Islands', 'Tarlac', 'Zambales'];
}

function pagasa_fail(string $why): array { return ['ok' => false, 'error' => $why]; }

/** Find the Luzon provinces named in a piece of text, in order, using PAGASA's spelling. */
function pagasa_find_luzon(string $text): array {
    $names = pagasa_luzon_names();
    usort($names, fn ($a, $b) => strlen($b) <=> strlen($a)); // longest first, so "Palawan (Kalayaan)" wins over "Palawan"
    $pattern = '/(?<![\p{L}])(' . implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names)) . ')(?![\p{L}])/iu';
    preg_match_all($pattern, $text, $found);
    $canon = array_combine(array_map('strtolower', pagasa_luzon_names()), pagasa_luzon_names());
    return array_map(fn ($n) => $canon[strtolower($n)] === 'Metro Manila' ? 'Metropolitan Manila' : $canon[strtolower($n)], $found[1]);
}

/**
 * Read text from PAGASA's "Drought and Dry Spell Assessment" (copied, or taken from the PDF) and work out the province's status.
 * Reads the latest "As of <month> <year>" table and checks the provinces it finds against PAGASA's own counts,
 * so a bad paste is rejected instead of silently giving a wrong answer.
 * Two layouts are understood: the older one (all three counts first, then the names) and the newer
 * PH Meteorological Drought Monitor one (each count followed by its own names, "None" for an empty cell).
 */
function parse_dds(string $text, string $province = 'Laguna'): array {
    $text = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)));
    if ($text === '') return pagasa_fail('Nothing was pasted.');

    // "As of May 2026 (ACTUAL)" (older) or "as of October 2026" (newer). An outlook heading is never accepted.
    $header = '/as of\s+([A-Za-z]+)\.?\s+(?:\d{1,2},\s*)?(\d{4})(?!\s*\((?!ACTUAL))/i';
    if (!preg_match_all($header, $text, $m, PREG_OFFSET_CAPTURE)) {
        return pagasa_fail('Could not find an "as of <month> <year>" assessment table. Use the assessment page of the PAGASA PDF (Dry Condition / Dry Spell / Drought), not the outlook.');
    }

    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    $blocks = [];
    foreach ($m[0] as $i => [$whole, $pos]) {
        $mon = $months[strtolower(substr($m[1][$i][0], 0, 3))] ?? null;
        if (!$mon) continue;
        $start = $pos + strlen($whole);
        $end = strlen($text);
        if (isset($m[0][$i + 1])) $end = min($end, $m[0][$i + 1][1]);
        $outlook = stripos($text, 'OUTLOOK OVER', $start);
        if ($outlook !== false) $end = min($end, $outlook);
        $blockText = substr($text, $start, $end - $start);
        if (!preg_match('/\bLUZON\s+(?:\d|None\b)/u', $blockText)) continue; // only a table with a Luzon row counts
        $blocks[] = ['key' => sprintf('%04d-%02d', (int) $m[2][$i][0], $mon), 'text' => $blockText];
    }
    if (!$blocks) return pagasa_fail('Could not find the Luzon table in the expected format. Please use the manual form below.');
    usort($blocks, fn ($a, $b) => strcmp($a['key'], $b['key']));
    $block = end($blocks); // the latest month

    $issued = null;
    if (preg_match('/Issuance:\s*(\d{4}-\d{2}-\d{2})/i', $block['text'], $im)) $issued = $im[1];

    $keys = ['dry_condition', 'dry_spell', 'drought'];
    $labels = ['dry condition', 'dry spell', 'drought'];

    if (preg_match('/LUZON\s+(\d+)\s+(\d+)\s+(\d+)\s+(.*?)(?=\sVISAYAS\b|$)/ui', $block['text'], $lm)) {
        // older layout: the three counts, then every name in order
        $counts = [(int) $lm[1], (int) $lm[2], (int) $lm[3]];
        $list = pagasa_find_luzon($lm[4]);
        if (count($list) !== array_sum($counts)) {
            return pagasa_fail('I found ' . count($list) . ' Luzon provinces, but PAGASA\'s counts add up to ' . array_sum($counts)
                . '. The paste may be incomplete or out of order. Please paste the whole table again, or use the manual form below.');
        }
        $lists = [
            'dry_condition' => array_slice($list, 0, $counts[0]),
            'dry_spell' => array_slice($list, $counts[0], $counts[1]),
            'drought' => array_slice($list, $counts[0] + $counts[1]),
        ];
    } elseif (preg_match('/\bLUZON\s+((?:\d+|None)\b.*?)(?=\sVISAYAS\b|$)/ui', $block['text'], $lm)) {
        // newer layout: each cell is "<count> <names>" or "None"
        $parts = preg_split('/(?<![\p{L}\d])(\d+|None)(?![\p{L}\d])/u', $lm[1], -1, PREG_SPLIT_DELIM_CAPTURE);
        $cells = [];
        for ($i = 1; $i < count($parts); $i += 2) $cells[] = [strcasecmp($parts[$i], 'None') === 0 ? 0 : (int) $parts[$i], $parts[$i + 1] ?? ''];
        if (trim($parts[0]) !== '' || count($cells) !== 3) {
            return pagasa_fail('Could not read the Luzon row (expected three cells: dry condition, dry spell, drought). Please use the manual form below.');
        }
        $counts = [];
        $lists = [];
        foreach ($cells as $i => [$n, $names]) {
            $found = $n > 0 ? pagasa_find_luzon($names) : [];
            if (count($found) !== $n) {
                return pagasa_fail('For Luzon ' . $labels[$i] . ' I found ' . count($found) . ' provinces, but PAGASA\'s count is ' . $n
                    . '. The text may be incomplete or out of order. Please check the file, or use the manual form below.');
            }
            $counts[] = $n;
            $lists[$keys[$i]] = $found;
        }
    } else {
        return pagasa_fail('Could not find the Luzon table in the expected format. Please use the manual form below.');
    }

    $status = 'normal'; // PAGASA only lists provinces that have a condition
    foreach ($keys as $k) if (in_array($province, $lists[$k], true)) $status = $k;

    return [
        'ok' => true, 'status' => $status, 'month' => $block['key'] . '-01', 'issued' => $issued,
        'counts' => $counts, 'lists' => $lists,
        'summary' => 'Luzon provinces: dry condition ' . $counts[0] . ', dry spell ' . $counts[1] . ', drought ' . $counts[2] . '.',
    ];
}

/** Days since PAGASA released (or staff saved) the latest advisory. */
function advisory_age_days(array $adv): ?int {
    $ref = $adv['issued'] ?: substr((string) $adv['created_at'], 0, 10);
    $t = strtotime($ref);
    return $t ? (int) floor((time() - $t) / 86400) : null;
}

/** Plain-language warnings about the PAGASA status, so an out-of-date or missing entry never goes unnoticed. */
function advisory_warnings(?array $adv, bool $live): array {
    if (!$adv) return $live ? ['No PAGASA advisory has been entered, so risk levels use only the ONI and rainfall data.'] : [];
    $age = advisory_age_days($adv);
    $limit = (int) cfg('advisory_stale_days');
    return ($age !== null && $age > $limit)
        ? ["The PAGASA province status is $age days old. PAGASA updates monthly, so check for a newer release."] : [];
}

/**
 * Is a new PAGASA table due? PAGASA releases the previous month's assessment in the first week of the month,
 * so from the 8th onward we expect last month's table; before that, the month before. Returns the month's name when due.
 */
function pagasa_due(?array $latest, ?int $now = null): ?string {
    $d = new DateTime('@' . ($now ?? time()));
    $d->setTimezone(new DateTimeZone('Asia/Manila'));
    $expected = new DateTime($d->format('Y-m-01'), new DateTimeZone('Asia/Manila'));
    $expected->modify('-' . ((int) $d->format('j') >= 8 ? 1 : 2) . ' months');
    if (!$latest || substr((string) $latest['month'], 0, 7) < $expected->format('Y-m')) return $expected->format('F Y');
    return null;
}

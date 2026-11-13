<?php
/** The numbers behind the risk rules. Staff can change them in Admin; these are the defaults. */
function risk_default_thresholds(): array {
    return [
        'oni_strong' => 1.0, 'oni_moderate' => 0.5,                       // ONI at or above (El Niño) or at or below minus (La Niña)
        'pagasa_dry_condition' => 1, 'pagasa_dry_spell' => 2, 'pagasa_drought' => 3, 'pagasa_above_normal' => 1, // points for PAGASA's status
        'recent_dry_strong' => 0.5, 'recent_dry' => 0.75,                  // last 30 days of rain vs normal (below = dry)
        'recent_wet' => 1.5, 'recent_wet_strong' => 2.0,                   // (above = wet)
        'forecast_dry' => 0.5, 'forecast_wet' => 1.5, 'forecast_wet_strong' => 2.0, // next 14 days forecast vs normal
        'level_watch' => 2, 'level_warning' => 4, 'level_severe' => 6,     // score needed for each level
    ];
}

function risk_threshold_labels(): array {
    return [
        'oni_strong' => 'ONI counted as a strong El Niño / La Niña at or beyond (±)',
        'oni_moderate' => 'ONI counted as El Niño / La Niña at or beyond (±)',
        'pagasa_dry_condition' => 'Points when PAGASA lists the province under dry condition',
        'pagasa_dry_spell' => 'Points when PAGASA lists the province under dry spell',
        'pagasa_drought' => 'Points when PAGASA lists the province under drought',
        'pagasa_above_normal' => 'Points when PAGASA reports above-normal rainfall',
        'recent_dry_strong' => 'Last 30 days very dry: rain below this share of normal',
        'recent_dry' => 'Last 30 days dry: rain below this share of normal',
        'recent_wet' => 'Last 30 days wet: rain above this multiple of normal',
        'recent_wet_strong' => 'Last 30 days very wet: rain above this multiple of normal',
        'forecast_dry' => 'Next 14 days dry: forecast below this share of normal',
        'forecast_wet' => 'Next 14 days wet: forecast above this multiple of normal',
        'forecast_wet_strong' => 'Next 14 days very wet: forecast above this multiple of normal',
        'level_watch' => 'Score for Watch',
        'level_warning' => 'Score for Warning',
        'level_severe' => 'Score for Severe',
    ];
}

/** Check threshold values from a form. Returns [clean values, list of problems]. */
function risk_validate_thresholds(array $in): array {
    $d = risk_default_thresholds();
    $floats = ['oni_strong' => [0.1, 4], 'oni_moderate' => [0.1, 4], 'recent_dry_strong' => [0.05, 0.99], 'recent_dry' => [0.05, 0.99],
        'recent_wet' => [1.01, 5], 'recent_wet_strong' => [1.01, 10], 'forecast_dry' => [0.05, 0.99], 'forecast_wet' => [1.01, 5], 'forecast_wet_strong' => [1.01, 10]];
    $ints = ['pagasa_dry_condition' => [0, 5], 'pagasa_dry_spell' => [0, 5], 'pagasa_drought' => [0, 5], 'pagasa_above_normal' => [0, 5],
        'level_watch' => [1, 12], 'level_warning' => [1, 12], 'level_severe' => [1, 12]];
    $labels = risk_threshold_labels();
    $clean = []; $errors = [];

    foreach ($floats + $ints as $k => [$min, $max]) {
        $v = $in[$k] ?? $d[$k];
        $isInt = isset($ints[$k]);
        if (!is_numeric($v) || (float) $v < $min || (float) $v > $max || ($isInt && (float) $v != (int) $v)) {
            $errors[] = $labels[$k] . ': enter a ' . ($isInt ? 'whole number' : 'number') . " from $min to $max.";
        } else {
            $clean[$k] = $isInt ? (int) $v : (float) $v;
        }
    }
    if (!$errors) {
        if ($clean['oni_moderate'] >= $clean['oni_strong']) $errors[] = 'The strong ONI value must be larger than the normal one.';
        if ($clean['recent_dry_strong'] >= $clean['recent_dry']) $errors[] = '"Very dry" must be lower than "dry" for the last 30 days.';
        if ($clean['recent_wet'] >= $clean['recent_wet_strong']) $errors[] = '"Very wet" must be higher than "wet" for the last 30 days.';
        if ($clean['forecast_wet'] >= $clean['forecast_wet_strong']) $errors[] = '"Very wet" must be higher than "wet" for the forecast.';
        if (!($clean['level_watch'] < $clean['level_warning'] && $clean['level_warning'] < $clean['level_severe'])) $errors[] = 'Watch, Warning and Severe scores must go up in that order.';
    }
    return [$errors ? $clean : array_merge($d, $clean), $errors];
}

/**
 * Rule-based risk engine. Drought and flood points are scored separately; the higher one decides the hazard.
 * Level cut-offs (defaults): score 0-1 normal, 2-3 watch, 4-5 warning, 6+ severe.
 *
 * @param float|null  $oni        latest Oceanic Niño Index value
 * @param string|null $rainStatus PAGASA province status: normal, above_normal, dry_condition, dry_spell, drought
 * @param float|null  $recent     last 30 days rainfall divided by normal
 * @param float|null  $forecast   next 14 days forecast rainfall divided by normal
 * @param array|null  $t          threshold overrides (see risk_default_thresholds)
 */
function risk_assess(?float $oni, ?string $rainStatus, ?float $recent, ?float $forecast, ?array $t = null): array {
    $t = array_merge(risk_default_thresholds(), $t ?? []);
    $dry = 0; $wet = 0; $dryWhy = []; $wetWhy = [];

    if ($oni !== null) {
        $v = number_format($oni, 1);
        if ($oni >= $t['oni_strong'])          { $dry += 2; $dryWhy[] = "Strong El Niño signal (ONI $v)"; }
        elseif ($oni >= $t['oni_moderate'])    { $dry += 1; $dryWhy[] = "El Niño conditions (ONI $v)"; }
        elseif ($oni <= -$t['oni_strong'])     { $wet += 2; $wetWhy[] = "Strong La Niña signal (ONI $v)"; }
        elseif ($oni <= -$t['oni_moderate'])   { $wet += 1; $wetWhy[] = "La Niña conditions (ONI $v)"; }
    }

    $points = ['dry_condition' => $t['pagasa_dry_condition'], 'dry_spell' => $t['pagasa_dry_spell'], 'drought' => $t['pagasa_drought']];
    if ($rainStatus !== null && isset($points[$rainStatus])) {
        $dry += $points[$rainStatus];
        $dryWhy[] = 'PAGASA lists the province under ' . str_replace('_', ' ', $rainStatus);
    } elseif ($rainStatus === 'above_normal') {
        $wet += $t['pagasa_above_normal']; $wetWhy[] = 'PAGASA reports above-normal rainfall for the province';
    }

    if ($recent !== null) {
        $p = (int) round($recent * 100);
        if ($recent < $t['recent_dry_strong'])     { $dry += 2; $dryWhy[] = "Last 30 days: only $p% of normal rainfall"; }
        elseif ($recent < $t['recent_dry'])        { $dry += 1; $dryWhy[] = "Last 30 days: $p% of normal rainfall"; }
        elseif ($recent > $t['recent_wet_strong']) { $wet += 2; $wetWhy[] = "Last 30 days: $p% of normal rainfall"; }
        elseif ($recent > $t['recent_wet'])        { $wet += 1; $wetWhy[] = "Last 30 days: $p% of normal rainfall"; }
    }

    if ($forecast !== null) {
        $p = (int) round($forecast * 100);
        if ($forecast < $t['forecast_dry'])              { $dry += 1; $dryWhy[] = "Next 14 days forecast: $p% of normal rainfall"; }
        elseif ($forecast > $t['forecast_wet_strong'])   { $wet += 2; $wetWhy[] = "Next 14 days forecast: $p% of normal rainfall"; }
        elseif ($forecast > $t['forecast_wet'])          { $wet += 1; $wetWhy[] = "Next 14 days forecast: $p% of normal rainfall"; }
    }

    $isFlood = $wet > $dry;
    $score = max($dry, $wet);
    $level = $score >= $t['level_severe'] ? 'severe' : ($score >= $t['level_warning'] ? 'warning' : ($score >= $t['level_watch'] ? 'watch' : 'normal'));

    return [
        'hazard'  => $level === 'normal' ? 'none' : ($isFlood ? 'flood' : 'drought'),
        'level'   => $level,
        'score'   => $score,
        'reasons' => $isFlood ? $wetWhy : $dryWhy,
    ];
}

function risk_advice(string $hazard, string $level, string $lang = 'en'): string {
    $text = [
        'en' => [
            'drought' => [
                'watch'   => 'Dry conditions may be developing. Check your water sources and watch for PAGASA updates.',
                'warning' => 'Dry spell risk is high. Conserve water, favor drought-tolerant crops, and hold off planting thirsty crops.',
                'severe'  => 'Severe drought risk. Conserve water, protect seedlings, and coordinate with your agriculture office for help.',
            ],
            'flood' => [
                'watch'   => 'Heavier rain is possible. Clear canals and drainage and watch for PAGASA updates.',
                'warning' => 'Flood risk is high. Harvest ripe crops early, protect stored produce, and prepare an evacuation plan.',
                'severe'  => 'Severe flood risk. Move people, livestock, and equipment to higher ground and follow your LGU instructions.',
            ],
            'none' => 'No El Niño or La Niña hazard detected. Conditions look normal.',
        ],
        'fil' => [
            'drought' => [
                'watch'   => 'Maaaring magsimula ang tagtuyot. Suriin ang inyong pinagkukunan ng tubig at subaybayan ang mga update ng PAGASA.',
                'warning' => 'Mataas ang panganib ng tagtuyot. Magtipid ng tubig, magtanim ng mga pananim na matibay sa tagtuyot, at ipagpaliban muna ang pagtatanim ng mga pananim na maraming kailangang tubig.',
                'severe'  => 'Matinding panganib ng tagtuyot. Magtipid ng tubig, protektahan ang mga punla, at makipag-ugnayan sa inyong tanggapan ng agrikultura para sa tulong.',
            ],
            'flood' => [
                'watch'   => 'Maaaring lumakas ang ulan. Linisin ang mga kanal at daluyan ng tubig at subaybayan ang mga update ng PAGASA.',
                'warning' => 'Mataas ang panganib ng baha. Anihin nang maaga ang mga hinog na pananim, protektahan ang mga inimbak na ani, at maghanda ng plano sa paglikas.',
                'severe'  => 'Matinding panganib ng baha. Ilipat sa mas mataas na lugar ang mga tao, hayop, at kagamitan, at sundin ang mga tagubilin ng inyong LGU.',
            ],
            'none' => 'Walang nakitang panganib mula sa El Niño o La Niña. Normal ang kalagayan.',
        ],
    ];
    $t = $text[$lang] ?? $text['en'];
    return $t[$hazard][$level] ?? $t['none'];
}

/** Display word for a level or hazard ("severe", "drought", ...) in English or Filipino. */
function risk_label(string $word, string $lang = 'en'): string {
    $fil = ['normal' => 'Normal', 'watch' => 'Bantay', 'warning' => 'Babala', 'severe' => 'Matinding Babala',
            'drought' => 'Tagtuyot', 'flood' => 'Baha', 'pending' => 'Hindi pa nasusuri'];
    return $lang === 'fil' ? ($fil[$word] ?? $word) : ucfirst($word);
}

/** First line of an alert, e.g. "BantayKlima Calamba: DROUGHT SEVERE." */
function risk_head(string $town, array $a, string $lang = 'en'): string {
    if ($a['level'] === 'normal') return "BantayKlima $town: " . ($lang === 'fil' ? 'NORMAL NA ANG KALAGAYAN.' : 'ALL CLEAR.');
    if ($lang === 'fil') return "BantayKlima $town: " . strtoupper(risk_label($a['level'], 'fil')) . ' - ' . strtoupper(risk_label($a['hazard'], 'fil')) . '.';
    return "BantayKlima $town: " . strtoupper($a['hazard']) . ' ' . strtoupper($a['level']) . '.';
}

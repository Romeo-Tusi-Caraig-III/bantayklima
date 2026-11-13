<?php
// Run with:  php tests/contrast_test.php
// Checks that every text colour used in the app has enough contrast on its background (WCAG AA: 4.5 for normal text).
// The colours below mirror assets/style.css and assets/app.js; keep them in sync when you change the palette.
function lum(string $hex): float {
    [$r, $g, $b] = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf(ltrim($hex, '#'), '%02x%02x%02x'));
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}
function ratio(string $fg, string $bg): float {
    [$a, $b] = [lum($fg), lum($bg)];
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

$white = '#ffffff'; $paper = '#edf4f6'; $ink = '#0f2733'; $green = '#0f6c86'; $band = '#0a2230'; $soft = '#e2eef2';
$pairs = [
    'body text on page' => [$ink, $paper], 'body text on cards' => [$ink, $white],
    'headings and links (green) on cards' => [$green, $white], 'links (green) on page' => [$green, $paper], 'green on soft panels' => [$green, $soft],
    'top bar text' => ['#e6f1f4', $band], 'top bar brand' => [$white, $band], 'hero text' => ['#c5dce3', $band], 'hero title' => [$white, $band], 'hero select' => ['#10282e', $white],
    'register button' => ['#10282e', '#f3b23c'],
    'small grey text on cards' => ['#44606c', $white], 'small grey text on soft panels' => ['#44606c', $soft], 'footer text' => ['#44606c', $paper],
    'button text' => [$white, $green], 'secondary button' => [$green, $white], 'remove button' => [$white, '#b3261e'],
    'ok message' => [$ink, '#e0f2ea'], 'error message' => [$ink, '#fde8e6'],
    'badge: normal' => [$white, '#2f7d6d'], 'badge: watch' => [$white, '#8a5a00'], 'badge: warning' => [$white, '#b8430f'],
    'badge: severe' => [$white, '#b3261e'], 'badge: pending' => [$white, '#666666'],
    'dark: body text' => ['#e8f1f4', '#08171f'], 'dark: card text' => ['#e8f1f4', '#10272f'], 'dark: links' => ['#6cc6dc', '#10272f'],
    'dark: button text' => ['#06282c', '#6cc6dc'], 'dark: grey text' => ['#a7bec6', '#10272f'],
    'level word: normal' => ['#1e6d5a', $white], 'level word: watch' => ['#8a5a00', $white], 'level word: warning' => ['#b8430f', $white], 'level word: severe' => ['#b3261e', $white],
    'dark level word: normal' => ['#5fd1b3', '#10272f'], 'dark level word: watch' => ['#f0b94a', '#10272f'], 'dark level word: warning' => ['#ff9a6b', '#10272f'], 'dark level word: severe' => ['#ff7d86', '#10272f'],
    'dark: ok message' => ['#e8f1f2', '#173a2c'], 'dark: error message' => ['#e8f1f2', '#4a1f1c'],
];
$fail = 0;
foreach ($pairs as $name => [$fg, $bg]) {
    $r = ratio($fg, $bg);
    if ($r >= 4.5) { printf("PASS %-38s %.1f:1\n", $name, $r); } else { $fail++; printf("FAIL %-38s %.1f:1 (needs 4.5)\n", $name, $r); }
}
// the colours the stylesheet actually uses for the badges must be the ones tested above
$css = file_get_contents(__DIR__ . '/../assets/style.css');
foreach (['normal' => '#2f7d6d', 'watch' => '#8a5a00', 'warning' => '#b8430f', 'severe' => '#b3261e', 'pending' => '#666666'] as $lv => $hex) {
    $has = (bool) preg_match('/\.b-' . $lv . '\s*\{\s*background:\s*' . preg_quote($hex, '/') . '/i', $css);
    if ($has) echo "PASS stylesheet uses $hex for .b-$lv\n"; else { $fail++; echo "FAIL stylesheet does not use $hex for .b-$lv\n"; }
}
exit($fail ? 1 : 0);

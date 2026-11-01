<?php

/**
 * Lists the instruction text that is still printed as a line of text instead of
 * living in an ⓘ icon (CLAUDE.md "Instruction text goes in an ⓘ icon").
 *
 *   php scripts/info-tip-audit.php                    summary per panel
 *   php scripts/info-tip-audit.php --panel=trade      every call in that panel, file by file
 *   php scripts/info-tip-audit.php --file=app/Filament/Pages/MyStore.php
 *   php scripts/info-tip-audit.php --dynamic --blocks add the closures and the text blocks to the list
 *   php scripts/info-tip-audit.php --json             machine-readable
 *   php scripts/info-tip-audit.php --write-baseline   store today's counts as the new ceiling
 *
 * Panels: erp (/admin), ims (/office), investor (/investor), reseller (/portal), trade (/trade).
 * Needs only PHP: no composer install, no database.
 * See docs/info-icon-instructions-plan.md.
 */

use App\Support\InfoTipAudit;

require __DIR__.'/../app/Support/InfoTipAudit.php';

$root = dirname(__DIR__);
$baselinePath = $root.'/tests/Fixtures/info-tip-baseline.json';
$options = [];

foreach (array_slice($argv, 1) as $argument) {
    [$name, $value] = array_pad(explode('=', ltrim($argument, '-'), 2), 2, true);
    $options[$name] = $value;
}

$findings = InfoTipAudit::scanPaths($root, ['app/Filament', 'app/Ims']);
$counts = InfoTipAudit::countsByFile($findings);
$violations = InfoTipAudit::violations($findings);

if ($violations !== []) {
    echo "ERROR: ->infoTip() must carry __('English text') (bilingual rule):\n";

    foreach ($violations as $v) {
        echo "  {$v['file']}:{$v['line']}  {$v['method']}\n";
    }

    echo "\n";
}

if (isset($options['write-baseline'])) {
    file_put_contents($baselinePath, json_encode([
        '_note' => 'Plain instruction texts still printed as text, per file. The guard test (tests/Unit/InfoTipGuardTest.php) fails when a file goes above its number. Lower it with: php scripts/info-tip-audit.php --write-baseline',
        'total' => array_sum($counts),
        'files' => $counts,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

    echo 'Baseline written: '.array_sum($counts).' texts in '.count($counts)." files.\n";

    exit(0);
}

$panel = isset($options['panel']) && $options['panel'] !== true ? (string) $options['panel'] : null;
$file = isset($options['file']) && $options['file'] !== true ? (string) $options['file'] : null;
$withDynamic = isset($options['dynamic']);
$withBlocks = isset($options['blocks']);

$rows = array_values(array_filter($findings, function (array $f) use ($panel, $file, $withDynamic, $withBlocks): bool {
    if ($panel !== null && InfoTipAudit::panel($f['file']) !== $panel) {
        return false;
    }

    if ($file !== null && $f['file'] !== $file) {
        return false;
    }

    if ($f['type'] === 'violation') {
        return false;
    }

    if ($f['type'] === 'block') {
        return $withBlocks;
    }

    if (! $f['in_scope'] || $f['kept']) {
        return false;
    }

    return $f['static'] || $withDynamic;
}));

if (isset($options['json'])) {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

    exit($violations === [] ? 0 : 1);
}

if ($panel === null && $file === null) {
    $summary = [];

    foreach ($findings as $f) {
        $key = InfoTipAudit::panel($f['file']);
        $summary[$key] ??= ['files' => [], 'static' => 0, 'dynamic' => 0, 'blocks' => 0, 'kept' => 0];

        if ($f['type'] === 'violation') {
            continue;
        }

        if ($f['type'] === 'block') {
            $summary[$key]['blocks']++;
        } elseif ($f['kept']) {
            $summary[$key]['kept']++;
        } elseif ($f['in_scope']) {
            $summary[$key][$f['static'] ? 'static' : 'dynamic']++;
            $summary[$key]['files'][$f['file']] = true;
        }
    }

    ksort($summary);
    printf("%-10s %6s %8s %9s %8s %6s\n", 'panel', 'files', 'static', 'dynamic', 'blocks', 'kept');

    foreach ($summary as $key => $row) {
        printf("%-10s %6d %8d %9d %8d %6d\n", $key, count($row['files']), $row['static'], $row['dynamic'], $row['blocks'], $row['kept']);
    }

    echo "\nstatic  = plain text still printed as text: convert to ->infoTip(__('...')) (the guard counts these)\n";
    echo "dynamic = a closure / variable: a person decides (--dynamic lists them)\n";
    echo "blocks  = Placeholder content / Text::make: a person decides (--blocks lists them)\n";
    echo "kept    = marked '// info-tip:keep' on purpose\n";

    exit($violations === [] ? 0 : 1);
}

$current = null;

foreach ($rows as $f) {
    if ($f['file'] !== $current) {
        $current = $f['file'];
        echo "\n{$current}\n";
    }

    $kind = $f['type'] === 'block' ? 'block' : ($f['static'] ? 'static' : 'dynamic');
    $text = $f['text'] === null ? '' : ' '.mb_strimwidth($f['text'], 0, 90, '…');
    printf("  %4d  %-8s %-14s %-18s%s\n", $f['line'], $kind, $f['component'], $f['method'], $text);
}

echo "\n".count($rows)." item(s).\n";

exit($violations === [] ? 0 : 1);

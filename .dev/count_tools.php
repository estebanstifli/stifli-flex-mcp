<?php
// Script para contar herramientas en cada ubicación

echo "=== CONTEO DE HERRAMIENTAS ===\n\n";

// 1. Contar en model.php - getTools()
$modelContent = file_get_contents('models/model.php');

// Buscar desde "public function getTools()" hasta "// Merge WooCommerce"
preg_match('/public function getTools\(\).*?\/\/ Merge WooCommerce/s', $modelContent, $match);

if ($match) {
    $toolsSection = $match[0];
    // Contar líneas que definen herramientas: 'nombre_tool' => array(
    preg_match_all("/^\s+'([a-z_]+)' => array\(/m", $toolsSection, $matches);
    $wpTools = $matches[1];
    echo "1. HERRAMIENTAS EN model.php getTools() (WordPress): " . count($wpTools) . "\n";
    echo "   Lista: " . implode(', ', $wpTools) . "\n\n";
}

// 2. Contar en easy-visual-mcp.php - seed WordPress
$seedContent = file_get_contents('easy-visual-mcp.php');
preg_match('/\$tools = array\((.*?)\);.*?\/\/ Add WooCommerce/s', $seedContent, $seedMatch);

if ($seedMatch) {
    preg_match_all("/array\('([a-z_]+)'/", $seedMatch[1], $seedMatches);
    $seedWpTools = $seedMatches[1];
    echo "2. HERRAMIENTAS EN easy-visual-mcp.php seed (WordPress): " . count($seedWpTools) . "\n";
    echo "   Lista: " . implode(', ', $seedWpTools) . "\n\n";
}

// 3. Contar WooCommerce en seed
preg_match('/if \( class_exists\( .WooCommerce. \) \) \{(.*?)\$wpdb->replace/s', $seedContent, $wcSeedMatch);

if ($wcSeedMatch) {
    preg_match_all("/array\('([a-z_]+)'/", $wcSeedMatch[1], $wcSeedMatches);
    $seedWcTools = $wcSeedMatches[1];
    echo "3. HERRAMIENTAS EN easy-visual-mcp.php seed (WooCommerce): " . count($seedWcTools) . "\n";
    echo "   Lista: " . implode(', ', $seedWcTools) . "\n\n";
}

// 4. Total
$totalModel = count($wpTools ?? []);
$totalSeedWp = count($seedWpTools ?? []);
$totalSeedWc = count($seedWcTools ?? []);
$totalSeed = $totalSeedWp + $totalSeedWc;

echo "=== RESUMEN ===\n";
echo "Model.php WordPress: $totalModel tools\n";
echo "Seed WordPress: $totalSeedWp tools\n";
echo "Seed WooCommerce: $totalSeedWc tools\n";
echo "Total en Seed: $totalSeed tools\n";
echo "\n";

if ($totalModel != $totalSeedWp) {
    echo "⚠️ DISCREPANCIA entre model.php ($totalModel) y seed WordPress ($totalSeedWp)\n";
    echo "Diferencia: " . abs($totalModel - $totalSeedWp) . " herramientas\n\n";
    
    $onlyInModel = array_diff($wpTools ?? [], $seedWpTools ?? []);
    $onlyInSeed = array_diff($seedWpTools ?? [], $wpTools ?? []);
    
    if (!empty($onlyInModel)) {
        echo "Solo en model.php: " . implode(', ', $onlyInModel) . "\n";
    }
    if (!empty($onlyInSeed)) {
        echo "Solo en seed: " . implode(', ', $onlyInSeed) . "\n";
    }
}

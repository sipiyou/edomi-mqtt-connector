<?php
/*
  MQTT Connector – Build-Skript
  Erzeugt: edomi/19002763_lbs.php

  Aufruf: cd edomi/ && php compile.php
*/

$lbsName    = "19002763_lbs.php";
$srcBase    = "../";
$koPickerBase = "../../waveshare/samsung-nasa-rs485-php-connector/php/";
$disclaimer = file_get_contents($srcBase . "disclaimer");

if ($disclaimer === false) die("Fehler: disclaimer nicht gefunden\n");

$encoded = array();

// PHP-Dateien: strip_whitespace + gzip + base64
$phpFiles = array(
    $srcBase . "MqttClient.php"  => "MqttClient.txt",
    $srcBase . "mqtt_admin.php"  => "mqtt_admin.txt",
);

foreach ($phpFiles as $src => $placeholder) {
    if (!file_exists($src)) die("Fehler: $src nicht gefunden\n");
    exec("php -l " . escapeshellarg($src) . " 2>&1", $out, $rc);
    if ($rc !== 0) die("Syntax-Fehler in $src:\n" . implode("\n", $out) . "\n");
    $data = php_strip_whitespace($src);
    $encoded[$placeholder] = base64_encode(gzcompress($data, 9, FORCE_DEFLATE));
    echo "OK: $src -> $placeholder (" . strlen($encoded[$placeholder]) . " bytes)\n";
}

// KO-Picker aus Samsung-NASA-Projekt
$koPickerPhp = $koPickerBase . "ko_picker.php";
if (!file_exists($koPickerPhp)) die("Fehler: ko_picker.php nicht gefunden: $koPickerPhp\n");
exec("php -l " . escapeshellarg($koPickerPhp) . " 2>&1", $out, $rc);
if ($rc !== 0) die("Syntax-Fehler in ko_picker.php:\n" . implode("\n", $out) . "\n");
$data = php_strip_whitespace($koPickerPhp);
$encoded["ko_picker.php.txt"] = base64_encode(gzcompress($data, 9, FORCE_DEFLATE));
echo "OK: $koPickerPhp -> ko_picker.php.txt (" . strlen($encoded["ko_picker.php.txt"]) . " bytes)\n";

foreach (array("ko_picker.css" => "ko_picker.css.txt", "ko_picker.js" => "ko_picker.js.txt") as $file => $key) {
    $src = $koPickerBase . $file;
    if (!file_exists($src)) die("Fehler: $file nicht gefunden: $src\n");
    $data = file_get_contents($src);
    $encoded[$key] = base64_encode(gzcompress($data, 9, FORCE_DEFLATE));
    echo "OK: $src -> $key (" . strlen($encoded[$key]) . " bytes)\n";
}

// Template laden, Platzhalter ersetzen
$lbs = file_get_contents($srcBase . "template_" . $lbsName);
if ($lbs === false) die("Fehler: template_$lbsName nicht gefunden\n");

foreach ($encoded as $key => $val) {
    $lbs = str_replace('__' . $key . '__', $val, $lbs);
}

$lbs = str_replace('__INSERT_DISCLAIMER__', $disclaimer, $lbs);

file_put_contents($lbsName, $lbs);
echo "\nFertig: $lbsName (" . filesize($lbsName) . " bytes)\n";
?>

<?php

$argv[1] = 9912;

require(dirname(__FILE__)."/../../main/include/php/incl_lbsexec.php");

$hasWrapper = 1;
define('LBSID', "19002763");

function W_logic_setVar($id, $v1, $v2) {
    printf("V[%s] = %s\n", $v1, $v2);
}

function W_logic_setOutput($id, $v1, $v2) {
    printf("O[%s] = %s\n", $v1, $v2);
}

function W_writeToCustomLog($lName, $dbgTxt, $output) {
    printf("%s %s => %s\n", $lName, $dbgTxt, $output);
}

function W_logic_getInputs() {
    global $id;
    $id = 98767;

    $arr = array();
    for ($i = 1; $i < 50; $i++) {
        $arr[$i]['value']   = '';
        $arr[$i]['refresh'] = 0;
    }

    $arr[1]['value']   = 1;        // Start
    $arr[1]['refresh'] = 1;
    $arr[2]['value']   = 'localhost'; // Broker-Host
    $arr[2]['refresh'] = 1;
    $arr[3]['value']   = 1883;     // Broker-Port
    $arr[3]['refresh'] = 1;
    $arr[4]['value']   = '';       // Username
    $arr[5]['value']   = '';       // Passwort
    $arr[6]['value']   = 30;       // Reconnect-Intervall
    $arr[8]['value']   = 1;        // Admin aktivieren
    $arr[9]['value']   = 2;        // Debug: Verbose

    return $arr;
}

function W_logic_getInputsQueued($id) {
    return false;
}

$ADMIN_WWW = MAIN_PATH . "/www/MQTT";
$LIB_DIR   = MAIN_PATH . "/main/include/php/MQTT";

LB_LBSID_installLib($LIB_DIR, 2);
LB_LBSID_installAdmin($ADMIN_WWW, 2);
?>

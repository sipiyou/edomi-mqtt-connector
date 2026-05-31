###[DEF]###
[name           = MQTT Connector v1.03 ]

[e#1 trigger    = (Re)Start/Stopp ]
[e#2 important  = Broker-Host (leer oder localhost = lokal)#init=localhost ]
[e#3            = Broker-Port#init=1883 ]
[e#4            = Broker-Username (leer = keine Auth) ]
[e#5            = Broker-Passwort ]
[e#6            = Reconnect-Intervall in Sek.#init=30 ]

[e#8 important  = Admin-Interface#init=1 ]
[e#9            = DEBUG#init=0 ]

[v#1            = 0]
###[/DEF]###

###[HELP]###
<a class="cmdButton" href="../MQTT/mqtt_admin.php" target="_mqttAdmin">Administration</a>

Generischer MQTT-Connector für Edomi.

Geräte und Channels werden per JSON-Import oder über MQTT Discovery im Admin angelegt.
Jeder Channel definiert ein Subscribe-Topic (MQTT → Edomi KO) und/oder ein
Publish-Topic (Edomi KO → Broker).

MQTT Discovery: Über den Admin-Button "Topic-Scanner" werden alle Topics im Netz
aufgezeichnet (inkl. homeassistant/# und tasmota/discovery/#). Geräte können
anschließend direkt aus der Scan-Liste importiert werden.

Der Baustein darf nur einmal im Projekt verwendet werden!

<b>Voraussetzung:</b> Lokaler MQTT-Broker (z.B. Mosquitto):
  apt install mosquitto mosquitto-clients

<b>Hinweis:</b> Nach Änderungen in der Administration muss der LBS neu gestartet werden
(E1 = 0, dann E1 = 1).

E1 : Betriebsmodus  0 = stoppen  /  1 = starten
E2 : Broker-Host — IP oder Hostname; leer oder nicht gesetzt = localhost
E3 : Broker-Port (Standard: 1883)
E4 : Broker-Username  (leer = keine Authentifizierung)
E5 : Broker-Passwort
E6 : Reconnect-Intervall in Sekunden bei Verbindungsverlust (Standard: 30)
E8 : Admin-Interface aktivieren (1) oder deaktivieren (0)
E9 : Debug-Level: 0=Kritisch, 1=Info, 2=Verbose

<h2>JSON-Format (Gerätedefinition)</h2>
Vollständige Dokumentation mit Beispielen (WLED, Zigbee2MQTT, Shelly u.a.):<br>
<b><a href="https://github.com/sipiyou/edomi-mqtt-connector/blob/main/git/JSON-Format.md" target="_blank">https://github.com/sipiyou/edomi-mqtt-connector</a></b>

<b>Kurzreferenz Channel-Felder:</b>
  name             : Anzeigename (Schlüssel beim Re-Import — nicht umbenennen)
  subscribeTopic   : MQTT → KO  (leer = nur publish)
  publishTopic     : KO → MQTT  (leer = nur subscribe)
  direction        : both | subscribe | publish
  dataType         : string | int | float | bool
  valueTemplate    : Jinja2: {{ value }}, {{ value | float * 0.1 | round(1) }},
                             {{ value_json.feld }}, {{ value_xml.tag }}
  commandTemplate  : Jinja2: {{ value }}, {"key":{{ value | int }}}
  valueMapIn       : {"on":1,"off":0}  — MQTT-Wert → KO-Wert
  valueMapOut      : {"1":"on","0":"off"}  — KO-Wert → MQTT-Wert
  note             : Freitext-Hinweis (im Admin angezeigt)

<h2>Disclaimer</h2>
<b>__INSERT_DISCLAIMER__</b>
###[/HELP]###

###[LBS]###
<?

/*
Changelog:
==========
v1.00  xx.xx.2026 NG initial release
v1.01  25.05.2026 NG MQTT Discovery + mini Jinja2 Template-Parser
v1.02  31.05.2026 Wert-Mapping-Editor im Admin (⇄-Button pro Channel) — valueMapIn/valueMapOut direkt im Browser bearbeiten, beliebig viele Einträge, persistent in DB
v1.03  31.05.2026 Topic-Scanner startet nicht mehr automatisch nach Absturz/Neustart (scanUntil beim Start geleert); permanente homeassistant/# und tasmota/discovery/# Subscriptions entfernt
*/

function LB_LBSID_debug($debugLevel, $thisTxtDbgLevel, $str) {
    if ($thisTxtDbgLevel <= $debugLevel) {
        $dbgTxts = array("Kritisch", "Info", "Verbose");
        writeToCustomLog("LBS_MQTT_LBSID", $dbgTxts[$thisTxtDbgLevel], $str);
    }
}

function LB_LBSID_installAdmin($wwwDir, $debugLevel) {
    if (!is_dir($wwwDir)) mkdir($wwwDir, 0755, true);
    $data = gzuncompress(base64_decode("__mqtt_admin.txt__"));
    $data = str_replace("__INSERT_EDOMI_PATH__", MAIN_PATH, $data);
    if (!file_put_contents($wwwDir . "/mqtt_admin.php", $data)) {
        LB_LBSID_debug($debugLevel, 0, "Admin konnte nicht erstellt werden!");
    } else {
        LB_LBSID_debug($debugLevel, 1, "Admin aktiviert.");
    }
    $staticFiles = array(
        "ko_picker.php" => "__ko_picker.php.txt__",
        "ko_picker.css" => "__ko_picker.css.txt__",
        "ko_picker.js"  => "__ko_picker.js.txt__",
    );
    foreach ($staticFiles as $filename => $encoded) {
        $dest = $wwwDir . "/" . $filename;
        if (!file_put_contents($dest, gzuncompress(base64_decode($encoded)))) {
            LB_LBSID_debug($debugLevel, 0, "$filename konnte nicht erstellt werden!");
        }
    }
    file_put_contents($wwwDir . "/index.php", '<?php header("Location: mqtt_admin.php"); exit;');
}

function LB_LBSID_installLib($libDir, $debugLevel) {
    if (!is_dir($libDir)) mkdir($libDir, 0755, true);
    $data = gzuncompress(base64_decode("__MqttClient.txt__"));
    if (!file_put_contents($libDir . "/MqttClient.php", $data)) {
        LB_LBSID_debug($debugLevel, 0, "MqttClient.php konnte nicht erstellt werden!");
    } else {
        LB_LBSID_debug($debugLevel, 1, "MqttClient.php installiert.");
    }
}

function LB_LBSID($id) {
    $ADMIN_WWW = MAIN_PATH . "/www/MQTT";
    $LIB_DIR   = MAIN_PATH . "/main/include/php/MQTT";

    if ($E = logic_getInputs($id)) {
        $vars    = logic_getVars($id);
        $running = (int)($vars[1] ?? 0) === 1;

        if (!($E[1]['refresh'] && (int)$E[1]['value'] !== 1 && !$running)) {
            logic_setInputsQueued($id, $E);
        }

        if ($E[8]['refresh']) {
            switch ($E[8]['value']) {
            case 0:
                $f = $ADMIN_WWW . "/mqtt_admin.php";
                if (file_exists($f)) unlink($f);
                LB_LBSID_debug($E[9]['value'], 1, "Admin deaktiviert.");
                break;
            case 1:
                LB_LBSID_installAdmin($ADMIN_WWW, $E[9]['value']);
                break;
            }
        }

        if (($E[1]['refresh'] == 1) && ($E[1]['value'] == 1) && !$running) {
            LB_LBSID_installLib($LIB_DIR, $E[9]['value']);
            if ($E[8]['value'] == 1) {
                LB_LBSID_installAdmin($ADMIN_WWW, $E[9]['value']);
            }
            logic_setVar($id, 1, 1);
            logic_callExec(LBSID, $id, false);
        }
    }
}
?>
###[/LBS]###

###[EXEC]###
<?php
//require('wrapper.php');
require(dirname(__FILE__)."/../../../../main/include/php/incl_lbsexec.php");

$MQTT_LIB = MAIN_PATH . "/main/include/php/MQTT/MqttClient.php";
if (!file_exists($MQTT_LIB)) {
    writeToCustomLog("LBS_MQTT_LBSID", "Kritisch", "MqttClient nicht vorhanden. Bitte LBS neu starten (E1=1).");
    logic_setVar($id, 1, 0);
    sql_disconnect();
    die();
}
include_once $MQTT_LIB;

sql_connect();
set_time_limit(0);

// Sicherstellen dass V[1] auch bei unerwartetem Abbruch zurückgesetzt wird,
// damit der LBS-Block EXEC nicht als "laufend" betrachtet.
register_shutdown_function(function() use ($id) {
    logic_setVar($id, 1, 0);
});

$E = logic_getInputs($id);
if (isset($hasWrapper)) $E = W_logic_getInputs($id);

$debugLevel     = (int)($E[9]['value'] ?? 0);
$brokerHost     = trim((string)($E[2]['value'] ?? '')) ?: 'localhost';
$brokerPort     = max(1, (int)($E[3]['value'] ?? 1883));
$brokerUser     = (string)($E[4]['value'] ?? '');
$brokerPass     = (string)($E[5]['value'] ?? '');
$reconnectDelay = max(5, (int)($E[6]['value'] ?? 30));
$lbsID          = LBSID;

$dbgTxts = array("Kritisch", "Info", "Verbose");

function exec_debug($thisTxtDbgLevel, $str) {
    global $debugLevel, $dbgTxts;
    if ($thisTxtDbgLevel <= $debugLevel) {
        writeToCustomLog("LBS_MQTT_LBSID", $dbgTxts[$thisTxtDbgLevel], $str);
    }
}

function mqtt_writeGA($koID, $val, &$cache) {
    if ($koID <= 0) return;
    $key = (string)$koID;
    if (isset($cache[$key]) && (string)$cache[$key] === (string)$val) return;
    $cache[$key] = (string)$val;
    writeGA($koID, $val);
}

// ── DB: Channels laden ────────────────────────────────────────────────────────

$dbMqtt = mysqli_connect("localhost", "root", "", "");

mqtt_initDB($dbMqtt);

// Broker-Config für Admin-Test-Publish speichern
$hEsc = mysqli_real_escape_string($dbMqtt, $brokerHost);
$uEsc = mysqli_real_escape_string($dbMqtt, $brokerUser);
$pEsc = mysqli_real_escape_string($dbMqtt, $brokerPass);
mysqli_query($dbMqtt,
    "INSERT INTO edomiProject.mqttBroker (id,host,port,username,password)
     VALUES (1,'$hEsc',$brokerPort,'$uEsc','$pEsc')
     ON DUPLICATE KEY UPDATE host='$hEsc', port=$brokerPort, username='$uEsc', password='$pEsc'"
);

$channels  = [];  // cid => channel-row
$byTopic   = [];  // subscribeTopic => [cid, ...]
$dynInputs = [];  // dynIdx => cid

$res = mysqli_query($dbMqtt,
    "SELECT c.id, c.name, c.subscribeTopic, c.publishTopic,
            c.dataType, c.jsonPath, c.valueTemplate, c.commandTemplate,
            c.valueMapIn, c.valueMapOut, c.direction, c.koIDsub, c.koIDpub
     FROM edomiProject.mqttChannel c
     WHERE c.koIDsub > 0 OR c.koIDpub > 0
     ORDER BY c.id"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $cid = (int)$row['id'];
        $row['valueMapIn']  = $row['valueMapIn']  ? json_decode($row['valueMapIn'],  true) : null;
        $row['valueMapOut'] = $row['valueMapOut'] ? json_decode($row['valueMapOut'], true) : null;
        $row['koIDsub']     = (int)$row['koIDsub'];
        $row['koIDpub']     = (int)$row['koIDpub'];
        $channels[$cid] = $row;

        $dir = $row['direction'];
        if (($dir === 'both' || $dir === 'subscribe') && $row['subscribeTopic'] !== '' && $row['koIDsub'] > 0) {
            $byTopic[$row['subscribeTopic']][] = $cid;
        }
    }
}

exec_debug(1, "Channels geladen: " . count($channels));

// Alte dynamische Eingänge löschen
sql_call("DELETE FROM edomiLive.RAMlogicLink WHERE eingang >= 10 AND elementid=" . (int)$id);

// Dynamische Eingänge für publish-Channels (koIDpub) registrieren
$dynIdx = 10;
foreach ($channels as $cid => $ch) {
    $dir = $ch['direction'];
    if (($dir === 'both' || $dir === 'publish') && $ch['publishTopic'] !== '' && $ch['koIDpub'] > 0) {
        $koID = $ch['koIDpub'];
        $dynInputs[$dynIdx] = $cid;
        sql_call(
            "INSERT INTO edomiLive.RAMlogicLink
             (elementid,functionid,eingang,linktyp,linkid,ausgang,init,refresh,value)
             VALUES ($id,$lbsID,$dynIdx,0,$koID,NULL,2,0,'0')"
        );
        exec_debug(2, "Dyn-Eingang E$dynIdx → KO $koID (pub) Channel: " . $ch['name']);
        $dynIdx++;
    }
}

if (empty($channels)) {
    exec_debug(1, "Keine Channels konfiguriert. Admin aufrufen: JSON importieren oder Discovery nutzen.");
}

// ── DB schließen — nicht mehr benötigt im Betrieb ────────────────────────────
mysqli_close($dbMqtt);
unset($dbMqtt);

// ── Verbindungsschleife ───────────────────────────────────────────────────────

$gaCache      = [];
$clientId     = 'edomi_mqtt_' . $lbsID . '_' . substr(md5(uniqid()), 0, 8);
$stop         = false;
$deviceTopics = array_keys($byTopic);

exec_debug(1, "Verbinde zu $brokerHost:$brokerPort (Client-ID: $clientId)");

do {
    $mqtt = new MqttClient($brokerHost, $brokerPort, $clientId, $brokerUser, $brokerPass);

    try {
        $mqtt->connect();
        exec_debug(1, "MQTT verbunden.");
        if (!empty($deviceTopics)) $mqtt->subscribe($deviceTopics);
        exec_debug(1, "Subscribed: " . count($deviceTopics) . " Device-Topics");

        // Innere Loop ebenfalls im try — writePacket() kann bei Verbindungsabbruch werfen
        do {
            $mqtt->loop(function($topic, $payload) use (&$gaCache, $byTopic, $channels) {
                if (!isset($byTopic[$topic])) return;
                foreach ($byTopic[$topic] as $cid) {
                    $ch  = $channels[$cid];
                    $val = mqtt_applyReceive($payload, $ch);
                    exec_debug(2, "RX [$topic] '$payload' → KO " . $ch['koIDsub'] . " = '$val'");
                    mqtt_writeGA($ch['koIDsub'], $val, $gaCache);
                }
            }, 1.0);

            if ($qE = logic_getInputsQueued($id)) {
                if (isset($hasWrapper)) $qE = W_logic_getInputsQueued($id);

                if (isset($qE[1]) && $qE[1]['refresh'] && (int)$qE[1]['value'] !== 1) {
                    exec_debug(1, "E1=0: LBS wird beendet.");
                    $stop = true;
                    break;
                }

                foreach ($dynInputs as $dIdx => $cid) {
                    if (!(int)($qE[$dIdx]['refresh'] ?? 0)) continue;
                    $ch      = $channels[$cid];
                    $rawVal  = (string)($qE[$dIdx]['value'] ?? '');
                    $payload = mqtt_applySend($rawVal, $ch);
                    exec_debug(1, "TX [" . $ch['publishTopic'] . "] = '$payload' (KO " . $ch['koIDpub'] . ")");
                    $mqtt->publish($ch['publishTopic'], $payload);
                }
            }

        } while (!$stop && getSysInfo(1) >= 1 && $mqtt->isConnected());

    } catch (\Throwable $e) {
        exec_debug(0, "MQTT Fehler: " . $e->getMessage());
    }

    // Immer aufrufen — stellt Error-Handler wieder her
    $mqtt->disconnect();

    if ($stop || getSysInfo(1) < 1) break;

    exec_debug(1, "Reconnect in {$reconnectDelay}s...");
    for ($i = 0; $i < $reconnectDelay && !$stop && getSysInfo(1) >= 1; $i++) {
        if ($qE = logic_getInputsQueued($id)) {
            if (isset($hasWrapper)) $qE = W_logic_getInputsQueued($id);
            if (isset($qE[1]) && $qE[1]['refresh'] && (int)$qE[1]['value'] !== 1) {
                $stop = true;
            }
        }
        if (!$stop) sleep(1);
    }

} while (!$stop && getSysInfo(1) >= 1);

exec_debug(1, "LBS gestoppt.");
logic_setVar($id, 1, 0);
sql_disconnect();
?>
###[/EXEC]###

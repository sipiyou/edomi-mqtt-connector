<?php
ob_start();
define('MAIN_PATH', '__INSERT_EDOMI_PATH__');

$mysqli  = mysqli_connect("localhost", "root", "", "");
$_mqttLib = MAIN_PATH . '/main/include/php/MQTT/MqttClient.php';
if (file_exists($_mqttLib)) require_once $_mqttLib;
require_once dirname(__FILE__) . '/ko_picker.php';

/*
  MQTT Connector Admin v1.02
  (w)(c) 2026 Nima Ghassemi Nejad
*/

// ── DB-Schema ────────────────────────────────────────────────────────────────

function checkDB($mysqli) {
    if (function_exists('mqtt_initDB')) {
        mqtt_initDB($mysqli);
    }
}

checkDB($mysqli);

// Frühzeitig berechnen — wird im <script>-Block benötigt
$deviceOpts  = '';
$devicesJson = [];
foreach (getDevices($mysqli) as $dv) {
    $deviceOpts .= '<option value="' . (int)$dv['id'] . '">' . h($dv['deviceName']) . '</option>';
    $devicesJson[] = ['id' => (int)$dv['id'], 'name' => $dv['deviceName']];
}

// ── Broker-Check ─────────────────────────────────────────────────────────────

function checkBroker(string $host = 'localhost', int $port = 1883): bool {
    $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($sock) { fclose($sock); return true; }
    return false;
}

// ── Helper ───────────────────────────────────────────────────────────────────

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function koName($mysqli, $koID) {
    if ((int)$koID <= 0) return '';
    $r = mysqli_query($mysqli, "SELECT koName FROM edomiLive.editKo WHERE koID=" . (int)$koID . " LIMIT 1");
    if ($r && ($row = mysqli_fetch_assoc($r))) return $row['koName'];
    return '';
}

function getDevices($mysqli) {
    $res = mysqli_query($mysqli,
        "SELECT d.id, d.deviceName,
                COUNT(c.id) AS chTotal,
                SUM(c.koIDsub > 0 OR c.koIDpub > 0) AS chMapped
         FROM edomiProject.mqttDevice d
         LEFT JOIN edomiProject.mqttChannel c ON c.deviceID = d.id
         GROUP BY d.id ORDER BY d.deviceName");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function getChannels($mysqli, $devID) {
    $devID = (int)$devID;
    $res = mysqli_query($mysqli,
        "SELECT * FROM edomiProject.mqttChannel WHERE deviceID=$devID ORDER BY id");
    if (!$res) return [];
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $r['koNameSub'] = koName($mysqli, $r['koIDsub'] ?? 0);
        $r['koNamePub'] = koName($mysqli, $r['koIDpub'] ?? 0);
        $rows[] = $r;
    }
    return $rows;
}

function getDiscovered($mysqli) {
    $res = mysqli_query($mysqli,
        "SELECT d.*,
                (SELECT ch.id FROM edomiProject.mqttChannel ch
                 WHERE ch.discoveryTopic = d.discoveryTopic LIMIT 1) AS channelID
         FROM edomiProject.mqttDiscovered d
         ORDER BY d.component, d.seen_at DESC");
    $rows = [];
    if (!$res) return [];
    while ($r = mysqli_fetch_assoc($res)) {
        $r['pl'] = json_decode($r['payload'], true) ?? [];
        $rows[] = $r;
    }
    return $rows;
}

// ── JSON Import ──────────────────────────────────────────────────────────────

function importJson($mysqli, $json, $targetDevID = 0) {
    $data = json_decode($json, true);
    if (!$data || !isset($data['device']) || !isset($data['channels'])) {
        return "Ungültiges JSON ('device' und 'channels' erforderlich).";
    }
    $targetDevID = (int)$targetDevID;
    if ($targetDevID > 0) {
        $devRes = mysqli_query($mysqli, "SELECT deviceName FROM edomiProject.mqttDevice WHERE id=$targetDevID LIMIT 1");
        if (!($devRow = mysqli_fetch_assoc($devRes))) return "Zielgerät nicht gefunden.";
        $devID   = $targetDevID;
        $devName = $devRow['deviceName'];
    } else {
        $devName = $data['device'];
        $devNameEsc = mysqli_real_escape_string($mysqli, $devName);
        $res = mysqli_query($mysqli, "SELECT id FROM edomiProject.mqttDevice WHERE deviceName='$devNameEsc' LIMIT 1");
        if ($row = mysqli_fetch_assoc($res)) {
            $devID = (int)$row['id'];
        } else {
            mysqli_query($mysqli, "INSERT INTO edomiProject.mqttDevice (deviceName) VALUES ('$devNameEsc')");
            $devID = (int)mysqli_insert_id($mysqli);
        }
    }

    $imported = 0;
    foreach ($data['channels'] as $ch) {
        $name     = mysqli_real_escape_string($mysqli, $ch['name']            ?? '');
        $subT     = mysqli_real_escape_string($mysqli, $ch['subscribeTopic']  ?? '');
        $pubT     = mysqli_real_escape_string($mysqli, $ch['publishTopic']    ?? '');
        $dtype    = mysqli_real_escape_string($mysqli, $ch['dataType']        ?? 'string');
        $valTpl   = mysqli_real_escape_string($mysqli, $ch['valueTemplate']   ?? '');
        $cmdTpl   = mysqli_real_escape_string($mysqli, $ch['commandTemplate'] ?? '');
        $dir      = mysqli_real_escape_string($mysqli, $ch['direction']       ?? 'both');
        // sendByChange (Default true) wird als @nosbc-Marker im note kodiert (keine eigene Spalte).
        $noteRaw = (string)($ch['note'] ?? '');
        $noteRaw = trim(preg_replace('/\s*@nosbc\b/i', '', $noteRaw));   // bestehenden Marker entfernen (idempotent)
        $sbcOn   = !array_key_exists('sendByChange', $ch) || $ch['sendByChange'] !== false;
        if (!$sbcOn) $noteRaw = ($noteRaw === '' ? '@nosbc' : $noteRaw . ' @nosbc');
        $note     = mysqli_real_escape_string($mysqli, $noteRaw);
        $mapIn    = isset($ch['valueMapIn'])  ? "'".mysqli_real_escape_string($mysqli,json_encode($ch['valueMapIn']))."'"  : 'NULL';
        $mapOut   = isset($ch['valueMapOut']) ? "'".mysqli_real_escape_string($mysqli,json_encode($ch['valueMapOut']))."'" : 'NULL';

        $chk = mysqli_query($mysqli,
            "SELECT id, koIDsub, koIDpub FROM edomiProject.mqttChannel WHERE deviceID=$devID AND name='$name' LIMIT 1");
        if ($ex = mysqli_fetch_assoc($chk)) {
            // Update: Topics bestehender Channels bleiben erhalten (Platzhalter nicht überschreiben)
            $topicUpdate = $targetDevID > 0 ? '' : "subscribeTopic='$subT', publishTopic='$pubT',";
            mysqli_query($mysqli,
                "UPDATE edomiProject.mqttChannel SET
                    $topicUpdate dataType='$dtype',
                    valueTemplate='$valTpl', commandTemplate='$cmdTpl',
                    valueMapIn=$mapIn, valueMapOut=$mapOut, direction='$dir', note='$note'
                 WHERE id=" . (int)$ex['id']);
        } else {
            mysqli_query($mysqli,
                "INSERT INTO edomiProject.mqttChannel
                    (deviceID,name,subscribeTopic,publishTopic,dataType,
                     valueTemplate,commandTemplate,valueMapIn,valueMapOut,direction,note,koIDsub,koIDpub)
                 VALUES ($devID,'$name','$subT','$pubT','$dtype',
                         '$valTpl','$cmdTpl',$mapIn,$mapOut,'$dir','$note',0,0)");
        }
        $imported++;
    }
    return "OK: Gerät '$devName' importiert, $imported Channels angelegt/aktualisiert.";
}

// ── Discovery Import ──────────────────────────────────────────────────────────

function importDiscovered($mysqli, $dtopic) {
    $dtopicEsc = mysqli_real_escape_string($mysqli, $dtopic);
    $res = mysqli_query($mysqli,
        "SELECT * FROM edomiProject.mqttDiscovered WHERE discoveryTopic='$dtopicEsc' LIMIT 1");
    if (!($row = mysqli_fetch_assoc($res))) return "Entität nicht gefunden.";

    $pl = json_decode($row['payload'], true) ?? [];
    $parts   = explode('/', $dtopic);
    $objectId = count($parts) >= 3 ? $parts[count($parts) - 2] : 'unknown';
    $devName  = isset($pl['device']['name']) ? (string)$pl['device']['name'] : $objectId;
    $devNameEsc = mysqli_real_escape_string($mysqli, $devName);

    $res2 = mysqli_query($mysqli, "SELECT id FROM edomiProject.mqttDevice WHERE deviceName='$devNameEsc' LIMIT 1");
    if ($dRow = mysqli_fetch_assoc($res2)) {
        $devID = (int)$dRow['id'];
    } else {
        mysqli_query($mysqli, "INSERT INTO edomiProject.mqttDevice (deviceName) VALUES ('$devNameEsc')");
        $devID = (int)mysqli_insert_id($mysqli);
    }

    $chName  = mysqli_real_escape_string($mysqli, $pl['name']                 ?? $objectId);
    $subT    = mysqli_real_escape_string($mysqli, $pl['state_topic']          ?? '');
    $pubT    = mysqli_real_escape_string($mysqli, $pl['command_topic']        ?? '');
    $valTpl  = mysqli_real_escape_string($mysqli, $pl['value_template']       ?? '');
    $cmdTpl  = mysqli_real_escape_string($mysqli, $pl['command_template']     ?? '');
    $unit    = mysqli_real_escape_string($mysqli, $pl['unit_of_measurement']  ?? '');
    $devCls  = mysqli_real_escape_string($mysqli, $pl['device_class']         ?? $row['component']);

    $direction = 'subscribe';
    if ($subT !== '' && $pubT !== '') $direction = 'both';
    elseif ($pubT !== '')             $direction = 'publish';

    $numericClasses = ['temperature','humidity','pressure','power','energy','voltage',
                       'current','battery','illuminance','distance','speed','co2','pm25','frequency'];
    $dataType = 'string';
    if (in_array($pl['device_class'] ?? '', $numericClasses)) $dataType = 'float';
    if ($row['component'] === 'binary_sensor') $dataType = 'bool';
    if ($row['component'] === 'switch')        $dataType = 'int';

    $chk = mysqli_query($mysqli,
        "SELECT id FROM edomiProject.mqttChannel WHERE deviceID=$devID AND name='$chName' LIMIT 1");
    if ($ex = mysqli_fetch_assoc($chk)) {
        mysqli_query($mysqli,
            "UPDATE edomiProject.mqttChannel SET
                subscribeTopic='$subT', publishTopic='$pubT',
                valueTemplate='$valTpl', commandTemplate='$cmdTpl',
                unit='$unit', deviceClass='$devCls', discoveryTopic='$dtopicEsc',
                direction='$direction', dataType='$dataType'
             WHERE id=" . (int)$ex['id']);
        return "OK: Channel '$chName' für Gerät '$devName' aktualisiert.";
    }
    mysqli_query($mysqli,
        "INSERT INTO edomiProject.mqttChannel
            (deviceID,name,subscribeTopic,publishTopic,dataType,
             valueTemplate,commandTemplate,valueMapIn,valueMapOut,
             direction,unit,deviceClass,discoveryTopic,koIDsub,koIDpub)
         VALUES ($devID,'$chName','$subT','$pubT','$dataType',
                 '$valTpl','$cmdTpl',NULL,NULL,'$direction',
                 '$unit','$devCls','$dtopicEsc',0,0)");
    return "OK: Channel '$chName' für Gerät '$devName' importiert.";
}

// ── Tasmota Discovery Import ──────────────────────────────────────────────────

function importTasmotaConfig($mysqli, $dtopic) {
    $dtopicEsc = mysqli_real_escape_string($mysqli, $dtopic);
    $res = mysqli_query($mysqli,
        "SELECT * FROM edomiProject.mqttDiscovered WHERE discoveryTopic='$dtopicEsc' LIMIT 1");
    if (!($row = mysqli_fetch_assoc($res))) return "Nicht gefunden.";

    $pl        = json_decode($row['payload'], true) ?? [];
    $baseTopic = (string)($pl['t'] ?? '');
    $tp        = $pl['tp'] ?? ['cmnd', 'stat', 'tele'];
    $ft        = (string)($pl['ft'] ?? '%prefix%/%topic%/');
    $rl        = $pl['rl'] ?? [];
    $fn        = $pl['fn'] ?? [];
    $devName   = ($pl['dn'] ?? null) ?: ($fn[0] ?? 'Tasmota-Gerät');

    $statBase = rtrim(str_replace(['%prefix%','%topic%'], [$tp[1], $baseTopic], $ft), '/');
    $cmndBase = rtrim(str_replace(['%prefix%','%topic%'], [$tp[0], $baseTopic], $ft), '/');
    $teleBase = rtrim(str_replace(['%prefix%','%topic%'], [$tp[2], $baseTopic], $ft), '/');

    $devNameEsc = mysqli_real_escape_string($mysqli, $devName);
    $res2 = mysqli_query($mysqli, "SELECT id FROM edomiProject.mqttDevice WHERE deviceName='$devNameEsc' LIMIT 1");
    if ($dRow = mysqli_fetch_assoc($res2)) {
        $devID = (int)$dRow['id'];
    } else {
        mysqli_query($mysqli, "INSERT INTO edomiProject.mqttDevice (deviceName) VALUES ('$devNameEsc')");
        $devID = (int)mysqli_insert_id($mysqli);
    }

    // --- Relay channels ---
    $relayCount = array_sum(array_map('intval', $rl));
    $relayImported = 0;
    $relayIdx = 0;
    foreach ($rl as $i => $r) {
        if ((int)$r < 1) continue;
        $relayIdx++;
        $suffix   = $relayCount > 1 ? (string)$relayIdx : '';
        $chName   = (isset($fn[$i]) && $fn[$i] !== null) ? (string)$fn[$i] : "Power$suffix";
        $subTopic = "$statBase/POWER$suffix";
        $pubTopic = "$cmndBase/POWER$suffix";
        $mapIn    = json_encode(['ON' => 1, 'OFF' => 0]);
        $mapOut   = json_encode(['1' => 'ON', '0' => 'OFF']);

        $chNameEsc = mysqli_real_escape_string($mysqli, $chName);
        $subEsc    = mysqli_real_escape_string($mysqli, $subTopic);
        $pubEsc    = mysqli_real_escape_string($mysqli, $pubTopic);
        $mapInEsc  = mysqli_real_escape_string($mysqli, $mapIn);
        $mapOutEsc = mysqli_real_escape_string($mysqli, $mapOut);

        $chk = mysqli_query($mysqli,
            "SELECT id FROM edomiProject.mqttChannel WHERE deviceID=$devID AND name='$chNameEsc' LIMIT 1");
        if ($ex = mysqli_fetch_assoc($chk)) {
            mysqli_query($mysqli,
                "UPDATE edomiProject.mqttChannel SET
                    subscribeTopic='$subEsc', publishTopic='$pubEsc',
                    valueMapIn='$mapInEsc', valueMapOut='$mapOutEsc',
                    direction='both', dataType='int', discoveryTopic='$dtopicEsc'
                 WHERE id=" . (int)$ex['id']);
        } else {
            mysqli_query($mysqli,
                "INSERT INTO edomiProject.mqttChannel
                    (deviceID,name,subscribeTopic,publishTopic,dataType,
                     valueMapIn,valueMapOut,direction,discoveryTopic,koIDsub,koIDpub)
                 VALUES ($devID,'$chNameEsc','$subEsc','$pubEsc','int',
                         '$mapInEsc','$mapOutEsc','both','$dtopicEsc',0,0)");
        }
        $relayImported++;
    }

    // --- Sensor channels from paired tasmota_sensors entry ---
    $sensorsDtopic = preg_replace('#/config$#', '/sensors', $dtopic);
    $sdEsc = mysqli_real_escape_string($mysqli, $sensorsDtopic);
    $sRes  = mysqli_query($mysqli,
        "SELECT payload FROM edomiProject.mqttDiscovered WHERE discoveryTopic='$sdEsc' LIMIT 1");
    $sensorImported = 0;
    if ($sRow = mysqli_fetch_assoc($sRes)) {
        $spl      = json_decode($sRow['payload'], true) ?? [];
        $sn       = $spl['sn'] ?? [];
        $skipSn   = ['Time', 'PressureUnit', 'TempUnit'];
        $skipFld  = ['Id', 'TotalStartTime'];
        $sensTopicEsc = mysqli_real_escape_string($mysqli, "$teleBase/SENSOR");

        foreach ($sn as $sensorType => $sensorVal) {
            if (in_array($sensorType, $skipSn, true) || !is_array($sensorVal)) continue;
            foreach ($sensorVal as $field => $val) {
                if (in_array($field, $skipFld, true) || !is_numeric($val)) continue;
                $chName   = "$sensorType $field";
                $dtype    = is_float($val) ? 'float' : 'int';
                $jsonPath = "$sensorType.$field";
                $chNameEsc = mysqli_real_escape_string($mysqli, $chName);
                $jpEsc     = mysqli_real_escape_string($mysqli, $jsonPath);

                $chk = mysqli_query($mysqli,
                    "SELECT id FROM edomiProject.mqttChannel WHERE deviceID=$devID AND name='$chNameEsc' LIMIT 1");
                if ($ex = mysqli_fetch_assoc($chk)) {
                    mysqli_query($mysqli,
                        "UPDATE edomiProject.mqttChannel SET
                            subscribeTopic='$sensTopicEsc', publishTopic='',
                            jsonPath='$jpEsc', direction='subscribe',
                            dataType='$dtype', discoveryTopic='$dtopicEsc'
                         WHERE id=" . (int)$ex['id']);
                } else {
                    mysqli_query($mysqli,
                        "INSERT INTO edomiProject.mqttChannel
                            (deviceID,name,subscribeTopic,publishTopic,dataType,
                             jsonPath,direction,discoveryTopic,koIDsub,koIDpub)
                         VALUES ($devID,'$chNameEsc','$sensTopicEsc','','$dtype',
                                 '$jpEsc','subscribe','$dtopicEsc',0,0)");
                }
                $sensorImported++;
            }
        }
    }

    $parts = [];
    if ($relayImported > 0)  $parts[] = "$relayImported Relais";
    if ($sensorImported > 0) $parts[] = "$sensorImported Sensoren";
    $summary = $parts ? implode(', ', $parts) : 'keine Channels (keine Relais, keine Sensordaten)';
    return "OK: '$devName' importiert — $summary.";
}

// ── AJAX-Endpunkte ───────────────────────────────────────────────────────────

if (isset($_GET['action'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $req = $_GET + $_POST;

    switch ($req['action']) {
        case 'searchKO':
            $q = '%' . mysqli_real_escape_string($mysqli, $req['q'] ?? '') . '%';
            $r = mysqli_query($mysqli,
                "SELECT koID, koName FROM edomiLive.editKo
                 WHERE koName LIKE '$q' OR koID LIKE '$q' ORDER BY koName LIMIT 50");
            $rows = [];
            if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
            echo json_encode($rows);
            break;

        case 'getKO':
            $koID = (int)($req['koID'] ?? 0);
            $r = mysqli_query($mysqli,
                "SELECT koID, koName FROM edomiLive.editKo WHERE koID=$koID LIMIT 1");
            echo json_encode($r ? (mysqli_fetch_assoc($r) ?: null) : null);
            break;

        case 'saveKOBatch':
            $changes = json_decode($req['changes'] ?? '[]', true) ?: [];
            foreach ($changes as $c) {
                $chID = (int)($c['chID'] ?? 0);
                if ($chID <= 0) continue;
                if (array_key_exists('sub', $c)) {
                    $koID = (int)$c['sub'];
                    mysqli_query($mysqli, "UPDATE edomiProject.mqttChannel SET koIDsub=$koID WHERE id=$chID");
                }
                if (array_key_exists('pub', $c)) {
                    $koID = (int)$c['pub'];
                    mysqli_query($mysqli, "UPDATE edomiProject.mqttChannel SET koIDpub=$koID WHERE id=$chID");
                }
            }
            echo json_encode(['ok' => true, 'count' => count($changes)]);
            break;

        case 'saveKOsub':
            $chID = (int)($req['channelID'] ?? 0);
            $koID = (int)($req['koID'] ?? 0);
            mysqli_query($mysqli, "UPDATE edomiProject.mqttChannel SET koIDsub=$koID WHERE id=$chID");
            echo json_encode(['ok' => true]);
            break;

        case 'saveKOpub':
            $chID = (int)($req['channelID'] ?? 0);
            $koID = (int)($req['koID'] ?? 0);
            mysqli_query($mysqli, "UPDATE edomiProject.mqttChannel SET koIDpub=$koID WHERE id=$chID");
            echo json_encode(['ok' => true]);
            break;

        case 'testPublish':
            $chID = (int)($req['chID'] ?? 0);
            $val  = (string)($req['val'] ?? '');
            $res  = mysqli_query($mysqli,
                "SELECT publishTopic, commandTemplate, valueMapOut FROM edomiProject.mqttChannel WHERE id=$chID LIMIT 1");
            if (!$res || !($ch = mysqli_fetch_assoc($res))) {
                echo json_encode(['ok' => false, 'msg' => 'Channel nicht gefunden.']);
                break;
            }
            $broker = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT * FROM edomiProject.mqttBroker LIMIT 1") ?: false) ?: [];
            if (empty($broker)) {
                echo json_encode(['ok' => false, 'msg' => 'Broker-Config fehlt — LBS einmal starten (E1=1).']);
                break;
            }
            $mqttLib = MAIN_PATH . '/main/include/php/MQTT/MqttClient.php';
            if (!file_exists($mqttLib)) {
                echo json_encode(['ok' => false, 'msg' => 'MqttClient nicht installiert — LBS starten (E1=1).']);
                break;
            }
            require_once $mqttLib;
            // valueMapOut + commandTemplate (mqtt_evalTemplate kommt aus MqttClient.php)
            $payload = $val;
            if ($ch['valueMapOut']) {
                $map = json_decode($ch['valueMapOut'], true) ?? [];
                if (isset($map[$val])) $payload = (string)$map[$val];
            }
            $payload = mqtt_evalTemplate((string)($ch['commandTemplate'] ?? ''), $payload);
            try {
                $mqtt = new MqttClient($broker['host'], (int)$broker['port'],
                    'edomi_admin_test_' . rand(1000,9999), $broker['username'], $broker['password']);
                $mqtt->connect();
                $mqtt->publish($ch['publishTopic'], $payload);
                $mqtt->disconnect();
                echo json_encode(['ok' => true, 'msg' => 'Gesendet: ' . $ch['publishTopic'] . ' = ' . $payload]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => 'Fehler: ' . $e->getMessage()]);
            }
            break;

        case 'renameDevice':
            $devID   = (int)($req['devID'] ?? 0);
            $newName = trim($req['name'] ?? '');
            if ($devID <= 0 || $newName === '') { echo json_encode(['ok' => false]); break; }
            $nEsc = mysqli_real_escape_string($mysqli, $newName);
            mysqli_query($mysqli, "UPDATE edomiProject.mqttDevice SET deviceName='$nEsc' WHERE id=$devID");
            echo json_encode(['ok' => true, 'name' => $newName]);
            break;

        case 'deleteDevice':
            $devID = (int)($req['devID'] ?? 0);
            mysqli_query($mysqli, "DELETE FROM edomiProject.mqttChannel WHERE deviceID=$devID");
            mysqli_query($mysqli, "DELETE FROM edomiProject.mqttDevice WHERE id=$devID");
            echo json_encode(['ok' => true]);
            break;

        case 'deleteChannel':
            $chID = (int)($req['channelID'] ?? 0);
            mysqli_query($mysqli, "DELETE FROM edomiProject.mqttChannel WHERE id=$chID");
            echo json_encode(['ok' => true]);
            break;

        case 'importJson':
            echo json_encode(['msg' => importJson($mysqli, $req['json'] ?? '', (int)($req['targetDevID'] ?? 0))]);
            break;

        case 'importDiscovered':
            echo json_encode(['msg' => importDiscovered($mysqli, $req['dtopic'] ?? '')]);
            break;

        case 'importTasmota':
            echo json_encode(['msg' => importTasmotaConfig($mysqli, $req['dtopic'] ?? '')]);
            break;

        case 'clearDiscovered':
            mysqli_query($mysqli, "TRUNCATE TABLE edomiProject.mqttDiscovered");
            echo json_encode(['ok' => true]);
            break;

        case 'startScan':
            $dur    = min(60, max(10, (int)($req['duration'] ?? 30)));
            $broker = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT * FROM edomiProject.mqttBroker LIMIT 1") ?: false) ?: [];
            if (empty($broker)) {
                echo json_encode(['ok' => false, 'msg' => 'Broker-Config fehlt — LBS einmal starten (E1=1).']);
                break;
            }
            $mqttLib = MAIN_PATH . '/main/include/php/MQTT/MqttClient.php';
            if (!file_exists($mqttLib)) {
                echo json_encode(['ok' => false, 'msg' => 'MqttClient nicht installiert — LBS starten (E1=1).']);
                break;
            }
            require_once $mqttLib;
            set_time_limit($dur + 30);
            mysqli_query($mysqli, "TRUNCATE TABLE edomiProject.mqttTopicScan");
            mysqli_query($mysqli,
                "INSERT INTO edomiProject.mqttBroker (id, scanUntil)
                 VALUES (1, DATE_ADD(NOW(), INTERVAL $dur SECOND))
                 ON DUPLICATE KEY UPDATE scanUntil = DATE_ADD(NOW(), INTERVAL $dur SECOND)");
            $clientId  = 'edomi_mqtt_scan_' . substr(md5(uniqid()), 0, 8);
            $mqtt      = new MqttClient($broker['host'], (int)$broker['port'],
                             $clientId, $broker['username'], $broker['password']);
            $cancelled = false;
            $lastCheck = 0;
            try {
                $mqtt->connect();
                $mqtt->subscribe(['#']);
                $mqtt->loop(function($topic, $payload) use ($mysqli, &$cancelled, &$lastCheck) {
                    $now = time();
                    if ($now - $lastCheck >= 2) {
                        $lastCheck = $now;
                        $sRow = mysqli_fetch_assoc(mysqli_query($mysqli,
                            "SELECT scanUntil FROM edomiProject.mqttBroker WHERE id=1 LIMIT 1"));
                        if (!$sRow || $sRow['scanUntil'] === null) {
                            $cancelled = true;
                            return true;
                        }
                    }
                    $tEsc = mysqli_real_escape_string($mysqli, $topic);
                    $pEsc = mysqli_real_escape_string($mysqli, mb_substr($payload, 0, 2048));
                    mysqli_query($mysqli,
                        "INSERT INTO edomiProject.mqttTopicScan (topic, payload, seen_at)
                         VALUES ('$tEsc', '$pEsc', NOW())
                         ON DUPLICATE KEY UPDATE payload='$pEsc', seen_at=NOW()");
                }, (float)$dur);
                $mqtt->disconnect();
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => 'MQTT-Fehler: ' . $e->getMessage()]);
                break;
            }
            mysqli_query($mysqli, "UPDATE edomiProject.mqttBroker SET scanUntil=NULL WHERE id=1");
            $total = (int)(mysqli_fetch_row(mysqli_query($mysqli,
                "SELECT COUNT(*) FROM edomiProject.mqttTopicScan"))[0] ?? 0);
            echo json_encode(['ok' => true, 'count' => $total, 'cancelled' => $cancelled]);
            break;

        case 'cancelScan':
            mysqli_query($mysqli, "UPDATE edomiProject.mqttBroker SET scanUntil=NULL WHERE id=1");
            echo json_encode(['ok' => true]);
            break;

        case 'getScanResults':
            $res = mysqli_query($mysqli,
                "SELECT topic, payload FROM edomiProject.mqttTopicScan ORDER BY topic");
            $topics = [];
            if ($res) {
                while ($r = mysqli_fetch_assoc($res)) {
                    $pl = trim($r['payload'] ?? '');
                    $isJson = strlen($pl) > 0 && $pl[0] === '{';
                    $keys = [];
                    if ($isJson) {
                        $dec = json_decode($pl, true);
                        if (is_array($dec)) {
                            foreach ($dec as $k => $v) {
                                if (is_scalar($v)) $keys[] = $k;
                                elseif (is_array($v)) {
                                    foreach ($v as $k2 => $v2) {
                                        if (is_scalar($v2)) $keys[] = "$k.$k2";
                                    }
                                }
                            }
                        }
                    }
                    $topics[] = [
                        'topic'   => $r['topic'],
                        'payload' => mb_substr($pl, 0, 80),
                        'isJson'  => $isJson,
                        'keys'    => $keys,
                    ];
                }
            }
            echo json_encode($topics);
            break;

        case 'createChannelFromScan':
            $devID  = (int)($req['deviceID']  ?? 0);
            $newDev = trim($req['newDevice']  ?? '');
            $topic  = trim($req['topic']      ?? '');
            $chName = trim($req['name']       ?? '');
            $dtype  = in_array($req['dtype'] ?? '', ['string','int','float','bool']) ? $req['dtype'] : 'float';
            $jp     = trim($req['jsonPath']   ?? '');
            if ($devID <= 0 && $newDev !== '') {
                $nEsc = mysqli_real_escape_string($mysqli, $newDev);
                // Existierendes Gerät gleichen Namens finden (verhindert Duplikate)
                $exRes = mysqli_query($mysqli,
                    "SELECT id FROM edomiProject.mqttDevice WHERE deviceName='$nEsc' LIMIT 1");
                if ($exRow = mysqli_fetch_assoc($exRes)) {
                    $devID = (int)$exRow['id'];
                } else {
                    mysqli_query($mysqli,
                        "INSERT INTO edomiProject.mqttDevice (deviceName) VALUES ('$nEsc')");
                    $devID = (int)mysqli_insert_id($mysqli);
                }
            }
            if ($devID <= 0 || $topic === '' || $chName === '') {
                echo json_encode(['ok' => false, 'msg' => 'Fehlende Pflichtfelder.']);
                break;
            }
            // Gerätename für JS-Rückgabe holen
            $devNameRow = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT deviceName FROM edomiProject.mqttDevice WHERE id=$devID LIMIT 1"));
            $devNameOut = $devNameRow ? $devNameRow['deviceName'] : '';
            $tEsc  = mysqli_real_escape_string($mysqli, $topic);
            $nEsc  = mysqli_real_escape_string($mysqli, $chName);
            $jpEsc = mysqli_real_escape_string($mysqli, $jp);
            $ok = mysqli_query($mysqli,
                "INSERT INTO edomiProject.mqttChannel
                    (deviceID, name, subscribeTopic, publishTopic, dataType, jsonPath, direction, koIDsub, koIDpub)
                 VALUES ($devID, '$nEsc', '$tEsc', '', '$dtype', '$jpEsc', 'subscribe', 0, 0)");
            if (!$ok) {
                echo json_encode(['ok' => false, 'msg' => 'DB-Fehler: ' . mysqli_error($mysqli)]);
                break;
            }
            echo json_encode(['ok' => true, 'channelID' => (int)mysqli_insert_id($mysqli),
                              'deviceID' => $devID, 'deviceName' => $devNameOut]);
            break;

        case 'replacePrefix':
            $devID  = (int)($req['devID']  ?? 0);
            $search = trim($req['search']  ?? '');
            $repl   = trim($req['replace'] ?? '');
            if ($devID <= 0 || $search === '') { echo json_encode(['ok' => false]); break; }
            $sEsc = mysqli_real_escape_string($mysqli, $search);
            $rEsc = mysqli_real_escape_string($mysqli, $repl);
            mysqli_query($mysqli,
                "UPDATE edomiProject.mqttChannel SET
                    subscribeTopic = REPLACE(subscribeTopic, '$sEsc', '$rEsc'),
                    publishTopic   = REPLACE(publishTopic,   '$sEsc', '$rEsc')
                 WHERE deviceID = $devID");
            $affected = mysqli_affected_rows($mysqli);
            echo json_encode(['ok' => true, 'count' => $affected]);
            break;

        case 'saveValueMap':
            $chID   = (int)($req['chID']  ?? 0);
            $mapIn  = trim($req['mapIn']  ?? '');
            $mapOut = trim($req['mapOut'] ?? '');
            $mapInSql  = 'NULL';
            $mapOutSql = 'NULL';
            if ($mapIn !== '' && $mapIn !== '{}') {
                $dec = json_decode($mapIn, true);
                if ($dec !== null && count($dec) > 0) $mapInSql  = "'" . mysqli_real_escape_string($mysqli, json_encode($dec)) . "'";
            }
            if ($mapOut !== '' && $mapOut !== '{}') {
                $dec = json_decode($mapOut, true);
                if ($dec !== null && count($dec) > 0) $mapOutSql = "'" . mysqli_real_escape_string($mysqli, json_encode($dec)) . "'";
            }
            if ($chID > 0) {
                mysqli_query($mysqli, "UPDATE edomiProject.mqttChannel SET valueMapIn=$mapInSql, valueMapOut=$mapOutSql WHERE id=$chID");
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Ungültige Channel-ID']);
            }
            break;

        default:
            echo json_encode(['error' => 'unknown action']);
    }
    exit;
}

ob_end_clean();

// ── HTML-Output ──────────────────────────────────────────────────────────────

$view  = $_GET['view']  ?? 'devices';
$devID = (int)($_GET['devID'] ?? 0);
$msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['importJson'])) {
        $msg = importJson($mysqli, $_POST['importJson']);
    }
}

$baseHref = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

$dirLabels = [
    'subscribe' => '<span class="dir-sub">&#8595; empfangen</span>',
    'publish'   => '<span class="dir-pub">&#8593; senden</span>',
    'both'      => '<span class="dir-both">&#8597; beides</span>',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<base href="<?= h($baseHref) ?>">
<meta charset="UTF-8">
<title>Edomi MQTT-Connector Admin</title>
<link rel="stylesheet" href="ko_picker.css">
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 12px; background: #343434; color: #000; margin: 0; padding: 10px; }
input, select, textarea { font-family: inherit; font-size: inherit; color: #000; background: #fff; }
.page-wrap {
    display: inline-block; border-radius: 3px;
    box-shadow: 3px 10px 40px #303030;
    background: linear-gradient(to bottom, #ffffff 0px, #f0f0e9 74px, #ffffff 74px);
    background-repeat: no-repeat; background-color: #fff;
    text-align: left; width: 100%; padding: 10px 12px 15px;
}
h1 { font-size: 15px; font-weight: bold; color: #343434; margin: 0 0 6px; }
h2 { font-size: 13px; color: #343434; margin: 15px 0 5px; padding-top: 12px; border-bottom: 1px dotted #a0a0a0; padding-bottom: 3px; }
.toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.cmdButton {
    display: inline-block; font-family: inherit; font-size: inherit;
    padding: 5px; text-align: center; color: #000;
    background: linear-gradient(to bottom, #d9d9d9 0%, #f0f0f0 100%);
    cursor: pointer; line-height: 15px; border: 1px solid #c0c0c0;
    border-radius: 3px; min-width: 62px; height: 27px; text-decoration: none;
}
.cmdButton:hover { background: #f0f0f0; }
.btn-del {
    display: inline-block; padding: 3px 6px; color: #fff;
    background: linear-gradient(to bottom, #d04040 0%, #a02020 100%);
    cursor: pointer; border: 1px solid #802020; border-radius: 3px; font-size: 11px;
}
.btn-del:hover { background: #d04040; }
.btn-import {
    display: inline-block; font-size: 11px; padding: 3px 8px; color: #fff;
    background: linear-gradient(to bottom, #4080d0 0%, #2060b0 100%);
    cursor: pointer; border: 1px solid #1040a0; border-radius: 3px; white-space: nowrap;
}
.btn-import:hover { background: #4080d0; }
.btn-import:disabled { background: #a0a0a0; border-color: #808080; cursor: default; }
.btn-test {
    display: inline-block; font-size: 11px; padding: 2px 7px; color: #fff;
    background: linear-gradient(to bottom, #a060d0 0%, #7040a0 100%);
    cursor: pointer; border: 1px solid #5020a0; border-radius: 3px; white-space: nowrap;
}
.btn-test:hover { background: #a060d0; }
table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
thead th {
    background: linear-gradient(to bottom, #d9d9d9 0%, #f0f0f0 100%);
    border: 1px solid #c0c0c0; padding: 5px 6px; text-align: left; font-weight: bold;
}
tbody tr:nth-child(odd) { background: #fff; }
tbody tr:nth-child(even) { background: #f5f5f0; }
tbody tr:hover { background: #e8e8e0; }
td { padding: 4px 6px; vertical-align: middle; border-bottom: 1px solid #e8e8e8; }
/* KO-Picker Zellen */
.ko-cell {
    display: flex; align-items: center; min-height: 22px;
    cursor: pointer; padding: 2px 4px; border: 1px solid #d9d9d9; background: #fff;
    border-radius: 2px; min-width: 120px;
}
.ko-cell:hover { border-color: #808080; background: #f0f0e9; }
.ko-cell-empty { color: #909090; font-style: italic; flex: 1; font-size: 11px; }
.ko-cell-name  { flex: 1; color: #008000; font-size: 11px; }
/* Topic-Sub-Zeilen */
.tr-sub td, .tr-pub td, .tr-detail td {
    background: #f4f4f0 !important; font-size: 11px;
    padding: 2px 6px 3px; border-bottom: 1px solid #ececec;
}
.tr-pub td { background: #f4f0f8 !important; }
.ind-sub { color: #0050a0; font-weight: bold; font-size: 11px; width: 22px; text-align: center; }
.ind-pub { color: #800080; font-weight: bold; font-size: 11px; width: 22px; text-align: center; }
.topic-mono { font-family: monospace; color: #404040; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: middle; }
.tr-detail td { background: #f8f8f4 !important; font-family: monospace; color: #505060; }
.test-input { width: 80px; padding: 1px 3px; border: 1px solid #b0b0b0; border-radius: 2px; }
.test-result { font-size: 10px; color: #555; margin-left: 4px; }
/* Messages */
.msg-info { background: #e8f4e8; border: 1px solid #80c080; padding: 6px 8px; margin-bottom: 8px; border-radius: 3px; }
.msg-warn { background: #f4ece8; border: 1px solid #c08080; padding: 6px 8px; margin-bottom: 8px; border-radius: 3px; }
.msg-err  { background: #fde8e8; border: 2px solid #c02020; padding: 8px 10px; margin-bottom: 8px; border-radius: 3px; color: #600; font-weight: bold; }
.msg-err code { font-family: monospace; font-size: 11px; background: #f8d8d8; padding: 1px 4px; border-radius: 2px; font-weight: normal; }
a { color: #c00000; text-decoration: none; }
a:hover { text-decoration: underline; }
.dir-both { color: #555; }
.dir-sub  { color: #0050a0; }
.dir-pub  { color: #800080; }
/* Navigation Tabs */
.nav-tabs { display: flex; gap: 2px; margin-bottom: 12px; border-bottom: 2px solid #c0c0c0; }
.nav-tab {
    padding: 5px 14px; font-size: 12px; font-weight: bold; color: #555;
    background: #e8e8e0; border: 1px solid #c0c0c0; border-bottom: none;
    border-radius: 3px 3px 0 0; text-decoration: none; cursor: pointer;
}
.nav-tab:hover { background: #f0f0e8; color: #000; }
.nav-tab.active { background: #fff; color: #c00000; border-bottom: 2px solid #fff; margin-bottom: -2px; }
/* Discovery */
.disc-imported { color: #008000; font-weight: bold; font-size: 11px; }
.disc-status   { font-size: 11px; color: #555; margin-left: 4px; }
.comp-badge {
    display: inline-block; font-size: 10px; padding: 1px 5px;
    border-radius: 8px; background: #e0e8f0; color: #204080; border: 1px solid #b0c8e0; white-space: nowrap;
}
.topic-cell { font-family: monospace; font-size: 11px; color: #404040; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tpl-cell   { font-family: monospace; font-size: 10px; color: #505080; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ko-pending { font-style:italic; color:#b06000 !important; }
.btn-map { display:inline-flex;align-items:center;justify-content:center;cursor:pointer;border:1px solid #a0a0a0;border-radius:3px;background:#e8e8f4;color:#4040a0;font-size:12px;padding:1px 5px;margin-right:3px;vertical-align:middle;line-height:1; }
.btn-map:hover { background:#d0d0f0; }
#testToast { font-size:11px;color:#555;margin-bottom:6px;min-height:16px; }
#mapOverlay { display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:2000;align-items:center;justify-content:center; }
#mapOverlay.open { display:flex; }
#mapModal { background:#fff;border-radius:6px;padding:18px 20px;min-width:440px;max-width:600px;box-shadow:0 4px 24px rgba(0,0,0,0.4); }
#mapModal h3 { font-size:13px;margin:0 0 12px;color:#343434; }
.map-section { margin-bottom:14px; }
.map-section > b { font-size:11px;color:#444; }
.map-section > .map-hint { font-size:10px;color:#888; }
.map-table { width:100%;border-collapse:collapse;margin-top:5px; }
.map-table th { font-size:10px;color:#888;font-weight:normal;text-align:left;padding:2px 4px; }
.map-table td { padding:2px 3px; }
.map-table input { width:100%;padding:3px 5px;border:1px solid #ccc;border-radius:3px;font-size:11px; }
.map-table .map-arrow { width:20px;text-align:center;color:#888;font-size:13px; }
.map-table .map-del { width:22px;text-align:center;cursor:pointer;color:#c00;font-size:13px; }
.map-table .map-del:hover { color:#f00; }
</style>
<script src="ko_picker.js"></script>
<script>
// ── KO-Staging (Speichern-Button) ────────────────────────────────────────────
var _pendingKO = {};

function _stageKO(chID, type, koID) {
    if (!_pendingKO[chID]) _pendingKO[chID] = {};
    _pendingKO[chID][type] = koID;
    _updateKOSaveBtn();
}

function _updateKOSaveBtn() {
    var n   = Object.keys(_pendingKO).length;
    var btn = document.getElementById('koBatchSaveBtn');
    var dis = document.getElementById('koBatchDiscardBtn');
    if (btn) { btn.disabled = n === 0; btn.textContent = n > 0 ? 'Speichern (' + n + ')' : 'Speichern'; }
    if (dis) dis.disabled = n === 0;
}

function saveKOBatch() {
    var changes = Object.keys(_pendingKO).map(function(id) {
        return Object.assign({chID: parseInt(id)}, _pendingKO[id]);
    });
    if (!changes.length) return;
    fetch('mqtt_admin.php?action=saveKOBatch', {
        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'changes=' + encodeURIComponent(JSON.stringify(changes))
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) {
            _pendingKO = {};
            document.querySelectorAll('.ko-pending').forEach(function(el) { el.classList.remove('ko-pending'); });
            _updateKOSaveBtn();
        }
    });
}

function discardKO() { location.reload(); }

function mqttPickerOpenSub(chID, curKoId) {
    koPickerOpen({
        currentKoId: curKoId,
        ajaxUrl: 'mqtt_admin.php?',
        onConfirm: function(id, name) {
            var el = document.getElementById('ko_sub_disp_' + chID);
            el.className = 'ko-cell-name ko-pending';
            el.textContent = name + ' [' + id + ']';
            _stageKO(chID, 'sub', id);
        },
        onReset: function() {
            var el = document.getElementById('ko_sub_disp_' + chID);
            el.className = 'ko-cell-empty ko-pending';
            el.textContent = 'KO auswählen';
            _stageKO(chID, 'sub', 0);
        }
    });
}
function mqttPickerOpenPub(chID, curKoId) {
    koPickerOpen({
        currentKoId: curKoId,
        ajaxUrl: 'mqtt_admin.php?',
        onConfirm: function(id, name) {
            var el = document.getElementById('ko_pub_disp_' + chID);
            el.className = 'ko-cell-name ko-pending';
            el.textContent = name + ' [' + id + ']';
            _stageKO(chID, 'pub', id);
        },
        onReset: function() {
            var el = document.getElementById('ko_pub_disp_' + chID);
            el.className = 'ko-cell-empty ko-pending';
            el.textContent = 'KO auswählen';
            _stageKO(chID, 'pub', 0);
        }
    });
}
function showToast(msg, ok) {
    var t = document.getElementById('testToast');
    if (!t) return;
    t.style.color = ok ? '#080' : '#c00';
    t.textContent = msg;
}
function doTestPublish(chID, inputId) {
    var val = document.getElementById(inputId).value;
    showToast('Sende…', true);
    fetch('mqtt_admin.php?action=testPublish&chID=' + chID + '&val=' + encodeURIComponent(val))
        .then(function(r) { return r.json(); })
        .then(function(d) { showToast('Zuletzt gesendet: ' + d.msg, d.ok !== false); });
}
function replacePrefix(devID) {
    var search = document.getElementById('pfxSearch').value.trim();
    var repl   = document.getElementById('pfxReplace').value.trim();
    if (!search) return;
    fetch('mqtt_admin.php?action=replacePrefix', {
        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'devID=' + devID + '&search=' + encodeURIComponent(search) + '&replace=' + encodeURIComponent(repl)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) location.reload();
    });
}
function renameDevice(devID, currentName) {
    var newName = prompt('Gerätename:', currentName);
    if (!newName || newName.trim() === '' || newName.trim() === currentName) return;
    fetch('mqtt_admin.php?action=renameDevice', {
        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'devID=' + devID + '&name=' + encodeURIComponent(newName.trim())
    })
    .then(function(r) { return r.json(); })
    .then(function(d) { if (d.ok) location.reload(); });
}
function delDevice(devID, name) {
    if (!confirm('Gerät "' + name + '" und alle Channels löschen?')) return;
    fetch('mqtt_admin.php?action=deleteDevice&devID=' + devID)
        .then(function() { location.reload(); });
}
function delChannel(chID, name) {
    if (!confirm('Channel "' + name + '" löschen?')) return;
    fetch('mqtt_admin.php?action=deleteChannel&channelID=' + chID)
        .then(function() { location.reload(); });
}
function doImport() {
    var file = document.getElementById('jsonFile').files[0];
    if (!file) return;
    var targetDevID = (document.getElementById('importTarget') || {value:'0'}).value;
    var reader = new FileReader();
    reader.onload = function(e) {
        var json = e.target.result.trim();
        if (!json) return;
        fetch('mqtt_admin.php?action=importJson', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'json=' + encodeURIComponent(json) + '&targetDevID=' + encodeURIComponent(targetDevID)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            document.getElementById('importResult').textContent = d.msg;
            if (d.msg && d.msg.indexOf('OK') === 0) setTimeout(function() { location.reload(); }, 1200);
        });
    };
    reader.readAsText(file);
}
function importDiscovery(idx, topic) {
    var btn = document.getElementById('disc_btn_' + idx);
    var st  = document.getElementById('disc_st_'  + idx);
    if (btn) btn.disabled = true;
    if (st)  st.textContent = 'Importiere...';
    fetch('mqtt_admin.php?action=importDiscovered', {
        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'dtopic=' + encodeURIComponent(topic)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (st) st.textContent = d.msg;
        if (d.msg && d.msg.indexOf('OK') === 0) setTimeout(function() { location.reload(); }, 1200);
        else if (btn) btn.disabled = false;
    });
}
function importTasmota(idx, topic) {
    var btn = document.getElementById('disc_btn_' + idx);
    var st  = document.getElementById('disc_st_'  + idx);
    if (btn) btn.disabled = true;
    if (st)  st.textContent = 'Importiere...';
    fetch('mqtt_admin.php?action=importTasmota', {
        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'dtopic=' + encodeURIComponent(topic)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (st) st.textContent = d.msg;
        if (d.msg && d.msg.indexOf('OK') === 0) setTimeout(function() { location.reload(); }, 1200);
        else if (btn) btn.disabled = false;
    });
}
function clearDiscovered() {
    if (!confirm('Alle Discovery-Einträge löschen?')) return;
    fetch('mqtt_admin.php?action=clearDiscovered').then(function() { location.reload(); });
}

var SCAN_DEVICES = <?= json_encode($devicesJson) ?>;

// ── Topic-Scanner ─────────────────────────────────────────────────────────
var scanController = null;
var scanTimer      = null;

function startScan() {
    scanController = new AbortController();
    var remaining  = 30;
    document.getElementById('scanBtn').disabled = true;
    document.getElementById('scanCancelBtn').style.display = '';
    document.getElementById('scanResults').innerHTML = '';
    document.getElementById('scanCount').textContent = '';
    document.getElementById('scanCountdown').textContent = 'läuft... ' + remaining + 's';

    scanTimer = setInterval(function() {
        remaining = Math.max(0, remaining - 1);
        document.getElementById('scanCountdown').textContent = 'läuft... ' + remaining + 's';
    }, 1000);

    fetch('mqtt_admin.php?action=startScan&duration=30', { signal: scanController.signal })
        .then(function(r){ return r.json(); })
        .then(function(d){
            clearInterval(scanTimer);
            document.getElementById('scanBtn').disabled = false;
            document.getElementById('scanCancelBtn').style.display = 'none';
            if (d.ok) {
                document.getElementById('scanCountdown').textContent = d.cancelled ? 'abgebrochen' : 'fertig';
                document.getElementById('scanCount').textContent = d.count + ' Topics';
                loadScanResults();
            } else {
                document.getElementById('scanCountdown').textContent = 'Fehler: ' + (d.msg || '');
            }
        })
        .catch(function() {
            clearInterval(scanTimer);
            document.getElementById('scanBtn').disabled = false;
            document.getElementById('scanCancelBtn').style.display = 'none';
            document.getElementById('scanCountdown').textContent = 'abgebrochen';
            setTimeout(loadScanResults, 500);
        });
}

function cancelScan() {
    fetch('mqtt_admin.php?action=cancelScan');
    if (scanController) { scanController.abort(); scanController = null; }
    if (scanTimer) { clearInterval(scanTimer); scanTimer = null; }
}

function loadScanResults() {
    fetch('mqtt_admin.php?action=getScanResults')
        .then(function(r){ return r.json(); })
        .then(function(topics){
            if (!topics.length) {
                document.getElementById('scanResults').innerHTML = '<i style="color:#888">Keine Topics empfangen.</i>';
                return;
            }
            // Gruppieren nach erstem Pfad-Segment
            var groups = {};
            topics.forEach(function(t){
                var seg = t.topic.split('/')[0];
                if (!groups[seg]) groups[seg] = [];
                groups[seg].push(t);
            });
            var html = '<table style="width:100%;font-size:11px;border-collapse:collapse">';
            html += '<tr style="background:#e8edf8"><th style="padding:3px 6px;text-align:left">Topic</th>';
            html += '<th style="padding:3px 6px;text-align:left">Payload</th>';
            html += '<th style="padding:3px 6px;min-width:60px"></th></tr>';
            Object.keys(groups).sort().forEach(function(seg){
                html += '<tr><td colspan="3" style="padding:4px 6px 2px;font-weight:bold;background:#dde3f5;color:#333">' + esc(seg) + '/</td></tr>';
                groups[seg].forEach(function(t){
                    var rowId = 'scan_row_' + t.topic.replace(/[^a-z0-9]/gi,'_');
                    html += '<tr id="' + rowId + '">';
                    html += '<td style="padding:2px 6px 2px 14px;font-family:monospace">' + esc(t.topic) + '</td>';
                    html += '<td style="padding:2px 6px;color:#555;max-width:220px;overflow:hidden;white-space:nowrap">' + esc(t.payload) + '</td>';
                    html += '<td style="padding:2px 4px;white-space:nowrap">';
                    html += '<button class="btn-import" onclick="showScanForm(\'' + rowId + '\')">&#43; Channel</button>';
                    html += '</td></tr>';
                    html += '<tr id="' + rowId + '_form" style="display:none"><td colspan="3" style="padding:4px 14px 8px;background:#f8f9ff">';
                    html += buildScanForm(t, rowId);
                    html += '</td></tr>';
                });
            });
            html += '</table>';
            document.getElementById('scanResults').innerHTML = html;
        });
}

// ── Wert-Mapping Editor ──────────────────────────────────────────────────────
var _mapChID = 0;

function openMapEditor(el) {
    _mapChID = parseInt(el.dataset.chid);
    document.getElementById('mapChName').textContent = el.dataset.name;
    document.getElementById('mapSaveMsg').textContent = '';
    fillMapTable('mapInBody',  el.dataset.mapin  ? JSON.parse(el.dataset.mapin)  : {});
    fillMapTable('mapOutBody', el.dataset.mapout ? JSON.parse(el.dataset.mapout) : {});
    document.getElementById('mapOverlay').classList.add('open');
}

function fillMapTable(bodyId, obj) {
    document.getElementById(bodyId).innerHTML = '';
    Object.keys(obj).forEach(function(k) { addMapRow(bodyId, k, obj[k]); });
}

function addMapRow(bodyId, key, val) {
    var tbody = document.getElementById(bodyId);
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" class="map-key" value="' + esc(key||'') + '"></td>'
                 + '<td class="map-arrow">&#x2192;</td>'
                 + '<td><input type="text" class="map-val" value="' + esc(val!==undefined?String(val):'') + '"></td>'
                 + '<td class="map-del" onclick="this.closest(\'tr\').remove()">&#x2715;</td>';
    tbody.appendChild(tr);
}

function closeMapEditor() {
    document.getElementById('mapOverlay').classList.remove('open');
}

function collectMap(bodyId) {
    var obj = {};
    document.querySelectorAll('#' + bodyId + ' tr').forEach(function(tr) {
        var k = tr.querySelector('.map-key').value.trim();
        var v = tr.querySelector('.map-val').value.trim();
        if (k !== '') obj[k] = v;
    });
    return obj;
}

function saveMapEditor() {
    var mapIn  = collectMap('mapInBody');
    var mapOut = collectMap('mapOutBody');
    var msg = document.getElementById('mapSaveMsg');
    msg.style.color = '#080';
    msg.textContent = '';
    fetch('mqtt_admin.php?action=saveValueMap', {
        method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'chID=' + _mapChID
            + '&mapIn='  + encodeURIComponent(JSON.stringify(mapIn))
            + '&mapOut=' + encodeURIComponent(JSON.stringify(mapOut))
    }).then(function(r){return r.json();}).then(function(d){
        if (d.ok) {
            msg.textContent = '✔ gespeichert';
            var btn = document.querySelector('.btn-map[data-chid="' + _mapChID + '"]');
            if (btn) {
                btn.dataset.mapin  = Object.keys(mapIn).length  ? JSON.stringify(mapIn)  : '';
                btn.dataset.mapout = Object.keys(mapOut).length ? JSON.stringify(mapOut) : '';
            }
            setTimeout(closeMapEditor, 900);
        } else {
            msg.style.color = '#c00';
            msg.textContent = d.msg || 'Fehler';
        }
    });
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildScanForm(t, rowId) {
    var devPrefix = t.topic.split('/')[0];
    var matchedId = 0;
    for (var i = 0; i < SCAN_DEVICES.length; i++) {
        if (SCAN_DEVICES[i].name.toLowerCase() === devPrefix.toLowerCase()) {
            matchedId = SCAN_DEVICES[i].id;
            break;
        }
    }
    var isNew = matchedId === 0;

    var devSel = '<select id="' + rowId + '_dev" style="max-width:150px">'
        + '<option value="0">' + (isNew ? esc(devPrefix) + ' (N)' : '-- Gerät wählen --') + '</option>';
    SCAN_DEVICES.forEach(function(d) {
        devSel += '<option value="' + d.id + '"' + (d.id === matchedId ? ' selected' : '') + '>' + esc(d.name) + '</option>';
    });
    devSel += '</select> ';
    devSel += '<input id="' + rowId + '_newdev" value="' + (isNew ? esc(devPrefix) : '') + '" placeholder="Neuer Gerätename" style="width:110px;' + (isNew ? '' : 'display:none') + '">';

    var parts   = t.topic.split('/').filter(Boolean);
    var chName  = parts.length > 1 ? parts.slice(1).join('/') : parts[0];
    var dtype   = isNaN(parseFloat(t.payload)) ? 'string' : (t.payload.indexOf('.') >= 0 ? 'float' : 'int');

    var jpTip = esc('Nur bei JSON-Payload: welches Feld extrahiert werden soll. Leer lassen wenn der Payload selbst der Wert ist (z.B. 29 oder pv).');
    var jpSel = '';
    if (t.isJson && t.keys.length) {
        jpSel = ' <select id="' + rowId + '_jp" style="max-width:130px" title="' + jpTip + '"><option value="">-- ganzer Payload --</option>';
        t.keys.forEach(function(k){ jpSel += '<option value="' + esc(k) + '">' + esc(k) + '</option>'; });
        jpSel += '</select>';
    } else {
        jpSel = '<input id="' + rowId + '_jp" placeholder="JSON-Feld (opt.)" style="width:110px" title="' + jpTip + '">';
    }

    return '<b>Channel:</b> '
        + devSel
        + ' <input id="' + rowId + '_name" value="' + esc(chName) + '" placeholder="Name" style="width:100px">'
        + ' <select id="' + rowId + '_dtype"><option value="float"' + (dtype==='float'?' selected':'') + '>float</option><option value="int"' + (dtype==='int'?' selected':'') + '>int</option><option value="string"' + (dtype==='string'?' selected':'') + '>string</option></select>'
        + jpSel
        + ' <button class="btn-import" onclick="submitScanChannel(\'' + rowId + '\',\'' + esc(t.topic) + '\')">Speichern</button>'
        + ' <button class="btn-import" onclick="hideScanForm(\'' + rowId + '\')">Abbrechen</button>'
        + ' <span id="' + rowId + '_msg" style="font-size:11px;margin-left:4px"></span>';
}

function showScanForm(rowId) {
    document.getElementById(rowId + '_form').style.display = '';
    var devSel = document.getElementById(rowId + '_dev');
    if (devSel) {
        devSel.onchange = function() {
            var nd = document.getElementById(rowId + '_newdev');
            if (nd) nd.style.display = this.value === '0' ? '' : 'none';
        };
    }
}

function hideScanForm(rowId) {
    document.getElementById(rowId + '_form').style.display = 'none';
}

function submitScanChannel(rowId, topic) {
    var devID    = document.getElementById(rowId + '_dev').value;
    var newDev   = (document.getElementById(rowId + '_newdev') || {value:''}).value;
    var name     = document.getElementById(rowId + '_name').value;
    var dtype    = document.getElementById(rowId + '_dtype').value;
    var jpEl     = document.getElementById(rowId + '_jp');
    var jsonPath = jpEl ? jpEl.value : '';
    var msg      = document.getElementById(rowId + '_msg');
    msg.textContent = '...';
    fetch('mqtt_admin.php?action=createChannelFromScan', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'deviceID=' + encodeURIComponent(devID)
            + '&newDevice=' + encodeURIComponent(newDev)
            + '&topic='     + encodeURIComponent(topic)
            + '&name='      + encodeURIComponent(name)
            + '&dtype='     + encodeURIComponent(dtype)
            + '&jsonPath='  + encodeURIComponent(jsonPath)
    }).then(function(r){ return r.json(); })
      .then(function(d){
          if (d.ok) {
              // SCAN_DEVICES aktualisieren damit weitere Channels das neue Gerät finden
              if (d.deviceID && d.deviceName) {
                  var exists = SCAN_DEVICES.some(function(dv){ return dv.id === d.deviceID; });
                  if (!exists) SCAN_DEVICES.push({id: d.deviceID, name: d.deviceName});
              }
              msg.style.color = '#080';
              msg.innerHTML = '&#x2714; Channel angelegt';
              setTimeout(function(){ hideScanForm(rowId); }, 1500);
          } else {
              msg.style.color = '#c00';
              msg.textContent = d.msg || 'Fehler';
          }
      });
}
</script>
</head>
<body>
<!-- ── Wert-Mapping Modal ───────────────────────────────────────────────────── -->
<div id="mapOverlay" onclick="if(event.target===this)closeMapEditor()">
  <div id="mapModal">
    <h3>Wert-Mapping &mdash; <span id="mapChName"></span></h3>
    <div class="map-section">
      <b>MQTT &rarr; KO</b> <span class="map-hint">(valueMapIn: empfangener MQTT-Wert wird vor KO-Schreiben ersetzt)</span>
      <table class="map-table">
        <thead><tr><th>MQTT-Wert</th><th></th><th>KO-Wert</th><th></th></tr></thead>
        <tbody id="mapInBody"></tbody>
      </table>
      <button class="btn-import" style="margin-top:5px;font-size:11px" onclick="addMapRow('mapInBody','','')">+ Eintrag</button>
    </div>
    <div class="map-section">
      <b>KO &rarr; MQTT</b> <span class="map-hint">(valueMapOut: KO-Wert wird vor dem Senden ersetzt)</span>
      <table class="map-table">
        <thead><tr><th>KO-Wert</th><th></th><th>MQTT-Wert</th><th></th></tr></thead>
        <tbody id="mapOutBody"></tbody>
      </table>
      <button class="btn-import" style="margin-top:5px;font-size:11px" onclick="addMapRow('mapOutBody','','')">+ Eintrag</button>
    </div>
    <div style="text-align:right;margin-top:10px;display:flex;gap:6px;justify-content:flex-end;align-items:center">
      <span id="mapSaveMsg" style="font-size:11px;color:#080;flex:1;text-align:left"></span>
      <button class="btn-import" onclick="saveMapEditor()">Speichern</button>
      <button class="btn-import" onclick="closeMapEditor()">Abbrechen</button>
    </div>
  </div>
</div>
<div class="page-wrap">
<h1>MQTT Connector &mdash; Admin</h1>
<p class="msg-warn">Nach &Auml;nderungen an Ger&auml;ten oder KO-Zuordnungen LBS neu starten (E1&nbsp;=&nbsp;0, dann E1&nbsp;=&nbsp;1).</p>
<?php
$_brokerRow = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT host, port FROM edomiProject.mqttBroker LIMIT 1") ?: false) ?: [];
$_brokerHost = !empty($_brokerRow['host']) ? $_brokerRow['host'] : 'localhost';
$_brokerPort = !empty($_brokerRow['port']) ? (int)$_brokerRow['port'] : 1883;
if (!checkBroker($_brokerHost, $_brokerPort)): ?>
<p class="msg-err">&#9888; MQTT-Broker nicht erreichbar (<?= htmlspecialchars($_brokerHost) ?>:<?= $_brokerPort ?>).<br>
<?php if ($_brokerHost === 'localhost' || $_brokerHost === '127.0.0.1'): ?>
<code>apt install mosquitto &amp;&amp; systemctl start mosquitto</code><br>
In <code>/etc/mosquitto/conf.d/local.conf</code>: <code>listener 1883</code> / <code>allow_anonymous true</code>
<?php else: ?>
Broker-Host pr&uuml;fen: Ist Mosquitto auf <b><?= htmlspecialchars($_brokerHost) ?></b> gestartet und Port <?= $_brokerPort ?> erreichbar?
<?php endif; ?>
</p>
<?php endif; ?>

<!-- Navigation -->
<div class="nav-tabs">
  <a href="mqtt_admin.php" class="nav-tab <?= ($view === 'devices' || $view === 'channels') ? 'active' : '' ?>">Ger&auml;te</a>
  <a href="mqtt_admin.php?view=discovery" class="nav-tab <?= $view === 'discovery' ? 'active' : '' ?>">
    Discovery<?php
    $dc = (int)(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM edomiProject.mqttDiscovered"))[0] ?? 0);
    if ($dc > 0) echo ' <span style="font-size:10px;color:#2060b0">(' . $dc . ')</span>';
    ?>
  </a>
</div>

<?php if ($msg): ?>
<p class="msg-info"><?= h($msg) ?></p>
<?php endif; ?>

<?php if ($view === 'devices'): ?>

<!-- ── Geräteliste ──────────────────────────────────────────────────────── -->
<?php $devList = getDevices($mysqli); ?>
<h2>Ger&auml;te</h2>
<table>
<thead><tr>
  <th>Ger&auml;tename</th><th>Channels</th><th>KOs zugewiesen</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($devList as $dev): ?>
<tr>
  <td><a href="mqtt_admin.php?view=channels&amp;devID=<?= $dev['id'] ?>"><?= h($dev['deviceName']) ?></a></td>
  <td><?= (int)$dev['chTotal'] ?></td>
  <td><?= (int)$dev['chMapped'] ?></td>
  <td style="white-space:nowrap">
    <a href="mqtt_admin.php?view=channels&amp;devID=<?= $dev['id'] ?>" class="cmdButton">Channels</a>
    &nbsp;<button class="cmdButton" onclick="renameDevice(<?= $dev['id'] ?>, '<?= h(addslashes($dev['deviceName'])) ?>')">Umbenennen</button>
    &nbsp;<button class="btn-del" onclick="delDevice(<?= $dev['id'] ?>, '<?= h(addslashes($dev['deviceName'])) ?>')">L&ouml;schen</button>
  </td>
</tr>
<?php endforeach; ?>
<?php if (empty($devList)): ?>
<tr><td colspan="4" style="color:#888;font-style:italic;">Keine Ger&auml;te — JSON importieren oder Discovery nutzen.</td></tr>
<?php endif; ?>
</tbody>
</table>

<!-- ── JSON-Import ──────────────────────────────────────────────────────── -->
<h2>JSON-Import</h2>
<div style="display:flex;align-items:center;gap:8px;flex-wrap:nowrap">
  <input type="file" id="jsonFile" accept=".json" style="font-size:12px">
  <select id="importTarget" style="font-size:12px">
    <option value="0">&mdash; Neues Ger&auml;t &mdash;</option>
    <?php foreach ($devList as $dev): ?>
    <option value="<?= $dev['id'] ?>"><?= h($dev['deviceName']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="cmdButton" onclick="doImport()">Importieren</button>
  <span id="importResult" style="color:#008000;font-size:11px"></span>
  <span style="color:#888;font-size:11px" title="Channels werden anhand des Namens abgeglichen. KO-Zuordnungen, Topics und manuell hinzugef&uuml;gte Channels bleiben erhalten. Neue Channels aus der JSON erhalten die Platzhalter-Topics und m&uuml;ssen danach per &quot;Topic-Prefix ersetzen&quot; angepasst werden.">&#9432; KO-Zuordnungen bleiben bei Updates erhalten</span>
</div>

<?php elseif ($view === 'channels' && $devID > 0): ?>

<?php
$devRes  = mysqli_query($mysqli, "SELECT deviceName FROM edomiProject.mqttDevice WHERE id=$devID LIMIT 1");
$devRow  = mysqli_fetch_assoc($devRes);
$devName = $devRow ? $devRow['deviceName'] : '?';
$chList  = getChannels($mysqli, $devID);
?>

<!-- ── Channel-Ansicht ──────────────────────────────────────────────────── -->
<div class="toolbar">
  <a href="mqtt_admin.php" class="cmdButton">&larr; Ger&auml;teliste</a>
  <span style="font-weight:bold;font-size:13px"><?= h($devName) ?></span>
  <span style="margin-left:12px;color:#666" title="Ersetzt einen Teil-String in allen Subscribe- und Publish-Topics dieses Ger&auml;ts. N&uuml;tzlich wenn der MQTT-Topic-Prefix nicht mit dem importierten JSON &uuml;bereinstimmt, z.B. wled/wohnzimmer &rarr; wled/aussenwand.">Topic-Prefix ersetzen &#9432;</span>
  <input id="pfxSearch"  placeholder="suchen"   style="width:130px;font-size:12px" title="Dieser Text wird in allen Topics gesucht">
  <span style="color:#666">&rarr;</span>
  <input id="pfxReplace" placeholder="ersetzen" style="width:130px;font-size:12px" title="Ersatztext (leer = l&ouml;schen)">
  <button class="cmdButton" onclick="replacePrefix(<?= $devID ?>)" title="Suchen &amp; Ersetzen in allen Topics dieses Ger&auml;ts ausf&uuml;hren">Ersetzen</button>
  <span style="margin-left:auto;display:flex;gap:6px">
    <button id="koBatchSaveBtn" class="btn-import" onclick="saveKOBatch()" disabled title="KO-Zuordnungen in Datenbank speichern">Speichern</button>
    <button id="koBatchDiscardBtn" class="btn-import" onclick="discardKO()" disabled title="&Auml;nderungen verwerfen">Verwerfen</button>
  </span>
</div>

<div id="testToast"></div>
<table>
<thead><tr>
  <th>Name</th><th style="min-width:90px">Richtung</th><th>Typ</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($chList as $ch): ?>
<?php
$cid      = (int)$ch['id'];
$dir      = $ch['direction'];
$dirLabel = $dirLabels[$dir] ?? $dirLabels['both'];
$koIDsub  = (int)($ch['koIDsub'] ?? 0);
$koIDpub  = (int)($ch['koIDpub'] ?? 0);
$hasSub   = ($dir === 'both' || $dir === 'subscribe') && $ch['subscribeTopic'] !== '';
$hasPub   = ($dir === 'both' || $dir === 'publish')   && $ch['publishTopic']   !== '';
$hasDetail = $ch['valueTemplate'] || $ch['commandTemplate'] || $ch['valueMapIn'] || $ch['unit'];
?>
<!-- Hauptzeile -->
<tr>
  <td><b><?= h($ch['name']) ?></b></td>
  <td><?= $dirLabel ?></td>
  <td><?= h($ch['dataType']) ?><?= $ch['unit'] ? ' <span style="color:#808080">['.h($ch['unit']).']</span>' : '' ?></td>
  <td>
    <span class="btn-map"
          data-chid="<?= $cid ?>"
          data-name="<?= h($ch['name']) ?>"
          data-mapin="<?= h($ch['valueMapIn'] ?? '') ?>"
          data-mapout="<?= h($ch['valueMapOut'] ?? '') ?>"
          onclick="openMapEditor(this)"
          title="Wert-Mapping bearbeiten">&#x21C4;</span>
    <span class="btn-del" onclick="delChannel(<?= $cid ?>, '<?= h(addslashes($ch['name'])) ?>')" title="Channel löschen">&#x2715;</span>
  </td>
</tr>
<?php if ($ch['note'] !== ''): ?>
<tr class="tr-detail">
  <td colspan="4" style="font-size:10px;color:#808080;font-style:italic;padding-left:8px"><?= h($ch['note']) ?></td>
</tr>
<?php endif ?>
<?php if ($hasSub): ?>
<!-- Subscribe-Zeile -->
<tr class="tr-sub">
  <td class="ind-sub" title="empfangen (MQTT → KO)">RD</td>
  <td><span class="topic-mono" title="<?= h($ch['subscribeTopic']) ?>"><?= h($ch['subscribeTopic']) ?></span></td>
  <td colspan="2">
    <div class="ko-cell" onclick="mqttPickerOpenSub(<?= $cid ?>, <?= $koIDsub ?>)">
      <span id="ko_sub_disp_<?= $cid ?>" class="<?= $koIDsub > 0 ? 'ko-cell-name' : 'ko-cell-empty' ?>">
        <?= $koIDsub > 0 ? h($ch['koNameSub']) . ' [' . $koIDsub . ']' : 'KO auswählen' ?>
      </span>
    </div>
  </td>
</tr>
<?php endif; ?>
<?php if ($hasPub): ?>
<!-- Publish-Zeile -->
<tr class="tr-pub">
  <td class="ind-pub" title="senden (KO → MQTT)">WR</td>
  <td><span class="topic-mono" title="<?= h($ch['publishTopic']) ?>"><?= h($ch['publishTopic']) ?></span></td>
  <td>
    <div class="ko-cell" onclick="mqttPickerOpenPub(<?= $cid ?>, <?= $koIDpub ?>)">
      <span id="ko_pub_disp_<?= $cid ?>" class="<?= $koIDpub > 0 ? 'ko-cell-name' : 'ko-cell-empty' ?>">
        <?= $koIDpub > 0 ? h($ch['koNamePub']) . ' [' . $koIDpub . ']' : 'KO auswählen' ?>
      </span>
    </div>
  </td>
  <td style="white-space:nowrap">
    <input id="tv_<?= $cid ?>" class="test-input" type="text" placeholder="Testwert">
    <span class="btn-test" onclick="doTestPublish(<?= $cid ?>, 'tv_<?= $cid ?>')">Senden</span>
  </td>
</tr>
<?php endif; ?>
<?php if ($hasDetail): ?>
<!-- Template/Mapping Detail -->
<tr class="tr-detail">
  <td></td>
  <td colspan="2" style="font-size:10px">
    <?php if ($ch['valueTemplate']):   ?>rxTpl: <b><?= h($ch['valueTemplate'])   ?></b>&nbsp; <?php endif ?>
    <?php if ($ch['commandTemplate']): ?>txTpl: <b><?= h($ch['commandTemplate']) ?></b>&nbsp; <?php endif ?>
    <?php if ($ch['valueMapIn']): $mi = json_decode($ch['valueMapIn'],true)??[]; ?>mapIn: <b><?= count($mi) ?> Eintr.</b> <span style="color:#808080">(<?= implode(', ', array_map(function($k,$v){return h($k).' → '.h($v);}, array_keys($mi), $mi)) ?>)</span>&nbsp;<?php endif ?>
    <?php if ($ch['valueMapOut']): $mo = json_decode($ch['valueMapOut'],true)??[]; ?>mapOut: <b><?= count($mo) ?> Eintr.</b> <span style="color:#808080">(<?= implode(', ', array_map(function($k,$v){return h($k).' → '.h($v);}, array_keys($mo), $mo)) ?>)</span><?php endif ?>
  </td>
  <td></td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
<?php if (empty($chList)): ?>
<tr><td colspan="4" style="color:#888;font-style:italic;">Keine Channels — JSON für dieses Gerät importieren.</td></tr>
<?php endif; ?>
</tbody>
</table>

<?php elseif ($view === 'discovery'): ?>

<?php $discovered = getDiscovered($mysqli); ?>

<!-- ── Discovery-Ansicht ─────────────────────────────────────────────────── -->
<div class="toolbar">
  <span style="font-weight:bold;font-size:13px">MQTT Discovery</span>
  <button class="cmdButton" onclick="location.reload()">Aktualisieren</button>
  <?php if (!empty($discovered)): ?>
  <button class="btn-del" onclick="clearDiscovered()">Alle l&ouml;schen</button>
  <?php endif; ?>
  <span style="color:#666;font-size:11px;margin-left:4px;">
    Ger&auml;te werden automatisch erkannt (homeassistant/# + tasmota/discovery/#). LBS muss laufen (E1=1).
  </span>
</div>

<!-- ── Topic-Scanner ──────────────────────────────────────────────────────── -->
<div style="background:#f0f4ff;border:1px solid #c5d0e8;border-radius:4px;padding:10px 14px;margin-bottom:12px;">
  <b style="font-size:12px">Topic-Scanner</b>
  <span style="font-size:11px;color:#555;margin-left:6px;">Alle MQTT-Topics auf dem Broker erfassen (wie MQTT-Explorer)</span><br>
  <div style="margin-top:8px;">
    <button class="cmdButton" id="scanBtn" onclick="startScan()">Scan starten (30s)</button>
    <button class="cmdButton" id="scanCancelBtn" onclick="cancelScan()" style="display:none">Abbrechen</button>
    <span id="scanCountdown" style="font-size:12px;color:#333;margin-left:8px;"></span>
    <span id="scanCount"     style="font-size:12px;color:#666;margin-left:8px;"></span>
  </div>
  <div id="scanResults" style="margin-top:10px;"></div>
</div>
<?php $devices = getDevices($mysqli); ?>

<?php if (empty($discovered)): ?>
<p style="color:#888;font-style:italic;margin-top:12px;">
  Keine Discovery-Daten vorhanden. LBS starten und kurz warten.<br>
  <b>Zigbee2MQTT / Shelly / ESPHome:</b> publishen auf <code>homeassistant/#</code>.<br>
  <b>Tasmota:</b> publisht auf <code>tasmota/discovery/#</code> (kein SetOption19 n&ouml;tig).
</p>
<?php else: ?>
<table>
<thead><tr>
  <th>Name</th><th>Typ</th><th>Ger&auml;t</th><th>State Topic</th><th>Value Template</th><th>Einheit</th><th>Gesehen</th><th></th>
</tr></thead>
<tbody>
<?php
// Pre-compute sensor counts: config-dtopic → count of numeric sensor fields
$sensorCountByConfig = [];
foreach ($discovered as $d) {
    if ($d['component'] !== 'tasmota_sensors') continue;
    $configKey = preg_replace('#/sensors$#', '/config', $d['discoveryTopic']);
    $sn = $d['pl']['sn'] ?? [];
    $cnt = 0;
    foreach ($sn as $k => $v) {
        if (!is_array($v)) continue;
        foreach ($v as $f => $val) {
            if ($f !== 'Id' && $f !== 'TotalStartTime' && is_numeric($val)) $cnt++;
        }
    }
    $sensorCountByConfig[$configKey] = $cnt;
}
?>
<?php foreach ($discovered as $i => $d): ?>
<?php
$pl       = $d['pl'];
$comp     = $d['component'];
$imported = $d['channelID'] !== null;

if ($comp === 'tasmota_config') {
    $fn      = $pl['fn'] ?? [];
    $entName = ($pl['dn'] ?? null) ?: ($fn[0] ?? '');
    $devN    = $pl['md'] ?? '';
    $stateTp = 'IP: ' . ($pl['ip'] ?? '') . '  Topic: ' . ($pl['t'] ?? '');
    $valTpl  = 'FW ' . ($pl['sw'] ?? '');
    $unit    = '';
    $relays  = array_sum(array_map('intval', $pl['rl'] ?? []));
    $sensors = $sensorCountByConfig[$d['discoveryTopic']] ?? 0;
    $btnParts = [];
    if ($relays > 0)  $btnParts[] = "$relays Relais";
    if ($sensors > 0) $btnParts[] = "$sensors Sensoren";
    $btnLabel  = 'Channels generieren (' . ($btnParts ? implode(', ', $btnParts) : 'IR/keine') . ')';
    $btnAction = "importTasmota($i, " . h(json_encode($d['discoveryTopic'])) . ")";
} elseif ($comp === 'tasmota_sensors') {
    $sn = $pl['sn'] ?? [];
    $sensorTypes = array_keys(array_filter($sn, 'is_array'));
    $entName  = 'Sensoren: ' . implode(', ', $sensorTypes);
    $devN = $stateTp = $valTpl = $unit = '';
    $btnLabel = null; $btnAction = '';
} else {
    $entName  = $pl['name'] ?? '';
    $devN     = isset($pl['device']['name']) ? $pl['device']['name'] : '';
    $stateTp  = $pl['state_topic'] ?? '';
    $valTpl   = $pl['value_template'] ?? '';
    $unit     = $pl['unit_of_measurement'] ?? '';
    $btnLabel  = 'Importieren';
    $btnAction = "importDiscovery($i, " . h(json_encode($d['discoveryTopic'])) . ")";
}
?>
<tr>
  <td><?= h($entName) ?></td>
  <td><span class="comp-badge"><?= h($comp) ?></span></td>
  <td style="color:#555"><?= h($devN) ?></td>
  <td class="topic-cell" title="<?= h($stateTp) ?>"><?= h($stateTp) ?></td>
  <td class="tpl-cell"   title="<?= h($valTpl) ?>"><?= h($valTpl) ?></td>
  <td><?= h($unit) ?></td>
  <td style="white-space:nowrap;color:#666;font-size:10px"><?= h(substr($d['seen_at'],0,16)) ?></td>
  <td style="white-space:nowrap">
    <?php if ($imported): ?>
      <span class="disc-imported">&#x2714; importiert</span>
    <?php elseif ($btnLabel !== null): ?>
      <button id="disc_btn_<?= $i ?>" class="btn-import" onclick="<?= $btnAction ?>"><?= h($btnLabel) ?></button>
    <?php endif; ?>
    <span id="disc_st_<?= $i ?>" class="disc-status"></span>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?php endif; ?>

<?php
// Scan-Ergebnisse beim Seitenload wiederherstellen
if ($view === 'discovery') {
    $scanCnt = (int)(mysqli_fetch_row(mysqli_query($mysqli,
        "SELECT COUNT(*) FROM edomiProject.mqttTopicScan"))[0] ?? 0);
    if ($scanCnt > 0): ?>
<script>
document.getElementById('scanCount').textContent = '<?= $scanCnt ?> Topics (letzter Scan)';
loadScanResults();
</script>
    <?php endif;
} ?>

</div>
</body>
</html>

<?php

declare(strict_types=1);

/**
 * Minimal MQTT 3.1.1 client over plain TCP (no TLS).
 * PHP 7.2+ compatible, no external dependencies.
 *
 * Supports: CONNECT (plain + user/pass), PUBLISH (QoS 0), SUBSCRIBE (QoS 0/1), PING, DISCONNECT.
 * Incoming PUBLISH packets (QoS 0 and 1) are handled; PUBACK is sent for QoS 1.
 *
 * Forked from Lepro MqttClient (TLS mutual auth) — adapted for plain TCP + username/password auth.
 */
class MqttClient
{
    /** @var resource|null */
    private $socket = null;

    /** Gesicherter Error-Handler vor connect() — wird in disconnect() wiederhergestellt */
    private $prevErrorHandler = false;

    /** PUBLISH-Pakete die während subscribe() ankamen und noch nicht verarbeitet wurden */
    /* private array */ private $pendingMessages = [];

    /* private string */ private $host;
    /* private int    */ private $port;
    /* private string */ private $clientId;
    /* private string */ private $username;
    /* private string */ private $password;
    /* private int    */ private $keepAlive;
    /* private float  */ private $lastActivity;

    public function __construct(
        string $host,
        int    $port,
        string $clientId,
        string $username  = '',
        string $password  = '',
        int    $keepAlive = 60
    ) {
        $this->host        = $host;
        $this->port        = $port;
        $this->clientId    = $clientId;
        $this->username    = $username;
        $this->password    = $password;
        $this->keepAlive   = $keepAlive;
        $this->lastActivity = microtime(true);
    }

    // ── Public ────────────────────────────────────────────────────────────────

    public function connect(): void
    {
        // Error-Handler für gesamte Socket-Lebensdauer deaktivieren.
        // Edomi's eigener Handler ruft die() bei Warnings → würde LBS killen
        // wenn Mosquitto stoppt und fread/fwrite fehlschlagen.
        $this->prevErrorHandler = set_error_handler(null);

        $this->socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno, $errstr, 8,
            STREAM_CLIENT_CONNECT
        );

        if ($this->socket === false) {
            $this->socket = null;
            $this->restoreErrorHandler();
            throw new \RuntimeException("MQTT TCP connect failed: $errstr ($errno)");
        }

        // CONNECT-Paket im blocking-Modus senden — non-blocking fwrite() kann 0
        // zurückgeben (EAGAIN) und würde sofort eine Exception werfen.
        stream_set_blocking($this->socket, true);
        $this->sendConnect();
        stream_set_blocking($this->socket, false);

        $connack = $this->readPacket(10.0);

        if ($connack === null || $connack['type'] !== 0x20) {
            $got = $connack ? sprintf('0x%02X', $connack['type']) : 'nothing';
            throw new \RuntimeException("CONNACK not received (got $got)");
        }

        $rc = strlen($connack['data']) >= 2 ? ord($connack['data'][1]) : -1;
        if ($rc !== 0) {
            $msgs = [
                1 => 'Unacceptable protocol version',
                2 => 'Identifier rejected',
                3 => 'Server unavailable',
                4 => 'Bad username or password',
                5 => 'Not authorized',
            ];
            throw new \RuntimeException('MQTT refused: ' . ($msgs[$rc] ?? "code $rc"));
        }

        $this->lastActivity = microtime(true);
    }

    /**
     * Subscribe to one or more topics (QoS 0).
     *
     * @param string[] $topics
     */
    public function subscribe(array $topics): void
    {
        $this->assertConnected();
        if (empty($topics)) return;

        $topicPart = '';
        foreach ($topics as $t) {
            $topicPart .= $this->encodeString($t) . "\x00"; // QoS 0
        }
        $payload = "\x00\x01" . $topicPart; // packet ID 1
        $this->writePacket(0x82, $payload);

        // Read SUBACK; incoming PUBLISH packets werden gepuffert statt verworfen
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $pkt = $this->readPacket(1.0);
            if ($pkt === null) continue;
            if ($pkt['type'] === 0x90) break; // SUBACK
            if (($pkt['type'] & 0xF0) === 0x30) {
                $msg = $this->parsePublish($pkt);
                if ($msg !== null) {
                    $this->pendingMessages[] = $msg;
                }
            }
        }
    }

    /**
     * Publish a message (QoS 0, no retain).
     */
    public function publish(string $topic, string $payload): void
    {
        $this->assertConnected();
        $data = $this->encodeString($topic) . $payload;
        $this->writePacket(0x30, $data);
        $this->lastActivity = microtime(true);
    }

    /**
     * Run the receive loop for $timeout seconds.
     *
     * The callback receives (topic, payload) for each incoming PUBLISH.
     * If the callback returns true the loop exits early.
     *
     * @param callable $callback fn(string $topic, string $payload): bool|void
     * @param float    $timeout  Maximum seconds to run
     */
    public function loop(callable $callback, float $timeout = 1.0): void
    {
        $this->assertConnected();

        // Gepufferte Pakete aus subscribe()-Wartezeiten zuerst verarbeiten
        while ($this->pendingMessages) {
            $msg  = array_shift($this->pendingMessages);
            $stop = $callback($msg['topic'], $msg['payload']);
            if ($stop === true) return;
        }

        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (microtime(true) - $this->lastActivity > $this->keepAlive / 2) {
                $this->writePacket(0xC0, '');
                $this->lastActivity = microtime(true);
            }

            $remaining = $deadline - microtime(true);
            $pkt = $this->readPacket(min(0.25, $remaining));
            if ($pkt === null) continue;

            $typeHigh = $pkt['type'] & 0xF0;

            if ($typeHigh === 0x30) { // PUBLISH (any QoS/flags)
                $msg = $this->parsePublish($pkt);
                if ($msg !== null) {
                    $stop = $callback($msg['topic'], $msg['payload']);
                    if ($stop === true) break;
                }
            } elseif ($pkt['type'] === 0xD0) { // PINGRESP
                $this->lastActivity = microtime(true);
            }
        }
    }

    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @$this->writePacket(0xE0, '');
            fclose($this->socket);
            $this->socket = null;
        }
        $this->restoreErrorHandler();
    }

    private function restoreErrorHandler(): void
    {
        if ($this->prevErrorHandler !== false) {
            set_error_handler($this->prevErrorHandler);
            $this->prevErrorHandler = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    // ── MQTT packet builders ──────────────────────────────────────────────────

    private function sendConnect(): void
    {
        // Connect flags: clean session = 0x02; add user/pass flags if set
        $connectFlags = 0x02;
        $extra = '';

        if ($this->username !== '') {
            $connectFlags |= 0x80; // username flag
            $extra .= $this->encodeString($this->username);
            if ($this->password !== '') {
                $connectFlags |= 0x40; // password flag
                $extra .= $this->encodeString($this->password);
            }
        }

        $payload =
            $this->encodeString('MQTT')        // protocol name
            . "\x04"                            // protocol level 3.1.1
            . chr($connectFlags)
            . pack('n', $this->keepAlive)
            . $this->encodeString($this->clientId)
            . $extra;

        $this->writePacket(0x10, $payload);
    }

    private function writePacket(int $type, string $payload): void
    {
        $packet  = chr($type) . $this->encodeRemaining(strlen($payload)) . $payload;
        $written = 0;
        $total   = strlen($packet);
        while ($written < $total) {
            $n = @fwrite($this->socket, substr($packet, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('MQTT socket write failed');
            }
            $written += $n;
        }
    }

    // ── Packet parser ─────────────────────────────────────────────────────────

    private function readPacket(float $timeout): ?array
    {
        if ($this->socket === null) return null;

        $r = [$this->socket];
        $w = $e = null;
        $tvSec  = (int)$timeout;
        $tvUsec = (int)(($timeout - $tvSec) * 1000000);

        $n = @stream_select($r, $w, $e, $tvSec, $tvUsec);
        if ($n === false || $n === 0) return null;

        $b = $this->readBytes(1, 2.0);
        if ($b === null) return null;
        $type = ord($b);

        $len = 0;
        $mul = 1;
        for ($i = 0; $i < 4; $i++) {
            $enc = $this->readBytes(1, 2.0);
            if ($enc === null) return null;
            $byte = ord($enc);
            $len += ($byte & 0x7F) * $mul;
            $mul *= 128;
            if (!($byte & 0x80)) break;
        }

        $data = '';
        if ($len > 0) {
            $chunk = $this->readBytes($len, 5.0);
            if ($chunk === null) return null;
            $data = $chunk;
        }

        return ['type' => $type, 'data' => $data];
    }

    private function readBytes(int $n, float $timeout): ?string
    {
        $buf      = '';
        $need     = $n;
        $deadline = microtime(true) + $timeout;

        while ($need > 0 && microtime(true) < $deadline) {
            $r = [$this->socket];
            $w = $e = null;
            $rem    = $deadline - microtime(true);
            $tvSec  = (int)$rem;
            $tvUsec = (int)(($rem - $tvSec) * 1000000);
            if (@stream_select($r, $w, $e, $tvSec, $tvUsec) < 1) break;
            $chunk = @fread($this->socket, $need);
            if ($chunk === false || $chunk === '') break;
            $buf  .= $chunk;
            $need -= strlen($chunk);
        }

        return $need === 0 ? $buf : null;
    }

    private function parsePublish(array $pkt): ?array
    {
        $data = $pkt['data'];
        $qos  = ($pkt['type'] >> 1) & 0x03;

        if (strlen($data) < 2) return null;

        $topicLen = (ord($data[0]) << 8) | ord($data[1]);
        if (strlen($data) < 2 + $topicLen) return null;

        $topic  = substr($data, 2, $topicLen);
        $offset = 2 + $topicLen;

        if ($qos > 0) {
            if (strlen($data) < $offset + 2) return null;
            $packetId = (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
            $offset  += 2;
            if ($qos === 1) {
                $this->writePacket(0x40, pack('n', $packetId));
            }
        }

        $payload = substr($data, $offset);
        return ['topic' => $topic, 'payload' => $payload];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function encodeString(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    private function encodeRemaining(int $len): string
    {
        $result = '';
        do {
            $byte = $len % 128;
            $len  = intdiv($len, 128);
            if ($len > 0) $byte |= 0x80;
            $result .= chr($byte);
        } while ($len > 0);
        return $result;
    }

    private function assertConnected(): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('MqttClient: not connected. Call connect() first.');
        }
    }
}

// ── Shared payload helpers (used by LBS and Admin) ────────────────────────────
// Mini Jinja2: {{ value }}, {{ value | float / 10 | round(2) }},
//              {{ value_json.key }}, {{ value_json['key'] }}
// Filters: float, int, round, round(N), lower, upper, arithmetic (+,-,*,/)

function mqtt_evalExpr(string $expr, string $raw): string {
    $decoded = null;

    if (preg_match("/^value_json_byid\(\s*'([^']*)'\s*(?:,\s*'([^']*)'\s*)?\)(.*)\$/s", $expr, $m)) {
        // Lookup nach Inhalt statt Position: durchsucht die Werte des JSON-Objekts
        // nach einem Untereintrag, dessen Feld (Default 'Id') == gesuchtem Wert.
        // Beispiel: value_json_byid('000000AF9C70').Temperature
        //           value_json_byid('000000AF9C70','Address').Temperature
        $idVal   = $m[1];
        $idField = (isset($m[2]) && $m[2] !== '') ? $m[2] : 'Id';
        $decoded = json_decode($raw, true);
        $cur = '';
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (is_array($entry) && isset($entry[$idField]) && (string)$entry[$idField] === $idVal) {
                    $cur = $entry;
                    break;
                }
            }
        }
        $rest = isset($m[3]) ? $m[3] : '';
        // Chained dot-notation: .subkey nach dem Lookup
        while (is_array($cur) && preg_match('/^\.(\w+)(.*)$/s', $rest, $sm)) {
            $cur  = isset($cur[$sm[1]]) ? $cur[$sm[1]] : '';
            $rest = $sm[2];
        }
        $val = is_array($cur) ? '' : (string)$cur;
    } elseif (preg_match('/^value_json\[\'([^\']+)\'\](.*)$/s', $expr, $m)) {
        $decoded = json_decode($raw, true);
        $cur  = (is_array($decoded) && isset($decoded[$m[1]])) ? $decoded[$m[1]] : '';
        $rest = $m[2];
        // Chained dot-notation after bracket: value_json['key'].subkey
        while (is_array($cur) && preg_match('/^\.(\w+)(.*)$/s', $rest, $sm)) {
            $cur  = isset($cur[$sm[1]]) ? $cur[$sm[1]] : '';
            $rest = $sm[2];
        }
        $val = is_array($cur) ? '' : (string)$cur;
    } elseif (preg_match('/^value_json\.(\w+(?:\.\w+)*)(.*)$/s', $expr, $m)) {
        $decoded = json_decode($raw, true);
        $cur = $decoded;
        foreach (explode('.', $m[1]) as $key) {
            if (is_array($cur) && isset($cur[$key])) {
                $cur = $cur[$key];
            } else {
                $cur = '';
                break;
            }
        }
        $val  = is_array($cur) ? '' : (string)$cur;
        $rest = $m[2];
    } elseif (preg_match('/^value_xml\.(\w+)(.*)$/s', $expr, $m)) {
        $tag  = preg_quote($m[1], '/');
        $val  = preg_match('/<' . $tag . '>(.*?)<\/' . $tag . '>/s', $raw, $xm) ? $xm[1] : '';
        $rest = $m[2];
    } elseif (preg_match('/^value(.*)$/s', $expr, $m)) {
        $val  = $raw;
        $rest = $m[1];
    } else {
        return $raw;
    }

    while ($rest !== '') {
        $rest = ltrim($rest);
        if ($rest === '') break;
        if ($rest[0] === '|') {
            $rest = ltrim(substr($rest, 1));
            if ($rest === '') break;
        }

        if (preg_match('/^float\b(.*)$/s', $rest, $m)) {
            $val = (string)(float)$val;
            $rest = $m[1];
        } elseif (preg_match('/^int\b(.*)$/s', $rest, $m)) {
            $val = (string)(int)((float)$val);
            $rest = $m[1];
        } elseif (preg_match('/^round\(\s*(\d+)\s*\)(.*)$/s', $rest, $m)) {
            $val = (string)round((float)$val, (int)$m[1]);
            $rest = $m[2];
        } elseif (preg_match('/^round\b(.*)$/s', $rest, $m)) {
            $val = (string)round((float)$val);
            $rest = $m[1];
        } elseif (preg_match('/^([+\-\*\/])\s*([\d.]+)(.*)$/s', $rest, $m)) {
            $n = (float)$m[2];
            switch ($m[1]) {
                case '+': $val = (string)((float)$val + $n); break;
                case '-': $val = (string)((float)$val - $n); break;
                case '*': $val = (string)((float)$val * $n); break;
                case '/': $val = ($n != 0.0) ? (string)((float)$val / $n) : '0'; break;
            }
            $rest = $m[3];
        } elseif (preg_match('/^lower\b(.*)$/s', $rest, $m)) {
            $val = strtolower($val);
            $rest = $m[1];
        } elseif (preg_match('/^upper\b(.*)$/s', $rest, $m)) {
            $val = strtoupper($val);
            $rest = $m[1];
        } else {
            break;
        }
    }
    return $val;
}

function mqtt_evalTemplate(string $tpl, string $raw): string {
    $tpl = trim($tpl);
    if ($tpl === '') return $raw;
    return preg_replace_callback('/\{\{(.+?)\}\}/s', function($m) use ($raw) {
        return mqtt_evalExpr(trim($m[1]), $raw);
    }, $tpl);
}

function mqtt_applyReceive($payload, $ch) {
    $raw = (string)$payload;

    // jsonPath: dot-notation field extraction from JSON payload
    $jp = (string)($ch['jsonPath'] ?? '');
    if ($jp !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $cur = $dec;
            foreach (explode('.', $jp) as $key) {
                $cur = is_array($cur) && isset($cur[$key]) ? $cur[$key] : null;
            }
            $raw = ($cur !== null) ? (string)$cur : $raw;
        }
    }

    $tpl = (string)($ch['valueTemplate'] ?? '');
    $val = ($tpl !== '') ? mqtt_evalTemplate($tpl, $raw) : $raw;

    if ($ch['valueMapIn'] !== null && isset($ch['valueMapIn'][$val])) {
        $val = (string)$ch['valueMapIn'][$val];
    }

    switch ($ch['dataType']) {
        case 'int':   $val = (string)(int)round((float)$val); break;
        case 'float': $val = (string)round((float)$val, 4); break;
        case 'bool':  $val = ((float)$val != 0.0 || strtolower($val) === 'true') ? '1' : '0'; break;
    }
    return $val;
}

function mqtt_initDB($db) {
    mysqli_query($db,
        "CREATE TABLE IF NOT EXISTS edomiProject.mqttDevice (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deviceName VARCHAR(64)     NOT NULL DEFAULT '',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    mysqli_query($db,
        "CREATE TABLE IF NOT EXISTS edomiProject.mqttChannel (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deviceID        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name            VARCHAR(64)     NOT NULL DEFAULT '',
            subscribeTopic  VARCHAR(255)    NOT NULL DEFAULT '',
            publishTopic    VARCHAR(255)    NOT NULL DEFAULT '',
            dataType        VARCHAR(16)     NOT NULL DEFAULT 'string',
            jsonPath        VARCHAR(128)    NOT NULL DEFAULT '',
            valueTemplate   VARCHAR(512)    NOT NULL DEFAULT '',
            commandTemplate VARCHAR(512)    NOT NULL DEFAULT '',
            valueMapIn      TEXT,
            valueMapOut     TEXT,
            direction       VARCHAR(16)     NOT NULL DEFAULT 'both',
            unit            VARCHAR(32)     NOT NULL DEFAULT '',
            deviceClass     VARCHAR(32)     NOT NULL DEFAULT '',
            discoveryTopic  VARCHAR(255)    NOT NULL DEFAULT '',
            koIDsub         BIGINT UNSIGNED NOT NULL DEFAULT 0,
            koIDpub         BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note            VARCHAR(512)    NOT NULL DEFAULT '',
            lastValue       VARCHAR(255)    NOT NULL DEFAULT '',
            lastSeen        DATETIME,
            PRIMARY KEY (id),
            KEY idx_device (deviceID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    mysqli_query($db,
        "CREATE TABLE IF NOT EXISTS edomiProject.mqttDiscovered (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            discoveryTopic VARCHAR(255)    NOT NULL DEFAULT '',
            component      VARCHAR(32)     NOT NULL DEFAULT '',
            payload        TEXT,
            seen_at        DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dtopic (discoveryTopic)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    mysqli_query($db,
        "CREATE TABLE IF NOT EXISTS edomiProject.mqttBroker (
            id        TINYINT UNSIGNED  NOT NULL DEFAULT 1,
            host      VARCHAR(128)      NOT NULL DEFAULT 'localhost',
            port      SMALLINT UNSIGNED NOT NULL DEFAULT 1883,
            username  VARCHAR(64)       NOT NULL DEFAULT '',
            password  VARCHAR(128)      NOT NULL DEFAULT '',
            scanUntil DATETIME          NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    mysqli_query($db,
        "CREATE TABLE IF NOT EXISTS edomiProject.mqttTopicScan (
            topic   VARCHAR(255) NOT NULL,
            payload VARCHAR(2048),
            seen_at DATETIME,
            PRIMARY KEY (topic)
        ) ENGINE=MEMORY"
    );
}

function mqtt_applySend($rawVal, $ch) {
    $val = (string)$rawVal;

    if ($ch['valueMapOut'] !== null && isset($ch['valueMapOut'][$val])) {
        $val = (string)$ch['valueMapOut'][$val];
    }

    $tpl = (string)($ch['commandTemplate'] ?? '');
    if ($tpl !== '') {
        $val = preg_replace_callback('/\{\{(.+?)\}\}/s', function($m) use ($val) {
            return mqtt_evalExpr(trim($m[1]), $val);
        }, $tpl);
    }
    return $val;
}

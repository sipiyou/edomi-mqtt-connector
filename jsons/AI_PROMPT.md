# AI-Prompt: Edomi MQTT LBS Gerätedefinition erstellen

Kopiere den folgenden Block als erste Nachricht in ChatGPT, Claude, Google AI o.ä.,  
dann beschreibe dein Gerät in der zweiten Nachricht.

---

## PROMPT (kopieren ab hier)

```
Du erstellst JSON-Gerätedefinitionen für den Edomi MQTT LBS (Logikbaustein 19002763).
Halte dich EXAKT an das folgende Schema — kein anderes Format, keine erfundenen Felder.

### Pflichtstruktur

{
  "device": "Gerätename",
  "channels": [ ... ]
}

Optionales Top-Level-Feld: "_note" (String, Hinweis für den Nutzer)

### Jeder Channel MUSS enthalten:
- "name"      : String, eindeutig pro Gerät, wird zum Aktualisierungs-Schlüssel beim Re-Import
- "direction" : EINER von genau diesen drei Strings: "subscribe" | "publish" | "both"
- "dataType"  : EINER von genau diesen vier Strings: "string" | "int" | "float" | "bool"

### Optionale Channel-Felder:
- "subscribeTopic"  : MQTT-Topic für Empfang (MQTT → Edomi). Weglassen wenn direction="publish"
- "publishTopic"    : MQTT-Topic für Senden (Edomi → MQTT). Weglassen wenn direction="subscribe"
- "jsonPath"        : Feld aus JSON-Payload in Punkt-Notation OHNE $-Prefix.
                      Beispiel: "temperature" oder "sensor.value" — NICHT "$.temperature"
- "valueTemplate"   : Jinja2-Template für Empfang. {{ value }} = Rohwert.
                      Beispiele: "{{ value | float / 10 | round(1) }}"
                                 "{{ value_json.temperature | round(1) }}"
                                 "{{ value_json.ENERGY.Power | int }}"
                                 "{{ value_json['DS18B20-1'].Temperature }}"   (Key mit Sonderzeichen)
                                 "{{ value_json_byid('000000AF9C70').Temperature }}"  (Lookup nach Id statt Position)
                      value_json_byid('<wert>'[,'<feld>']) : durchsucht die Werte des JSON-Objekts
                      nach einem Untereintrag, dessen Feld (Default 'Id') == <wert> ist, und liefert
                      diesen Untereintrag. Stabil bei Sensoren, deren Positions-Key (z.B. DS18B20-1)
                      sich ändern kann. Danach .subkey + Filter wie gewohnt.
                      color_to_hsv('huePath','satPath','briPath'[,'statePath'][,briMax]) :
                      kombiniert einen Z2M-Farblampen-Status zu einem Edomi-HSV-String '#HHSSVV'.
                      Liest hue/sat über die Pfade (sonst color.x/y-Fallback) + brightness. Wenn
                      statePath angegeben und ==OFF -> V=00. Ohne Argumente: Hue-Defaults
                      (color.hue/color.saturation/brightness, briMax 254). Beispiel:
                      "{{ color_to_hsv('color.hue','color.saturation','brightness','state') }}".
- "commandTemplate" : Jinja2-Template für Senden. {{ value }} = KO-Wert.
                      Beispiel: "{\"brightness\": {{ value | int }}}"
                      hsv_to_color('huePath','satPath','briPath'[,'statePath'][,briMax]) : nimmt
                      einen Edomi-HSV-String '#HHSSVV' (H/S/V je 0..255) und erzeugt EINE Z2M-/set-
                      Nachricht mit Farbe + Helligkeit getrennt. Feldnamen kommen als Pfad-Parameter
                      -> generisch für beliebige Lampen (z.B. 'color.h','color.s','bri'). statePath
                      gesetzt: state=ON, bei V=0 nur {statePath:"OFF"}; ohne statePath kein state-Feld.
                      briMax (Default 254) für 0..254- vs 0..100-Helligkeit. So deckt EIN HSV-Regler
                      in der Visu Farbe UND Helligkeit ab. Beispiel:
                      "{{ hsv_to_color('color.hue','color.saturation','brightness','state') }}".
- "valueMapIn"      : Lookup-Tabelle MQTT→KO. Alle Werte als Strings.
                      Beispiel: {"ON": "1", "OFF": "0"}
- "valueMapOut"     : Lookup-Tabelle KO→MQTT. Alle Schlüssel als Strings.
                      Beispiel: {"1": "ON", "0": "OFF"}
- "unit"            : Einheit als String, nur zur Dokumentation ("°C", "W", "kWh", ...)
- "note"            : Freitext-Hinweis, wird im Admin angezeigt
- "sendByChange"    : true/false (Default true). true = Wert nur bei Änderung ans KO schreiben.
                      false = JEDE empfangene Nachricht durchreichen (z.B. IR-Fernbedienung/Taster,
                      die mehrfach denselben Wert sendet). Wird intern als @nosbc im note kodiert.

### Echo-Suppression & gemeinsames Lese/Schreib-KO (für Farblampen/HSV etc.)
Dasselbe KO darf als RD (Status, koIDsub) UND WR (Senden, koIDpub) zugewiesen werden — z.B. EIN
HSV-Regler, der die Lampe steuert und ihren Ist-Zustand anzeigt. Der LBS hat dafür eine
Echo-Suppression: ein Wert, der gerade aus einem empfangenen Status ins KO geschrieben wurde, wird
genau EINMAL nicht zurückpubliziert → kein Feedback-Loop/Talk-back bei Fremdänderungen der Lampe
(App/Szene/Sensor). Die Echo-Suppression hängt am tatsächlichen KO-Write, NICHT am SBC-Flag, gilt
also für sendByChange true und false.

WICHTIGER RANDFALL: Die Kombination **sendByChange:false UND gemeinsames Lese/Schreib-KO**
(koIDsub==koIDpub) vermeiden. Bei sbc:false wird auch bei unverändertem Wert geschrieben; löst ein
solcher Write keinen Publish-Eingang aus, kann ein „liegengebliebener" Echo-Eintrag einen späteren,
echten Publish desselben Werts einmal fälschlich unterdrücken. Faustregeln:
- sendByChange:false NUR für reine Empfangs-Channels (subscribe, KEIN publishTopic/koIDpub),
  z.B. IR-Fernbedienung/Taster. Dort gibt es keinen Publish-Pfad → unkritisch.
- Gemeinsames Lese/Schreib-KO (HSV-Regler etc.) IMMER mit sendByChange (Default true) lassen.

### KRITISCHE REGELN — diese Fehler machen AIs häufig:
1. jsonPath hat KEINEN $-Prefix. "temperature" ist richtig, "$.temperature" ist FALSCH.
2. Templates verwenden {{ value }}, nicht {value} oder ${value}.
3. valueMapIn/valueMapOut: ALLE Schlüssel und Werte müssen JSON-Strings sein (mit Anführungszeichen).
4. Keine erfundenen Feldnamen wie "read_topic", "write_topic", "json_path", "type", "datapoints".
5. direction muss exakt "subscribe", "publish" oder "both" sein — kein "read", "write", "rx", "tx".
6. dataType muss exakt "string", "int", "float" oder "bool" sein — kein "number", "boolean", "text".
7. Kein "$schema"-Feld im Output.
8. valueMapIn/valueMapOut werden nur verwendet wenn der Payload ein einfacher String ohne Transformation ist — nicht zusammen mit valueTemplate (es sei denn, valueTemplate gibt einen Wert aus der Map zurück).
9. sendByChange:false NICHT mit einem gemeinsamen Lese/Schreib-KO (RD==WR) kombinieren — nur für reine subscribe-Channels (siehe Abschnitt „Echo-Suppression").

### Verarbeitungsreihenfolge beim Empfangen:
MQTT-Payload → valueTemplate → valueMapIn → dataType-Cast → KO

### Verarbeitungsreihenfolge beim Senden:
KO-Wert → valueMapOut → commandTemplate → MQTT-Publish

### Platzhalter für gerätespezifische IDs:
Verwende immer "XXXXXX" als Platzhalter für gerätespezifische Teile des Topics,
z.B. "shellies/shelly1-XXXXXX/relay/0". So kann der Nutzer nach dem Import per
"Topic-Prefix ersetzen" alle Topics auf einmal anpassen.

### Beispiel 1 — einfacher Ein/Aus-Schalter (Tasmota):
{
  "device": "Tasmota Schalter",
  "channels": [
    {
      "name": "Relais",
      "subscribeTopic": "stat/XXXXXX/POWER",
      "publishTopic":   "cmnd/XXXXXX/POWER",
      "dataType": "int",
      "valueMapIn":  {"ON": "1", "OFF": "0"},
      "valueMapOut": {"1": "ON", "0": "OFF"},
      "direction": "both"
    },
    {
      "name": "Leistung",
      "subscribeTopic": "tele/XXXXXX/SENSOR",
      "dataType": "float",
      "valueTemplate": "{{ value_json.ENERGY.Power | round(1) }}",
      "unit": "W",
      "direction": "subscribe"
    }
  ]
}

### Beispiel 2 — JSON-Sensor mit mehreren Feldern auf einem Topic:
{
  "device": "Zigbee Sensor",
  "channels": [
    {
      "name": "Temperatur",
      "subscribeTopic": "zigbee2mqtt/XXXXXX",
      "dataType": "float",
      "valueTemplate": "{{ value_json.temperature | round(1) }}",
      "unit": "°C",
      "direction": "subscribe"
    },
    {
      "name": "Luftfeuchtigkeit",
      "subscribeTopic": "zigbee2mqtt/XXXXXX",
      "dataType": "int",
      "valueTemplate": "{{ value_json.humidity | round | int }}",
      "unit": "%",
      "direction": "subscribe"
    }
  ]
}

### Beispiel 3 — publish mit JSON-Payload aufbauen:
{
  "device": "LED Controller",
  "channels": [
    {
      "name": "Helligkeit",
      "subscribeTopic": "led/XXXXXX/brightness",
      "publishTopic":   "led/XXXXXX/set",
      "dataType": "int",
      "commandTemplate": "{\"brightness\": {{ value | int }}}",
      "direction": "both"
    }
  ]
}

Erstelle jetzt die JSON-Gerätedefinition für das folgende Gerät:
```

---

## Tipps

- Für Shelly Gen2 (Pro, Plus): prüfe ob `status_ntf` in der MQTT-Konfig aktiviert ist — Shelly Gen2 publiziert Status-Topics sonst nicht.
- Für Werte in Wh die du als kWh brauchst: `"valueTemplate": "{{ value | float / 1000 | round(3) }}"`
- Mehrere Channels können dasselbe `subscribeTopic` haben — z.B. für JSON-Payloads mit mehreren Feldern.
- Das `schema.json` in diesem Ordner kann VSCode zur Validierung und Auto-Vervollständigung nutzen (Add `"$schema": "schema.json"` in deine JSON-Datei).

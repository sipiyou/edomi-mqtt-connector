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
- "commandTemplate" : Jinja2-Template für Senden. {{ value }} = KO-Wert.
                      Beispiel: "{\"brightness\": {{ value | int }}}"
- "valueMapIn"      : Lookup-Tabelle MQTT→KO. Alle Werte als Strings.
                      Beispiel: {"ON": "1", "OFF": "0"}
- "valueMapOut"     : Lookup-Tabelle KO→MQTT. Alle Schlüssel als Strings.
                      Beispiel: {"1": "ON", "0": "OFF"}
- "unit"            : Einheit als String, nur zur Dokumentation ("°C", "W", "kWh", ...)
- "note"            : Freitext-Hinweis, wird im Admin angezeigt

### KRITISCHE REGELN — diese Fehler machen AIs häufig:
1. jsonPath hat KEINEN $-Prefix. "temperature" ist richtig, "$.temperature" ist FALSCH.
2. Templates verwenden {{ value }}, nicht {value} oder ${value}.
3. valueMapIn/valueMapOut: ALLE Schlüssel und Werte müssen JSON-Strings sein (mit Anführungszeichen).
4. Keine erfundenen Feldnamen wie "read_topic", "write_topic", "json_path", "type", "datapoints".
5. direction muss exakt "subscribe", "publish" oder "both" sein — kein "read", "write", "rx", "tx".
6. dataType muss exakt "string", "int", "float" oder "bool" sein — kein "number", "boolean", "text".
7. Kein "$schema"-Feld im Output.
8. valueMapIn/valueMapOut werden nur verwendet wenn der Payload ein einfacher String ohne Transformation ist — nicht zusammen mit valueTemplate (es sei denn, valueTemplate gibt einen Wert aus der Map zurück).

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

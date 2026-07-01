# MQTT Connector – JSON Gerätedefinition

Dieses Dokument beschreibt das JSON-Format zur Definition von MQTT-Geräten für den Edomi MQTT Connector LBS (19002763).

## Dateien in diesem Ordner

| Datei | Beschreibung |
|-------|--------------|
| `schema.json` | Formales JSON-Schema (Draft-07) zur Validierung und VSCode Auto-Vervollständigung |
| `AI_PROMPT.md` | Copy-Paste-Prompt für ChatGPT/Claude/Google AI zur Erstellung neuer Gerätedefinitionen |
| `WLED.json` | Beispiel: WLED LED-Controller |
| `Tasmota.json` | Beispiel: Tasmota-basierte Geräte (Schalter, Steckdose, Sensor) |
| `ShellyPro3EM.json` | Beispiel: Shelly Pro 3EM Energiezähler (Gen2) |

## VSCode-Validierung

Um Validierung und Auto-Vervollständigung in VSCode zu aktivieren, füge in deine JSON-Datei ein:

```json
{
  "$schema": "schema.json",
  "device": "...",
  "channels": [ ... ]
}
```

---

---

## Struktur

```json
{
  "device": "Gerätename",
  "note": "Optionaler Hinweis",
  "channels": [ ... ]
}
```

| Feld       | Typ    | Pflicht | Beschreibung |
|------------|--------|---------|--------------|
| `device`   | string | ja      | Anzeigename des Geräts im Admin. Wird beim Import als Gerätename verwendet (außer wenn ein Zielgerät ausgewählt wurde). |
| `note`     | string | nein    | Freitext-Hinweis für das gesamte Gerät, wird im Admin angezeigt. |
| `channels` | array  | ja      | Liste der Channel-Definitionen (siehe unten). |

---

## Channel-Felder

Jeder Eintrag in `channels` ist ein JSON-Objekt:

```json
{
  "name":            "Kanalname",
  "note":            "Optionaler Hinweis",
  "subscribeTopic":  "mqtt/topic/empfangen",
  "publishTopic":    "mqtt/topic/senden",
  "dataType":        "int",
  "direction":       "both",
  "valueTemplate":   "{{ value | float * 0.1 | round(1) }}",
  "commandTemplate": "{\"val\":{{ value | int }}}",
  "valueMapIn":      {"on": 1, "off": 0},
  "valueMapOut":     {"1": "on", "0": "off"}
}
```

### Pflichtfelder

| Feld        | Typ    | Beschreibung |
|-------------|--------|--------------|
| `name`      | string | Anzeigename im Admin. Wird beim Re-Import als Schlüssel zur Zuordnung bestehender Channels verwendet — **Name nicht ändern**, sonst wird ein neuer Channel angelegt. |
| `direction` | string | `subscribe`, `publish` oder `both` |

### Optionale Felder

| Feld              | Typ    | Beschreibung |
|-------------------|--------|--------------|
| `note`            | string | Freitext-Hinweis, wird im Admin unter dem Channel-Namen angezeigt. |
| `subscribeTopic`  | string | MQTT-Topic auf das subscribed wird (MQTT → Edomi KO). Leer = nur publish. |
| `publishTopic`    | string | MQTT-Topic auf das publiziert wird (Edomi KO → MQTT). Leer = nur subscribe. |
| `dataType`        | string | Datentyp des KO-Werts: `string`, `int`, `float`, `bool`. Standard: `string`. |
| `valueTemplate`   | string | Jinja2-Ausdruck zur Transformation des empfangenen Payload (siehe unten). |
| `commandTemplate` | string | Jinja2-Ausdruck zur Transformation des gesendeten Werts (siehe unten). |
| `valueMapIn`      | object | Lookup-Tabelle: MQTT-Wert → KO-Wert (wird nach `valueTemplate` angewendet). |
| `valueMapOut`     | object | Lookup-Tabelle: KO-Wert → MQTT-Wert (wird vor `commandTemplate` angewendet). |
| `sendByChange`    | bool   | Default `true`. `false` = jede empfangene Nachricht ans KO durchreichen (auch bei gleichem Wert), z.B. IR-Fernbedienung/Taster. Wird intern als `@nosbc` im `note` gespeichert (keine eigene DB-Spalte). |

> **Gemeinsames KO für Status + Steuerung (z.B. ein HSV-Regler):** Du darfst dasselbe KO als RD
> (Status) und WR (Senden) zuweisen. Der LBS hat **Echo-Suppression** — ein aus einem empfangenen
> Status geschriebener Wert wird genau einmal **nicht** zurückpubliziert, d.h. Fremdänderungen der
> Lampe (App/Szene/Sensor) aktualisieren nur die Anzeige und lösen kein „Talk-back" aus.

---

## Richtung (`direction`)

| Wert        | Bedeutung |
|-------------|-----------|
| `subscribe` | Nur empfangen: MQTT-Topic → Edomi KO. Kein Publish. |
| `publish`   | Nur senden: Edomi KO → MQTT-Topic. Kein Subscribe. |
| `both`      | Beides: Subscribe und Publish (jeweils eigenes Topic möglich). |

Bei `direction: both` können `subscribeTopic` und `publishTopic` **unterschiedlich** sein — typisch bei Geräten die Status auf einem Topic melden und Befehle auf einem anderen erwarten.

---

## Datentyp (`dataType`)

Bestimmt wie der Wert nach der Template-/Mapping-Verarbeitung ins KO geschrieben wird:

| Wert     | Verarbeitung |
|----------|--------------|
| `string` | Rohwert als Text (kein Cast). |
| `int`    | `round(float(value))` → Ganzzahl. |
| `float`  | Auf 4 Nachkommastellen gerundet. |
| `bool`   | `0` wenn Wert `0` oder `"false"`, sonst `1`. |

---

## Payload-Pipeline

### Empfangen (MQTT → KO)

```
MQTT-Payload
    → valueTemplate   (optional: Transformation/Extraktion)
    → valueMapIn      (optional: String-Ersetzung)
    → dataType-Cast
    → KO schreiben
```

### Senden (KO → MQTT)

```
KO-Wert
    → valueMapOut     (optional: String-Ersetzung)
    → commandTemplate (optional: Payload aufbauen)
    → MQTT publishen
```

---

## Template-Syntax (`valueTemplate` / `commandTemplate`)

Mini Jinja2-Parser. Ausdrücke werden in `{{ }}` eingebettet.

### Variablen

| Variable                    | Beschreibung |
|-----------------------------|--------------|
| `value`                     | Rohwert des MQTT-Payloads (string) |
| `value_json.feld`           | JSON-Feld aus dem Payload extrahieren |
| `value_json.feld.unterfeld` | Verschachteltes JSON-Feld (beliebig tief, Punkt-Notation) |
| `value_json['feld']`        | Alternativschreibweise für JSON-Extraktion (eine Ebene) |
| `value_xml.tag`             | XML-Tag-Inhalt aus dem Payload extrahieren (`<tag>inhalt</tag>`) |

### Filter (mit `|` verkettbar)

| Filter        | Beschreibung |
|---------------|--------------|
| `float`       | In Dezimalzahl umwandeln |
| `int`         | In Ganzzahl umwandeln (schneidet ab) |
| `round`       | Auf ganze Zahl runden |
| `round(N)`    | Auf N Nachkommastellen runden |
| `lower`       | In Kleinbuchstaben umwandeln |
| `upper`       | In Großbuchstaben umwandeln |
| `* N`         | Multiplizieren mit N |
| `/ N`         | Dividieren durch N |
| `+ N`         | Addieren |
| `- N`         | Subtrahieren |

### Funktionen

| Funktion | Richtung | Beschreibung |
|----------|----------|--------------|
| `value_json_byid('<wert>'[,'<feld>'])` | Empfang | Durchsucht die Werte des JSON-Objekts nach einem Untereintrag, dessen Feld (Default `Id`) == `<wert>` ist. Danach `.subkey` + Filter. Stabil bei Sensoren mit wechselndem Positions-Key. |
| `hsv_to_color('huePath','satPath','briPath'[,'statePath'][,briMax])` | Senden | Edomi-HSV-String `#HHSSVV` (H/S/V je 0..255) → EINE Z2M-/set-Nachricht mit Farbe + Helligkeit getrennt. Feldnamen als Pfad-Parameter (generisch, z.B. `'color.h','color.s','bri'`). **statePath gesetzt:** `state=ON`, bei V=0 nur `{statePath:"OFF"}`; **ohne statePath:** kein `state`-Feld. `briMax` (Default 254) für 0..254 vs 0..100. Ohne Argumente: Hue-Defaults. |
| `color_to_hsv('huePath','satPath','briPath'[,'statePath'][,briMax])` | Empfang | Z2M-Farblampen-Status → Edomi-HSV-String `#HHSSVV`. Liest hue/sat über die Pfade (sonst `color.x`/`color.y` xy→HS-Fallback), V aus briPath. statePath gesetzt und `==OFF` → V=00. Ohne Argumente: Hue-Defaults (`color.hue`/`color.saturation`/`brightness`, briMax 254). |

### Beispiele

```
{{ value }}
    → Rohwert unverändert ausgeben

{{ value | float * 0.392 | round | int }}
    → WLED Helligkeit 0–255 → Edomi 0–100%

{{ value | float * 2.55 | round | int }}
    → Edomi 0–100% → WLED Helligkeit 0–255

{{ value | float / 10 | round(1) }}
    → Rohwert durch 10 teilen (z.B. Tasmota Temperatur x10)

{{ value_json.temperature }}
    → Feld "temperature" aus JSON-Payload

{{ value_json.temperature | round(1) }}
    → JSON-Feld runden

{{ value_json.ENERGY.Power | int }}
    → Verschachteltes Feld (z.B. Tasmota ENERGY-Block)

{{ value_json.DHT22.Temperature | round(1) }}
    → Verschachtelter Sensor-Wert (z.B. Tasmota DHT22)

{{ value_xml.ac }}
    → Tag <ac> aus XML-Payload extrahieren (z.B. WLED /v Topic)

{{ value_xml.fx | int }}
    → XML-Tag als Ganzzahl

{"bri":{{ value | float * 2.55 | round | int }}}
    → JSON-Payload für commandTemplate aufbauen

{{ hsv_to_color('color.hue','color.saturation','brightness','state') }}
    → commandTemplate: Edomi-HSV #HHSSVV → {"state":"ON","brightness":B,"color":{"hue":H,"saturation":S}} (V=0 = AUS)

{{ hsv_to_color('color.h','color.s','bri') }}
    → andere Lampe mit Feldnamen bri/h/s, ohne state-Feld

{{ color_to_hsv('color.hue','color.saturation','brightness','state') }}
    → valueTemplate: Z2M-Status → Edomi-HSV #HHSSVV (state=OFF → V=00)
```

---

## Wert-Mapping (`valueMapIn` / `valueMapOut`)

Lookup-Tabellen zur Ersetzung von String-Werten.

### `valueMapIn` — beim Empfangen

Wird **nach** `valueTemplate` angewendet. Schlüssel = MQTT-Wert, Wert = KO-Wert.

```json
"valueMapIn": {"on": 1, "off": 0, "online": 1, "offline": 0}
```

### `valueMapOut` — beim Senden

Wird **vor** `commandTemplate` angewendet. Schlüssel = KO-Wert (als String), Wert = MQTT-Payload.

```json
"valueMapOut": {"1": "on", "0": "off"}
```

> **Hinweis:** Die Schlüssel in `valueMapOut` sind immer Strings, auch wenn der KO-Wert numerisch ist.

---

## Import-Verhalten

- **Neues Gerät:** Device wird nach `device`-Feld benannt, alle Channels werden neu angelegt.
- **Vorhandenes Gerät aktualisieren** (Zielgerät im Admin ausgewählt):
  - Channels werden anhand des **Namens** abgeglichen.
  - Vorhandene Channels: Topics bleiben erhalten, Templates/Mapping/Note werden aktualisiert, **KO-Zuordnungen bleiben erhalten**.
  - Neue Channels (im JSON, nicht in DB): werden hinzugefügt mit Topics aus JSON.
  - Channels die nur in der DB existieren: bleiben erhalten (werden nicht gelöscht).
- Nach dem Import von neuen Channels: Topics ggf. per **Topic-Prefix ersetzen** im Admin anpassen.

---

## Vollständiges Beispiel — WLED

```json
{
  "device": "WLED/xx",
  "note": "Topic-Prefix anpassen: wled/xx → wled/GeräteName (Config → Sync → MQTT → Topic)",
  "channels": [
    {
      "name": "Status",
      "subscribeTopic": "wled/xx/status",
      "dataType": "int",
      "valueMapIn": {"online": 1, "offline": 0},
      "direction": "subscribe"
    },
    {
      "name": "Power",
      "subscribeTopic": "wled/xx",
      "publishTopic":   "wled/xx",
      "dataType": "int",
      "valueMapIn":  {"on": 1, "off": 0},
      "valueMapOut": {"1": "on", "0": "off"},
      "direction": "both"
    },
    {
      "name": "Helligkeit",
      "subscribeTopic": "wled/xx/g",
      "publishTopic":   "wled/xx/api",
      "dataType": "int",
      "valueTemplate":   "{{ value | float * 0.392 | round | int }}",
      "commandTemplate": "{\"bri\":{{ value | float * 2.55 | round | int }}}",
      "direction": "both"
    },
    {
      "name": "Farbe",
      "note": "Farbwert als RRGGBB Hex-String ohne # (z.B. FF0000 = Rot)",
      "subscribeTopic": "wled/xx/c",
      "publishTopic":   "wled/xx/col",
      "dataType": "string",
      "direction": "both"
    },
    {
      "name": "Preset",
      "subscribeTopic": "wled/xx/v",
      "publishTopic":   "wled/xx/api",
      "dataType": "int",
      "valueTemplate":   "{{ value_xml.ps | int }}",
      "commandTemplate": "{\"ps\":{{ value | int }}}",
      "direction": "both"
    },
    {
      "name": "Effekt",
      "subscribeTopic": "wled/xx/v",
      "publishTopic":   "wled/xx/api",
      "dataType": "int",
      "valueTemplate":   "{{ value_xml.fx | int }}",
      "commandTemplate": "{\"seg\":[{\"fx\":{{ value | int }}}]}",
      "direction": "both"
    }
  ]
}
```

---

## Vollständiges Beispiel — Zigbee2MQTT Temperatursensor

```json
{
  "device": "Zigbee Sensor Küche",
  "channels": [
    {
      "name": "Temperatur",
      "subscribeTopic": "zigbee2mqtt/sensor_kueche",
      "dataType": "float",
      "valueTemplate": "{{ value_json.temperature | round(1) }}",
      "direction": "subscribe"
    },
    {
      "name": "Luftfeuchtigkeit",
      "subscribeTopic": "zigbee2mqtt/sensor_kueche",
      "dataType": "int",
      "valueTemplate": "{{ value_json.humidity | round | int }}",
      "direction": "subscribe"
    },
    {
      "name": "Batterie",
      "subscribeTopic": "zigbee2mqtt/sensor_kueche",
      "dataType": "int",
      "valueTemplate": "{{ value_json.battery | int }}",
      "direction": "subscribe"
    }
  ]
}
```

---

## Vollständiges Beispiel — Shelly (Ein/Aus + Leistung)

```json
{
  "device": "Shelly Steckdose",
  "channels": [
    {
      "name": "Power",
      "subscribeTopic": "shellies/shelly1-XXXXXX/relay/0",
      "publishTopic":   "shellies/shelly1-XXXXXX/relay/0/command",
      "dataType": "int",
      "valueMapIn":  {"on": 1, "off": 0},
      "valueMapOut": {"1": "on", "0": "off"},
      "direction": "both"
    },
    {
      "name": "Leistung",
      "subscribeTopic": "shellies/shelly1-XXXXXX/relay/0/power",
      "dataType": "float",
      "direction": "subscribe"
    }
  ]
}
```

---

## Mehrere Channels auf demselben Topic

Mehrere Channels können dasselbe `subscribeTopic` haben — jeder extrahiert dann ein anderes Feld:

```json
{ "name": "Temperatur",     "subscribeTopic": "sensor/daten", "valueTemplate": "{{ value_json.temp }}", ... },
{ "name": "Luftfeuchtigkeit","subscribeTopic": "sensor/daten", "valueTemplate": "{{ value_json.hum }}",  ... },
{ "name": "Status XML",     "subscribeTopic": "gerät/v",       "valueTemplate": "{{ value_xml.ac }}",    ... }
```

---

## Hinweise

- **Topic-Platzhalter:** Verwende einen einheitlichen Prefix-Platzhalter (z.B. `geraet/xx`) der nach dem Import per *Topic-Prefix ersetzen* im Admin in einem Schritt auf alle Channels angewendet wird.
- **Neuimport:** Beim Update eines vorhandenen Geräts werden Topics bestehender Channels **nicht** überschrieben. Nur neue Channels erhalten die Topics aus der JSON.
- **KO-Zuordnung:** Nach dem Import KO per Admin zuweisen, dann LBS neu starten (E1=0 → E1=1) damit der neue Subscribe-Topic registriert wird.
- **Werte für `valueMapOut`:** Schlüssel müssen Strings sein, also `"1"` nicht `1`.

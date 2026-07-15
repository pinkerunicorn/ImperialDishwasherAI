# ImperialDishwasherAI

Ein IP-Symcon Modul zur intelligenten Erkennung von Programmphasen einer Imperial Spülmaschine (GSI 8265 BS) mittels Google Gemini KI.

## Voraussetzungen

- IP-Symcon ab Version 8
- HmIP-PSM (oder vergleichbare) Messsteckdose, die den aktuellen Stromverbrauch (Watt) in eine IP-Symcon Variable schreibt
- Google Gemini API-Schlüssel

## Funktionen

Das Modul überwacht die Leistungsvariable der Steckdose. Sobald ein Verbrauch über 0,5 Watt gemessen wird, wechselt die Maschine in den Status "Aktiv".
Anschließend sammelt das Modul im Minutentakt den Stromverbrauch. Alle X Minuten (konfigurierbar) wird die bisher gesammelte Verbrauchskurve an die Google Gemini KI geschickt. 
Die KI analysiert das Stromprofil, erkennt die aktuelle Phase (z. B. "Aufheizen", "Hauptwäsche", "Trocknen") und entscheidet intelligent, wann das Spülprogramm vollständig beendet ist ("Fertig").

## Konfiguration

1. **Leistung (Watt) Variable**: Wählen Sie die Variable Ihrer Messsteckdose aus.
2. **Gemini API-Schlüssel**: Tragen Sie hier Ihren Google Gemini API Key ein.
3. **Gemini Modell**: Wählen Sie das zu verwendende Modell (z.B. gemini-3.5-flash).
4. **Analyse Intervall**: Bestimmen Sie, alle wie viele Minuten das Stromprofil zur Analyse an die KI gesendet werden soll (Empfehlung: 10 Minuten).

## Bedienung

Die Variable "Status" zeigt an, ob die Maschine "Aus", "Aktiv" oder "Fertig" ist. Sie können den Status über das WebFront auch manuell wieder auf "Aus" schalten (z. B. nachdem Sie die Maschine ausgeräumt haben). Die Variable "Aktuelle Phase" zeigt den letzten von der KI gemeldeten Status an.

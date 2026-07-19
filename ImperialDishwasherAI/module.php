<?php

declare(strict_types=1);

/**
 * ImperialDishwasherAI — KI-gestützte Spülmaschinen-Überwachung.
 * Nutzt SmartGeminiIO für alle Gemini-API-Aufrufe.
 */
class ImperialDishwasherAI extends IPSModuleStrict {
    /** GUID des SmartGeminiIO-Moduls zur Auto-Discovery */
    private const GEMINI_IO_GUID = '{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}';

    public function Create(): void {
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyInteger('PowerVariableID', 0);
        $this->RegisterPropertyInteger('AnalysisInterval', 10); // in Minuten
        $this->RegisterPropertyFloat('StartThreshold', 100.0);

        // Variablen
        $vid = @$this->GetIDForIdent('Status');
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== 3) {
            $this->UnregisterVariable('Status');
        }
        $this->RegisterVariableString('Status', 'Status', '', 1);
        IPS_SetIcon($this->GetIDForIdent('Status'), 'Information');

        $this->RegisterVariableString('CurrentPhase', 'Aktuelle Phase', '', 2);
        IPS_SetIcon($this->GetIDForIdent('CurrentPhase'), 'Script');

        $this->RegisterVariableInteger('ActiveSince', 'Aktiv Seit', '', 3);
        IPS_SetIcon($this->GetIDForIdent('ActiveSince'), 'Clock');

        $this->RegisterVariableString('LastGeminiPrompt', 'Letzter KI Prompt', '', 4);
        IPS_SetIcon($this->GetIDForIdent('LastGeminiPrompt'), 'Information');

        $this->RegisterVariableString('LastGeminiResponse', 'Letzte KI Antwort', '', 5);
        IPS_SetIcon($this->GetIDForIdent('LastGeminiResponse'), 'Information');

        $this->RegisterVariableInteger('RemainingTime', 'Restlaufzeit', '', 6);
        IPS_SetIcon($this->GetIDForIdent('RemainingTime'), 'Clock');

        $this->RegisterVariableInteger('ExpectedEnd', 'Erwartetes Ende', '', 7);
        IPS_SetIcon($this->GetIDForIdent('ExpectedEnd'), 'Clock');

        $this->RegisterVariableInteger('Progress', 'Fortschritt', '', 8);
        IPS_SetIcon($this->GetIDForIdent('Progress'), 'Motion');

        // Timer
        $this->RegisterTimer('DataCollectorTimer', 0, 'IDW_CollectData($_IPS[\'TARGET\']);');
        $this->RegisterTimer('AnalysisTimer', 0, 'IDW_AnalyzeData($_IPS[\'TARGET\']);');

        $this->RegisterVariableString('SessionData', 'Session Data (Intern)', '', 99);
        IPS_SetHidden($this->GetIDForIdent('SessionData'), true);

        $this->RegisterVariableString('LastSessionData', 'Letzte Session Data (Intern)', '', 100);
        IPS_SetHidden($this->GetIDForIdent('LastSessionData'), true);

        // Vestaboard: Kurzzusammenfassung für VestaboardGenerator
        $this->RegisterVariableString('VestaboardMessage', 'Vestaboard Nachricht', '', 101);
        IPS_SetIcon($this->GetIDForIdent('VestaboardMessage'), 'Script');
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();

        $this->DisableAction('Status');

        $powerVarID = $this->ReadPropertyInteger('PowerVariableID');
        if ($powerVarID > 1 && @IPS_ObjectExists($powerVarID)) {
            $this->RegisterReference($powerVarID);
            $this->RegisterMessage($powerVarID, VM_UPDATE);
        }

        // Custom Presentation (IPS 8) für Datumsanzeige
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ActiveSince'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'         => 'Clock'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ExpectedEnd'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'         => 'Clock'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('RemainingTime'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' Sek'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Progress'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'SUFFIX'       => '%',
            'MIN'          => 0,
            'MAX'          => 100
        ]);

        $this->MaintainTimer();
    }

    public function RequestAction(string $Ident, $Value): void {
        if ($Ident === 'Status') {
            if ($Value === 'Aus') {
                $this->SetValue('Status', 'Aus');
                $this->SetValue('CurrentPhase', 'Aus');
                $this->SetValue('RemainingTime', 0);
                $this->SetValue('ExpectedEnd', 0);
                $this->SetValue('Progress', 0);
                $this->SetValue('SessionData', '[]');
                $this->SetValue('VestaboardMessage', '');
                $this->MaintainTimer();
            } else {
                $this->SetValue('Status', $Value);
                $this->MaintainTimer();
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {
        if ($Message == VM_UPDATE) {
            $powerVarID = $this->ReadPropertyInteger('PowerVariableID');
            if ($SenderID == $powerVarID) {
                $power     = $Data[0];
                $status    = $this->GetValue('Status');
                $threshold = $this->ReadPropertyFloat('StartThreshold');

                if ($power > $threshold && ($status === 'Aus' || $status === '' || $status === 'Fertig')) {
                    $this->SetValue('Status', 'Start');
                    $this->SetValue('VestaboardMessage', 'Spülmaschine gestartet…');
                    $this->SetValue('ActiveSince', time());
                    $this->SetValue('CurrentPhase', 'Gestartet');
                    $this->SetValue('RemainingTime', 0);
                    $this->SetValue('ExpectedEnd', 0);
                    $this->SetValue('Progress', 0);
                    $this->SetValue('SessionData', '[]');
                    IPS_LogMessage('ImperialDishwasherAI', 'Spülmaschine hat gestartet.');
                    $this->MaintainTimer();
                }
            }
        }
    }

    private function MaintainTimer(): void {
        $status = $this->GetValue('Status');
        if ($status === 'Start' || $status === 'Aktiv') {
            $this->SetTimerInterval('DataCollectorTimer', 60000);
            $interval = $this->ReadPropertyInteger('AnalysisInterval');
            $this->SetTimerInterval('AnalysisTimer', $interval * 60000);
        } else {
            $this->SetTimerInterval('DataCollectorTimer', 0);
            $this->SetTimerInterval('AnalysisTimer', 0);
        }
    }

    public function CollectData(): void {
        $powerVarID = $this->ReadPropertyInteger('PowerVariableID');
        if ($powerVarID == 0 || !IPS_VariableExists($powerVarID)) return;

        $power = round(GetValue($powerVarID));

        $sessionDataStr = $this->GetValue('SessionData');
        $sessionData = json_decode($sessionDataStr, true);
        if (!is_array($sessionData)) $sessionData = [];

        $sessionData[] = $power;
        $this->SetValue('SessionData', json_encode($sessionData));
    }

    public function AnalyzeData(): void {
        // SmartGeminiIO auto-discover
        $geminiInstances = IPS_GetInstanceListByModuleID(self::GEMINI_IO_GUID);
        if (empty($geminiInstances)) {
            IPS_LogMessage('ImperialDishwasherAI', 'SmartGeminiIO Instanz nicht gefunden! Bitte eine erstellen.');
            return;
        }
        $geminiId = $geminiInstances[0];

        $sessionDataStr = $this->GetValue('SessionData');
        $sessionData    = json_decode($sessionDataStr, true);
        if (!is_array($sessionData) || count($sessionData) == 0) return;

        // Maximal 300 Punkte (Context Limit)
        if (count($sessionData) > 300) {
            $sessionData = array_slice($sessionData, -300);
        }

        $dataString = implode(', ', $sessionData);
        $threshold  = $this->ReadPropertyFloat('StartThreshold');

        $systemInstruction = 'Du antwortest ausschließlich im JSON-Format.';

        $userPrompt = "Du bist eine KI zur Analyse des Stromverbrauchs von Haushaltsgeräten.\n";
        $userPrompt .= "Dies ist der Stromverbrauch (in Watt) einer Imperial GSI 8265 BS Spülmaschine.\n";

        $lastSessionDataStr = $this->GetValue('LastSessionData');
        $lastSessionData    = json_decode($lastSessionDataStr, true);
        if (is_array($lastSessionData) && count($lastSessionData) > 0) {
            $lastDuration   = count($lastSessionData);
            $lastDataString = implode(', ', $lastSessionData);
            $userPrompt .= "Als Referenz: Hier ist der komplette Stromverlauf des zuletzt durchgelaufenen Waschvorgangs (Dauer: $lastDuration Minuten):\n";
            $userPrompt .= "[$lastDataString]\n\n";
            $userPrompt .= "Nutze diese Referenzkurve, um besser abzuschätzen, in welcher Phase sich das aktuelle Programm befindet.\n\n";
        }

        $userPrompt .= "Daten des AKTUELLEN Programms (Minutentakt seit Start):\n[$dataString]\n\n";
        $userPrompt .= "HINWEIS STANDBY: Werte unter {$threshold}W sind der Standby-Verbrauch (ausgeschaltete Maschine).\n";
        $userPrompt .= "Deine Aufgabe:\n";
        $userPrompt .= "1. Bestimme die aktuelle Phase (z.B. 'Aufheizen', 'Hauptwäsche', 'Trocknen', 'Fertig').\n";
        $userPrompt .= "2. Entscheide ob das Programm fertig ist (isFinished: true).\n";
        $userPrompt .= "3. Schätze die verbleibende Restlaufzeit in Minuten.\n";

        $responseSchema = json_encode([
            'type'       => 'OBJECT',
            'properties' => [
                'phase'            => ['type' => 'STRING',  'description' => 'Aktuelle Phase des Spülvorgangs'],
                'isFinished'       => ['type' => 'BOOLEAN', 'description' => 'true wenn komplett fertig'],
                'remainingMinutes' => ['type' => 'INTEGER', 'description' => 'Geschätzte Restlaufzeit in Minuten (0 wenn fertig)']
            ],
            'required' => ['phase', 'isFinished', 'remainingMinutes']
        ]);

        $this->SetValue('LastGeminiPrompt', $userPrompt);

        $instanceId = $this->InstanceID;

        // Async via IPS_RunScriptText — GIO_Query blockiert, daher in Background
        $script = '<?php
            $result = GIO_Query(' . $geminiId . ',
                ' . var_export($userPrompt, true) . ',
                ' . var_export($systemInstruction, true) . ',
                ' . var_export($responseSchema, true) . '
            );
            IDW_ProcessGeminiResult(' . $instanceId . ', $result);
        ';
        IPS_RunScriptText($script);
    }

    /**
     * Verarbeitet das Ergebnis der Gemini-Analyse.
     * Wird aus dem Background-Script via IPS_RunScriptText aufgerufen.
     *
     * @param string $jsonText Bereits extrahierter JSON-Text von GIO_Query
     */
    public function ProcessGeminiResult(string $jsonText): void {
        $this->SetValue('LastGeminiResponse', $jsonText);

        if (empty($jsonText)) {
            IPS_LogMessage('ImperialDishwasherAI', 'Gemini-Analyse fehlgeschlagen (leere Antwort von SmartGeminiIO).');
            return;
        }

        $parsed = json_decode($jsonText, true);
        if (!is_array($parsed) || !isset($parsed['phase'])) {
            IPS_LogMessage('ImperialDishwasherAI', 'Gemini-Antwort konnte nicht geparst werden: ' . $jsonText);
            return;
        }

        $this->SetValue('CurrentPhase', $parsed['phase']);

        if (isset($parsed['remainingMinutes'])) {
            $remMin = (int)$parsed['remainingMinutes'];
            $remSec = $remMin * 60;
            $this->SetValue('RemainingTime', $remSec);

            // Vestaboard: Kurz-Status mit Restzeit
            if ($remMin > 0) {
                $this->SetValue('VestaboardMessage', 'Spülmaschine: ' . $parsed['phase'] . ' (~' . $remMin . ' min)');
            }

            if ($remSec > 0) {
                $expectedEnd = time() + $remSec;
                $this->SetValue('ExpectedEnd', $expectedEnd);

                $activeSince = $this->GetValue('ActiveSince');
                $total       = $expectedEnd - $activeSince;
                if ($total > 0) {
                    $progress = (int)(((time() - $activeSince) / $total) * 100);
                    $this->SetValue('Progress', min(100, max(0, $progress)));
                }
            } else {
                $this->SetValue('Progress', 100);
                $this->SetValue('ExpectedEnd', time());
            }
        }

        if (isset($parsed['isFinished']) && $parsed['isFinished'] == true) {
            $this->SetValue('Status', 'Fertig');
            $this->SetValue('Progress', 0);
            $this->SetValue('VestaboardMessage', 'Spülmaschine fertig! Bitte ausräumen.');

            // Komplette Kurve für nächsten Durchlauf speichern
            $this->SetValue('LastSessionData', $this->GetValue('SessionData'));
            $this->MaintainTimer();
            IPS_LogMessage('ImperialDishwasherAI', 'Gemini meldet: Spülmaschine ist fertig.');
        } else {
            IPS_LogMessage('ImperialDishwasherAI', 'Gemini Phase: ' . $parsed['phase']);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'ImperialDishwasherAI: ' . $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "ImperialDishwasherAI — KI-gestützte Spülmaschinen-Überwachung\n\nDer API-Key wird zentral über die SmartGeminiIO Instanz konfiguriert.\nBitte zuerst eine SmartGeminiIO Instanz erstellen und dort den API-Key eintragen."
        },
        {
            "type": "SelectVariable",
            "name": "PowerVariableID",
            "caption": "Leistungsmessung Spülmaschine (Watt)"
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "StartThreshold",
                    "caption": "Einschaltschwelle (Watt)",
                    "digits": 1
                },
                {
                    "type": "NumberSpinner",
                    "name": "AnalysisInterval",
                    "caption": "KI-Analyse Intervall (Minuten)",
                    "minimum": 5,
                    "maximum": 60
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "📊 Jetzt analysieren",
            "onClick": "IDW_AnalyzeData($id);"
        },
        {
            "type": "Button",
            "label": "📋 Daten sammeln",
            "onClick": "IDW_CollectData($id);"
        }
    ],
    "status": [
        {"code": 102, "icon": "active",   "caption": "Überwachung aktiv."},
        {"code": 104, "icon": "inactive", "caption": "Inaktiv."}
    ]
}
EOT;
    }
}

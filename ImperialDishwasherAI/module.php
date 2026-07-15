<?php

declare(strict_types=1);

class ImperialDishwasherAI extends IPSModuleStrict {
    public function Create(): void {
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyInteger('PowerVariableID', 0);
        $this->RegisterPropertyString('GeminiApiKey', '');
        $this->RegisterPropertyString('GeminiModel', 'gemini-3.5-flash');
        $this->RegisterPropertyInteger('AnalysisInterval', 10); // in Minuten

        // Variablen
        $this->RegisterVariableInteger('Status', 'Status', 'IDW.Status', 1);
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
            'PRESENTATION'=> VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'=> 'Clock'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ExpectedEnd'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'=> 'Clock'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('RemainingTime'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'=> ' Sek'
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Progress'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SLIDER,
            'SUFFIX'=> '%',
            'MIN'=> 0,
            'MAX'=> 100
        ]);
        
        $this->MaintainTimer();
    }

    public function RequestAction(string $Ident, $Value): void {
        if ($Ident === 'Status') {
            if ($Value == 0) {
                // Manuell auf Aus setzen, beendet den aktuellen Durchlauf
                $this->SetValue('Status', 0);
                $this->SetValue('CurrentPhase', 'Aus');
                $this->SetValue('RemainingTime', 0);
                $this->SetValue('ExpectedEnd', 0);
                $this->SetValue('Progress', 0);
                $this->SetValue('SessionData', '[]');
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
                $power = $Data[0];
                $status = $this->GetValue('Status');
                
                // Wenn Strom > 0.5W und Maschine war aus, starte den Vorgang
                if ($power > 0.5 && $status == 0) {
                    $this->SetValue('Status', 1); // Aktiv
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
        if ($status == 1) { // Aktiv
            $this->SetTimerInterval('DataCollectorTimer', 60000); // Jede Minute
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
        
        $power = GetValue($powerVarID);
        
        $sessionDataStr = $this->GetValue('SessionData');
        $sessionData = json_decode($sessionDataStr, true);
        if (!is_array($sessionData)) $sessionData = [];
        
        $sessionData[] = $power;
        $this->SetValue('SessionData', json_encode($sessionData));
    }

    public function AnalyzeData(): void {
        $apiKey = trim($this->ReadPropertyString('GeminiApiKey'));
        $model = trim($this->ReadPropertyString('GeminiModel'));
        
        if (empty($apiKey)) {
            IPS_LogMessage('ImperialDishwasherAI', 'Kein Gemini API Key hinterlegt.');
            return;
        }

        $sessionDataStr = $this->GetValue('SessionData');
        $sessionData = json_decode($sessionDataStr, true);
        if (!is_array($sessionData) || count($sessionData) == 0) {
            return; // Keine Daten
        }

        // Wir reduzieren die Daten auf maximal 300 Punkte, um Context Limits zu schonen
        if (count($sessionData) > 300) {
            $sessionData = array_slice($sessionData, -300);
        }

        $dataString = implode(', ', $sessionData);
        
        $systemInstruction = "Du antwortest ausschließlich im JSON-Format.";
        
        $userPrompt = "Du bist eine KI zur Analyse des Stromverbrauchs von Haushaltsgeräten.\n";
        $userPrompt .= "Dies ist der Stromverbrauch (in Watt) einer Imperial GSI 8265 BS Spülmaschine.\n";
        $userPrompt .= "Die Daten wurden im Minutentakt seit dem Start des Programms aufgezeichnet:\n";
        $userPrompt .= "[" . $dataString . "]\n\n";
        $userPrompt .= "Deine Aufgabe:\n";
        $userPrompt .= "1. Analysiere die Kurve. Aufheizen benötigt typischerweise viel Strom (über 1000W), Abpumpen oder Einweichen sehr wenig oder gar keinen Strom.\n";
        $userPrompt .= "2. Bestimme die aktuelle Phase des Spülvorgangs (z.B. 'Aufheizen', 'Hauptwäsche', 'Trocknen', 'Abpumpen', 'Fertig').\n";
        $userPrompt .= "3. Entscheide, ob das Programm komplett durchgelaufen und fertig ist (isFinished: true).\n";
        $userPrompt .= "4. Schätze die verbleibende Restlaufzeit in Minuten (remainingMinutes). Wenn fertig, setze auf 0.\n";
        $userPrompt .= "Bitte gib die Antwort als folgendes JSON-Objekt zurück:\n";
        $userPrompt .= "{\n  \"phase\": \"Name der Phase\",\n  \"isFinished\": true/false,\n  \"remainingMinutes\": Zahl\n}";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $apiKey;
        $responseSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'phase' => [
                    'type' => 'STRING',
                    'description' => 'Die erkannte aktuelle Phase'
                ],
                'isFinished' => [
                    'type' => 'BOOLEAN',
                    'description' => 'True wenn der Vorgang komplett abgeschlossen ist'
                ],
                'remainingMinutes' => [
                    'type' => 'INTEGER',
                    'description' => 'Geschätzte Restlaufzeit in Minuten'
                ]
            ],
            'required' => ['phase', 'isFinished', 'remainingMinutes']
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema
            ]
        ];

        $jsonPayload = json_encode($payload);
        $this->SetValue('LastGeminiPrompt', $userPrompt);

        $script = '<?php
            $ch = curl_init("' . $url . '");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ' . var_export($jsonPayload, true) . ');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            IDW_ProcessGeminiResponse(' . $this->InstanceID . ', $result, $httpCode);
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiResponse(string $result, int $httpCode): void {
        $this->SetValue('LastGeminiResponse', $result);
        if ($httpCode === 200 && $result) {
            $data = json_decode($result, true);
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $jsonText = $data['candidates'][0]['content']['parts'][0]['text'];
                $parsed = json_decode($jsonText, true);
                if (is_array($parsed) && isset($parsed['phase'])) {
                    
                    $this->SetValue('CurrentPhase', $parsed['phase']);
                    
                    if (isset($parsed['remainingMinutes'])) {
                        $remMin = (int)$parsed['remainingMinutes'];
                        $remSec = $remMin * 60;
                        $this->SetValue('RemainingTime', $remSec);
                        
                        if ($remSec > 0) {
                            $expectedEnd = time() + $remSec;
                            $this->SetValue('ExpectedEnd', $expectedEnd);
                            
                            $activeSince = $this->GetValue('ActiveSince');
                            $total = $expectedEnd - $activeSince;
                            if ($total > 0) {
                                $progress = (int)(((time() - $activeSince) / $total) * 100);
                                $progress = min(100, max(0, $progress));
                                $this->SetValue('Progress', $progress);
                            }
                        } else {
                            $this->SetValue('Progress', 100);
                            $this->SetValue('ExpectedEnd', time());
                        }
                    }
                    
                    if (isset($parsed['isFinished']) && $parsed['isFinished'] == true) {
                        $this->SetValue('Status', 2); // Fertig
                        $this->MaintainTimer();
                        IPS_LogMessage('ImperialDishwasherAI', 'Gemini meldet: Spülmaschine ist fertig.');
                    } else {
                        IPS_LogMessage('ImperialDishwasherAI', 'Gemini Phase: ' . $parsed['phase']);
                    }
                    return;
                }
            }
        }
        
        IPS_LogMessage('ImperialDishwasherAI', 'Fehler bei der Gemini-Analyse. HTTP Code: ' . $httpCode);
    }
}

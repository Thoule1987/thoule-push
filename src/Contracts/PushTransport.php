<?php

namespace Thoule\Push\Contracts;

use Thoule\Push\Models\PushAbo;
use Thoule\Push\PushErgebnis;

/**
 * Der eigentliche Web-Push-Versand.
 *
 * **Warum ein Contract.** Die tatsächliche Zustellung läuft über FCM, Mozilla
 * Autopush bzw. APNs und ist ohne echte Geräte mit erteilter Berechtigung nicht
 * automatisiert prüfbar. Ohne austauschbaren Transport bliebe damit die gesamte
 * Versandlogik – wen beliefern, was bei 410, was bei 500 – ungetestet, und genau
 * dort sitzen die Fehler, die man im Betrieb nicht sieht.
 */
interface PushTransport
{
    /**
     * Schickt eine Nutzlast an genau ein Abo.
     *
     * Wirft nicht: Ein toter Endpunkt ist Normalbetrieb, kein Ausnahmefall. Die
     * Unterscheidung „endgültig tot" gegen „gerade gestört" trägt `PushErgebnis`.
     *
     * @param  array<string, mixed>  $nutzlast
     */
    public function senden(PushAbo $abo, array $nutzlast): PushErgebnis;

    /**
     * Schickt dieselbe Nutzlast an viele Abos – wenn möglich in einem Rutsch.
     *
     * @param  iterable<PushAbo>  $abos
     * @param  array<string, mixed>  $nutzlast
     * @return array<string, PushErgebnis> Schlüssel = Abo-ID
     */
    public function sendenViele(iterable $abos, array $nutzlast): array;
}

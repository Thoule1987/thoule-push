<?php

namespace Thoule\Push\Console;

use Illuminate\Console\Command;
use Thoule\Push\Models\PushAbo;

/**
 * Entfernt Abos, die endgültig nichts mehr nützen.
 *
 * Zwei Gruppen, aus zwei verschiedenen Gründen:
 *
 * 1. **Abgelaufene** (404/410). Sie bleiben zunächst als Markierung stehen, damit
 *    `PushAbo::registrieren()` denselben toten Endpunkt nicht wieder aufleben lässt.
 *    Diese Schutzwirkung braucht es nur, solange der Browser das Abonnement noch
 *    vorhält – danach ist die Zeile ein personenbezogenes Datum ohne Zweck.
 * 2. **Verwaiste**: nie abgelaufen, aber seit langem nicht mehr aktualisiert. Ein
 *    Gerät, das seit Monaten nicht mehr da war, kommt meist nicht zurück, und
 *    Endpunkt samt Schlüsseln liegen bis dahin ohne Nutzen herum (Art. 5 Abs. 1
 *    lit. e DSGVO).
 */
class PushAbosAufraeumenCommand extends Command
{
    protected $signature = 'push:abos-aufraeumen';

    protected $description = 'Entfernt abgelaufene und verwaiste Push-Abos';

    public function handle(): int
    {
        $ablaufTage = (int) config('push.aufbewahrung.abgelaufen_tage', 7);
        $verwaistTage = (int) config('push.aufbewahrung.verwaist_tage', 365);

        // Eine 0 oder ein negativer Wert würde auch die Markierung von eben löschen und
        // damit genau den Schutz aushebeln, für den sie existiert. Lieber abbrechen als
        // still das Gegenteil tun.
        if ($ablaufTage < 1 || $verwaistTage < 1) {
            $this->error('Aufbewahrungsfristen müssen mindestens 1 Tag betragen – nichts gelöscht.');

            return self::FAILURE;
        }

        $abgelaufen = PushAbo::query()
            ->whereNotNull('abgelaufen_at')
            ->where('abgelaufen_at', '<', now()->subDays($ablaufTage))
            ->delete();

        $verwaist = PushAbo::query()
            ->whereNull('abgelaufen_at')
            ->where('updated_at', '<', now()->subDays($verwaistTage))
            ->delete();

        $this->info("Push-Abos aufgeräumt: {$abgelaufen} abgelaufen, {$verwaist} verwaist.");

        return self::SUCCESS;
    }
}

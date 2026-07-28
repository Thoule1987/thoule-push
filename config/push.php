<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID-Schlüsselpaar
    |--------------------------------------------------------------------------
    |
    | Identifiziert den Absender gegenüber den Push-Diensten (FCM, Mozilla
    | Autopush, APNs). Der öffentliche Schlüssel geht ans Frontend, der private
    | NIE – er darf weder in einer Ausgabe noch in einem Log oder Ereignis
    | auftauchen. `subject` ist eine mailto:- oder https:-Adresse, unter der die
    | Push-Dienste den Absender erreichen.
    |
    | Je Umgebung ein eigenes Paar: Wird es getauscht, sind alle bestehenden Abos
    | ungültig, und die Nutzer müssen erneut zustimmen.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zeitlimit je Zustellversuch (Sekunden)
    |--------------------------------------------------------------------------
    |
    | Der Versand läuft synchron im Scheduler-Prozess, weil auf dem
    | Shared-Hosting kein dauerhafter Queue-Worker läuft. Ein hängender
    | Push-Dienst darf deshalb nicht den ganzen Lauf blockieren.
    |
    */

    'timeout' => (int) env('PUSH_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrung (DSGVO)
    |--------------------------------------------------------------------------
    |
    | Endpunkt und Schlüssel sind pseudonyme personenbezogene Daten. Abgelaufene
    | Abos bleiben nur so lange stehen, wie die Markierung eine Wiederauferstehung
    | verhindern muss; verwaiste verschwinden, wenn das Gerät lange nicht mehr da war.
    |
    */

    'aufbewahrung' => [
        'abgelaufen_tage' => (int) env('PUSH_ABGELAUFEN_TAGE', 7),
        'verwaist_tage' => (int) env('PUSH_VERWAIST_TAGE', 365),
    ],

];

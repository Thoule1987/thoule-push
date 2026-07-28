<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web-Push-Abonnements je Browser/Gerät.
 *
 * **DSGVO:** Endpunkt und Schlüssel sind pseudonyme personenbezogene Daten. Sie
 * werden bei Abmeldung gelöscht, bei endgültigem Ablauf markiert und vom
 * Aufräum-Kommando entfernt, und hängen per Kaskade am Konto.
 *
 * **Der Besitzer ist polymorph und optional.** Die drei Thoule-Apps binden Abos
 * an unterschiedliche Modelle (Konto, Verkäufer) – und eine erlaubt bewusst
 * anonyme Abos, weil ihr Besucher-Frontend ohne Anmeldung nutzbar ist. Ein
 * fester, nicht-nullbarer Fremdschlüssel auf `users` hätte das ausgeschlossen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_abos', function (Blueprint $tabelle) {
            $tabelle->uuid('id')->primary();
            $tabelle->nullableMorphs('abonnent');

            // Der Push-Endpunkt ist geräteweit eindeutig. 500 Zeichen, weil er als
            // `text` nicht indizierbar wäre – und ohne Unique-Index legt jede
            // Neuregistrierung desselben Browsers eine weitere Zeile an, die dann
            // dieselbe Nachricht ein zweites Mal zustellt.
            $tabelle->string('endpoint', 500)->unique();
            $tabelle->string('public_key');
            $tabelle->string('auth_token');

            // Gesetzt, sobald der Push-Dienst das Abo mit 404/410 verwirft.
            // Markieren statt löschen: s. `PushAbo::alsAbgelaufenMarkieren()`.
            $tabelle->timestamp('abgelaufen_at')->nullable()->index();
            $tabelle->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_abos');
    }
};

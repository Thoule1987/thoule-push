<?php

namespace Thoule\Push\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Ein Web-Push-Abonnement (ein Browser auf einem Gerät).
 *
 * @property string $id
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property Carbon|null $abgelaufen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PushAbo extends Model
{
    use HasUuids;

    protected $table = 'push_abos';

    protected $fillable = [
        'abonnent_type',
        'abonnent_id',
        'endpoint',
        'public_key',
        'auth_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['abgelaufen_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function abonnent(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  Builder<PushAbo>  $abfrage */
    public function scopeAktiv(Builder $abfrage): void
    {
        $abfrage->whereNull('abgelaufen_at');
    }

    /**
     * Nimmt ein vom Browser geliefertes Abonnement entgegen.
     *
     * Gibt `null` zurück, wenn genau dieser Endpunkt bereits als abgelaufen
     * markiert war. **Das ist der Kern der Ablaufbehandlung**, kein Randfall:
     * Nach einem 410 liefert der Browser – Firefox besonders zuverlässig –
     * beim nächsten Seitenaufruf weiterhin dasselbe, längst verworfene
     * Abonnement zurück. Ein gewöhnliches `updateOrCreate` liesse den gerade
     * für tot erklärten Endpunkt Sekunden später wieder als gültig erscheinen,
     * und der nächste Versand scheitert erneut mit 410 – in einer der
     * Thoule-Apps real über anderthalb Stunden bei jedem Cron-Lauf.
     *
     * Der aufrufende Controller beantwortet ein `null` mit HTTP 410, damit der
     * Browser sein Abonnement wirklich neu erzeugt statt es nur erneut zu melden.
     */
    public static function registrieren(
        string $endpoint,
        string $publicKey,
        string $authToken,
        ?Model $abonnent = null,
    ): ?self {
        $bestehend = static::query()->where('endpoint', $endpoint)->first();

        if ($bestehend?->abgelaufen_at !== null) {
            // Innerhalb dieses Zweigs ist $bestehend garantiert nicht null:
            // `null?->abgelaufen_at` waere null, und `null !== null` ist falsch.
            $bestehend->delete();

            return null;
        }

        return static::query()->updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'abonnent_type' => $abonnent?->getMorphClass(),
                'abonnent_id' => $abonnent?->getKey(),
            ],
        );
    }

    /**
     * Markiert das Abo als endgültig verworfen, statt es zu löschen.
     *
     * **Warum nicht löschen** – obwohl zwei der drei Thoule-Apps genau das taten:
     * Die Zeile ist nach dem Löschen der einzige Beleg dafür, dass dieser
     * Endpunkt tot ist. Ohne sie kann `registrieren()` den Unterschied zwischen
     * „neuer Browser" und „derselbe tote Endpunkt schon wieder" nicht erkennen.
     * Endgültig entfernt wird sie vom Aufräum-Kommando.
     */
    public function alsAbgelaufenMarkieren(): void
    {
        $this->forceFill(['abgelaufen_at' => now()])->save();
    }

    public function istAbgelaufen(): bool
    {
        return $this->abgelaufen_at !== null;
    }
}

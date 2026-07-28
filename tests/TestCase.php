<?php

namespace Thoule\Push\Tests;

use Orchestra\Testbench\TestCase as Basis;
use Thoule\Push\PushServiceProvider;

abstract class TestCase extends Basis
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PushServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        // Ein eigens für diese Tests erzeugtes VAPID-Paar – nirgends sonst verwendet,
        // kein Geheimnis. Es steht hier, weil Platzhalter nicht genügen: Die Bibliothek
        // validiert die Schlüssel und leitet mit ihnen ein Shared Secret über der Kurve
        // P-256 ab. Mit erfundenen Werten käme der Transport nie bis zum HTTP-Aufruf –
        // und genau der ist das, was geprüft werden soll.
        $app['config']->set('push.vapid', [
            'subject' => 'mailto:test@thoule.example',
            'public_key' => 'BLZrHh1e1RJFDFgj5Fuf-uoVMwrgmyomXOyk5EHCazyCKp-wxiR7frd_k2Tq_jCDIVDpkoiuJOUgQoV0FV8ZyDU',
            'private_key' => 'N2dqfaAkXzeg-phC5zH5vxyAt9HLo-1YEcEf23c_aLU',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

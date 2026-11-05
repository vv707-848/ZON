<?php

namespace Tests\Feature\Api;

use Database\Seeders\ReferenceSeeder;
use Tests\TestCase;

/**
 * The app in this repository (app/pubspec.yaml) and the version gate the seed opens with: a build of the app as it
 * is today is let in, and is not told that a newer version exists. CheckAppVersion compares with PHP's
 * version_compare; the app sends its `version` in X-App-Version, without the build number.
 */
class AppVersionContractTest extends TestCase
{
    public function test_the_app_as_it_is_passes_the_gate_the_seed_opens_with(): void
    {
        $pubspec = base_path('../app/pubspec.yaml');
        if (! is_file($pubspec)) {
            $this->markTestSkipped('the app is not next to the backend here');
        }
        preg_match('/^version:\s*([0-9]+\.[0-9]+\.[0-9]+)(\+[0-9]+)?\s*$/m', (string) file_get_contents($pubspec), $m);
        $this->assertNotEmpty($m, 'app/pubspec.yaml has a version x.y.z+build');
        $app = $m[1];

        $this->assertTrue(version_compare($app, ReferenceSeeder::FIRST_APP_VERSION, '>='), "the app {$app} is below the seeded minimum");

        $this->getJson('/api/v1/catalog', ['X-App-Platform' => 'ios', 'X-App-Version' => $app])->assertOk();
        $this->getJson('/api/v1/catalog', ['X-App-Platform' => 'android', 'X-App-Version' => $app])->assertOk();
        $latest = $this->getJson('/api/v1/app/config', ['X-App-Platform' => 'android', 'X-App-Version' => $app])
            ->assertOk()->json('data.platform.latest_version');
        $this->assertFalse(version_compare($app, (string) $latest, '<'), 'the app is not offered an update that does not exist');
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The app's recorded contract (docs/contracts/fixtures/api) is compared, not rewritten, under CI's own settings:
 * ZON_CONTRACT_DIR (set by CI to record what goes to the agents) must not turn the comparison into a recording.
 */
class ApiContractTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZON_CONTRACT_DIR');
        putenv('ZON_RECORD_API');
        parent::tearDown();
    }

    public function test_a_renamed_key_fails_with_ci_settings_and_the_recording_stays(): void
    {
        $path = base_path('../docs/contracts/fixtures/api/feed.json');
        $recorded = (string) File::get($path);
        putenv('ZON_CONTRACT_DIR='.sys_get_temp_dir());                 // as in .github/workflows/platform.yml

        $answer = json_decode($recorded, true);
        $answer['data'][0]['name'] = $answer['data'][0]['title'];       // «title» renamed
        unset($answer['data'][0]['title']);

        try {
            $this->apiContract('feed', TestResponse::fromBaseResponse(new JsonResponse($answer)));
            $this->fail('a renamed key passed the contract');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('title', $e->getMessage());
        }
        $this->assertSame($recorded, (string) File::get($path), 'the recording was rewritten');
    }

    public function test_the_same_shape_passes(): void
    {
        putenv('ZON_CONTRACT_DIR='.sys_get_temp_dir());
        $this->apiContract('feed', TestResponse::fromBaseResponse(new JsonResponse(json_decode((string) File::get(base_path('../docs/contracts/fixtures/api/feed.json')), true))));
    }
}

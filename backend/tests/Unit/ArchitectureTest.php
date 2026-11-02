<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Rules of the code layout that keep failures easy to find. */
class ArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * Masking, quotas, the 20 s cut-off, fallbacks and ai_requests all live in AiGateway:
     * a class calling the agents client directly would skip them.
     */
    public function test_only_the_ai_gateway_calls_the_agents_client(): void
    {
        $callers = [];
        foreach ($this->phpFiles('app') as $path => $code) {
            if (str_contains($code, 'AgentsClient') && ! str_ends_with($path, 'app/Ai/AgentsClient.php')) {
                $callers[] = $path;
            }
        }

        $this->assertSame(['app/Ai/AiGateway.php'], $callers);
    }

    /**
     * Every road to the AI node, by what makes it safe: AgentsClient (behind AiGateway's masking),
     * PushIndexEvent (payloads from IndexPayloads, which masks customer text), the image hasher (image bytes
     * only) and the health check (GET /agents/health). A new caller must be added here on purpose.
     */
    public function test_only_known_classes_reach_the_ai_node(): void
    {
        $callers = array_keys(array_filter($this->phpFiles('app'), fn (string $code) => str_contains($code, "config('zon.agents.url')")));

        $this->assertSame([
            'app/Ai/AgentsClient.php',
            'app/Integrations/ImageHash/AgentsImageHasher.php',
            'app/Jobs/PushIndexEvent.php',
            'app/Support/Health/SystemHealth.php',
        ], $callers);
    }

    /** A job written for the schedule but never put on it would silently never run. */
    public function test_every_scheduled_job_is_on_the_schedule(): void
    {
        $schedule = (string) file_get_contents(self::ROOT.'/routes/console.php');

        foreach (array_keys($this->phpFiles('app/Jobs/Scheduled')) as $path) {
            $job = basename($path, '.php');
            $this->assertStringContainsString("Schedule::job(new {$job})", $schedule, "{$job} is not scheduled in routes/console.php");
        }
    }

    /** @return array<string, string> path relative to the project => code */
    private function phpFiles(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/'.$directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $directory.substr($file->getPathname(), strlen(self::ROOT.'/'.$directory));
                $files[$path] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}

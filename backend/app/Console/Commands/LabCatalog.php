<?php

namespace App\Console\Commands;

use App\SuperAgent\Proposal;
use App\SuperAgent\SuperAgent;
use Illuminate\Console\Command;

/**
 * `php artisan zon:lab-catalog` — writes what the SuperAgent's agents receive from Laravel to the model lab: the
 * report catalog (report_select, report_explore) and the proposals (report_write), so the lab measures the choices
 * against the very lists Laravel sends. Run it after adding or changing a template or a proposal; LabCatalogTest
 * fails until then.
 */
class LabCatalog extends Command
{
    public const DIR = 'agents/zon_agents/lab/cases';

    protected $signature = 'zon:lab-catalog';

    protected $description = "Writes the SuperAgent's report catalog and proposals to the model lab";

    public function handle(): int
    {
        foreach (self::files() as $name => $json) {
            file_put_contents(dirname(base_path()).'/'.self::DIR.'/'.$name, $json);
        }
        $this->info(count(SuperAgent::catalog()).' reports, '.count(Proposal::cases()).' proposals → '.self::DIR);

        return self::SUCCESS;
    }

    /** @return array<string, string> file name => its content, as Laravel sends it (with the seeded reference cities) */
    public static function files(): array
    {
        $json = fn (array $list) => json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";

        return ['catalog.json' => $json(SuperAgent::catalog()), 'proposals.json' => $json(Proposal::catalog())];
    }
}

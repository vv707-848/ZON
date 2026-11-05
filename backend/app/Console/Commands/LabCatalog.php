<?php

namespace App\Console\Commands;

use App\SuperAgent\Catalog;
use App\SuperAgent\Template;
use Illuminate\Console\Command;

/**
 * `php artisan zon:lab-catalog` — writes the SuperAgent's catalog, as report_select receives it, to the model lab
 * (agents/zon_agents/lab/cases/catalog.json): the lab measures the choice of a report against the very catalog
 * Laravel sends. Run it after adding or changing a template; LabCatalogTest fails until then.
 */
class LabCatalog extends Command
{
    public const FILE = 'agents/zon_agents/lab/cases/catalog.json';

    protected $signature = 'zon:lab-catalog';

    protected $description = "Writes the SuperAgent's report catalog to the model lab";

    public function handle(): int
    {
        file_put_contents(dirname(base_path()).'/'.self::FILE, self::json());
        $this->info(count(Catalog::all()).' reports → '.self::FILE);

        return self::SUCCESS;
    }

    /** The catalog exactly as SuperAgent::ask sends it (with the reference cities of the seeded database). */
    public static function json(): string
    {
        $catalog = array_values(array_map(fn (Template $t) => $t->catalogEntry(), Catalog::all()));

        return json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    }
}

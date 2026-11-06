<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\LabCatalog;
use Tests\TestCase;

/** The model lab chooses reports and proposals from the lists Laravel really sends: the files never drift apart. */
class LabCatalogTest extends TestCase
{
    public function test_the_labs_catalog_and_proposals_are_the_ones_the_agents_receive(): void
    {
        foreach (LabCatalog::files() as $name => $json) {
            $this->assertSame($json, file_get_contents(dirname(base_path()).'/'.LabCatalog::DIR.'/'.$name),
                "{$name} changed: run `php artisan zon:lab-catalog` and commit it");
        }
    }
}

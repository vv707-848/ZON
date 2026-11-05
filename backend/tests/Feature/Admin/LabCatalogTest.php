<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\LabCatalog;
use Tests\TestCase;

/** The model lab chooses reports from the catalog Laravel really sends: the two files never drift apart. */
class LabCatalogTest extends TestCase
{
    public function test_the_labs_catalog_is_the_one_report_select_receives(): void
    {
        $this->assertSame(LabCatalog::json(), file_get_contents(dirname(base_path()).'/'.LabCatalog::FILE),
            'the SuperAgent catalog changed: run `php artisan zon:lab-catalog` and commit the file');
    }
}

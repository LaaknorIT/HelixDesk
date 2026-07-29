<?php

namespace Tests\Feature;

use App\Models\WorkItem;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_content_seeder_assigns_public_ids_to_work_items(): void
    {
        $this->seed(DemoContentSeeder::class);

        $demoWorkItems = WorkItem::query()
            ->where('title', 'like', 'Demo%')
            ->get();

        $this->assertNotEmpty($demoWorkItems);

        foreach ($demoWorkItems as $workItem) {
            $this->assertNotNull($workItem->public_id);
            $this->assertNotSame('', trim($workItem->public_id));
        }
    }
}

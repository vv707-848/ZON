<?php

namespace Tests\Feature\Api;

use App\Models\ClientNote;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Task;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The broker's desk as the app will do it with a server: a client edited, his notes read and one removed, the
 * client removed; an owner's note removed; the stock listed, read, edited, removed; a task edited, done — twice
 * sent, still done — undone, removed. None of it reachable by another broker. Its answers are the app's recorded
 * contract.
 */
class DeskTest extends TestCase
{
    public function test_a_client_his_notes_and_his_removal(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $client = $this->postJson('/api/v1/clients', ['name' => 'سلمان', 'phone' => '0557776665', 'city' => 'الرياض', 'neighborhoods' => ['النرجس']])->assertCreated()->json('data.id');
        $other = $this->postJson('/api/v1/clients', ['name' => 'خالد', 'phone' => '0551112223', 'city' => 'الرياض'])->assertCreated()->json('data.id');

        $this->apiContract('client_updated', $this->patchJson("/api/v1/clients/{$client}", ['status' => 'closed', 'budget_max' => 1500000, 'property_types' => ['villa']])
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.budget_max', 1500000)->assertJsonPath('data.name', 'سلمان'));
        $this->patchJson("/api/v1/clients/{$client}", ['phone' => '+966 55 111 2223'])->assertStatus(422);   // خالد's, written another way

        $kept = $this->postJson("/api/v1/clients/{$client}/notes", ['category' => 'interested', 'body' => 'مهتم بالفلل في الشمال'])->assertCreated()->json('data.id');
        $gone = $this->postJson("/api/v1/clients/{$client}/notes", ['category' => 'followup', 'body' => 'اتصل بعد العيد'])->assertCreated()->json('data.id');
        $otherNote = $this->postJson("/api/v1/clients/{$other}/notes", ['category' => 'followup', 'body' => 'ملاحظة خالد'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/clients/{$client}/notes/{$otherNote}")->assertNotFound();             // not this client's
        $this->apiContract('client_note_removed', $this->deleteJson("/api/v1/clients/{$client}/notes/{$gone}")->assertOk());
        $this->apiContract('client_notes', $this->getJson("/api/v1/clients/{$client}/notes")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept));

        $this->signIn(Customer::factory()->broker()->create());
        $this->patchJson("/api/v1/clients/{$client}", ['name' => 'غيري'])->assertNotFound();
        $this->getJson("/api/v1/clients/{$client}/notes")->assertNotFound();
        $this->deleteJson("/api/v1/clients/{$client}/notes/{$kept}")->assertNotFound();
        $this->deleteJson("/api/v1/clients/{$client}")->assertNotFound();

        $this->signIn($broker);
        $this->apiContract('client_removed', $this->deleteJson("/api/v1/clients/{$client}")->assertOk());
        $this->getJson("/api/v1/clients/{$client}")->assertNotFound();
        $this->assertSame(1, ClientNote::query()->count(), 'his notes go with him; خالد keeps his');
    }

    public function test_the_apps_reads_and_first_writes_of_the_desk(): void
    {
        $this->signIn(Customer::factory()->broker()->create());
        $created = $this->postJson('/api/v1/clients', ['name' => 'سلمان العتيبي', 'phone' => '0557776665', 'purpose' => 'sale', 'city' => 'الرياض',
            'neighborhoods' => ['النرجس', 'الياسمين'], 'property_types' => ['villa', 'apartment'], 'budget_min' => 1200000, 'budget_max' => 1800000, 'area_min' => 300,
            'rooms_min' => 4, 'description' => 'يبحث عن فيلا قريبة من المدارس'], ['Idempotency-Key' => 'client-0001-abcdef'])->assertCreated()
            ->assertJsonPath('data.phone', '0557776665')->assertJsonPath('data.neighborhoods', ['النرجس', 'الياسمين']);
        $this->apiContract('client_created', $created);
        $id = $created->json('data.id');
        $this->apiContract('client_note_added', $this->postJson("/api/v1/clients/{$id}/notes", ['category' => 'interested', 'body' => 'أعجبته فيلا النرجس'], ['Idempotency-Key' => 'note-0001-abcdef'])
            ->assertCreated()->assertJsonPath('data.category', 'interested'));
        $this->apiContract('clients', $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.notes_count', 1)
            ->assertJsonPath('meta.page.last', 1));
        $this->apiContract('client', $this->getJson("/api/v1/clients/{$id}")->assertOk()->assertJsonPath('data.notes_count', 1));

        $this->apiContract('task_created', $this->postJson('/api/v1/tasks', ['title' => 'اتصال بسلمان', 'body' => 'عن فيلا النرجس', 'priority' => 'high',
            'remind_at' => now()->addDay()->startOfHour()->toIso8601String()], ['Idempotency-Key' => 'task-0001-abcdef'])->assertCreated()->assertJsonPath('data.done', false));
        $this->postJson('/api/v1/tasks', ['title' => 'تجديد رخصة عرض', 'priority' => 'low'])->assertCreated();
        $this->apiContract('tasks', $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.priority', 'high'));
    }

    public function test_the_apps_owners_and_stock_and_its_publishing(): void
    {
        Http::fake(['agents.test/agents/*' => Http::response(['detail' => 'down'], 503)]);
        $this->signIn(Customer::factory()->broker()->create());
        $owner = $this->postJson('/api/v1/property-owners', ['name' => 'عبدالله الدوسري', 'phone' => '0558889990', 'listing_title' => 'فيلا الملقا'])->assertCreated()->json('data.id');
        $this->apiContract('owner_note_added', $this->postJson("/api/v1/property-owners/{$owner}/notes", ['category' => 'rejected', 'body' => 'لا يقبل أقل من مليونين'],
            ['Idempotency-Key' => 'onote-0001-abcdef'])->assertCreated()->assertJsonPath('data.category', 'rejected'));
        $this->apiContract('property_owners', $this->getJson('/api/v1/property-owners')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.notes_count', 1));
        $this->apiContract('owner_notes', $this->getJson("/api/v1/property-owners/{$owner}/notes")->assertOk()->assertJsonCount(1, 'data'));
        $this->apiContract('owner_removed', $this->deleteJson("/api/v1/property-owners/{$owner}")->assertOk());

        $this->apiContract('inventory_created', $this->postJson('/api/v1/inventory', ['purpose' => 'sale', 'property_type' => 'villa', 'title' => 'فيلا في الملقا',
            'city' => 'الرياض', 'neighborhood' => 'الملقا', 'price' => 2400000, 'area_land' => 450, 'area_built' => 520, 'bedrooms' => 6,
            'description' => 'فيلا زاوية بمسبح'], ['Idempotency-Key' => 'stock-0001-abcdef'])->assertCreated()->assertJsonPath('data.published_listing_id', null));
        $item = InventoryItem::query()->sole()->id;
        $this->postJson("/api/v1/inventory/{$item}/publish", ['licence_number' => '7200111222', 'description' => 'قصير'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['description']]]]);                    // what he adds is checked as a listing
        $this->apiContract('inventory_publish_needs', $this->postJson("/api/v1/inventory/{$item}/publish")->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['licence_number']]]]));
        $this->apiContract('inventory_published', $this->postJson("/api/v1/inventory/{$item}/publish", ['licence_number' => '7200111222'])->assertCreated());
        $this->apiContract('inventory_publish_again', $this->postJson("/api/v1/inventory/{$item}/publish", ['licence_number' => '7200111222'])->assertStatus(409));
        $this->getJson("/api/v1/inventory/{$item}")->assertJsonPath('data.published_listing_id', InventoryItem::query()->sole()->published_listing_id);
    }

    public function test_an_owners_note_removed(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $owner = $this->postJson('/api/v1/property-owners', ['name' => 'عبدالله الدوسري', 'phone' => '0558889990'])->assertCreated()->json('data.id');
        $note = $this->postJson("/api/v1/property-owners/{$owner}/notes", ['category' => 'followup', 'body' => 'يبي يبيع قبل رمضان'])->assertCreated()->json('data.id');

        $this->signIn(Customer::factory()->broker()->create());
        $this->deleteJson("/api/v1/property-owners/{$owner}/notes/{$note}")->assertNotFound();

        $this->signIn($broker);
        $this->apiContract('owner_note_removed', $this->deleteJson("/api/v1/property-owners/{$owner}/notes/{$note}")->assertOk());
        $this->getJson("/api/v1/property-owners/{$owner}/notes")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_stock_listed_read_edited_removed(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $item = $this->postJson('/api/v1/inventory', ['purpose' => 'sale', 'property_type' => 'apartment', 'title' => 'شقة من المخزون', 'city' => 'الرياض',
            'neighborhood' => 'الياسمين', 'price' => 750000, 'area_built' => 140, 'bedrooms' => 3, 'description' => 'شقة مخزون جديدة'])->assertCreated()->json('data.id');

        $this->apiContract('inventory', $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $item));
        $this->apiContract('inventory_item', $this->getJson("/api/v1/inventory/{$item}")->assertOk()->assertJsonPath('data.title', 'شقة من المخزون'));
        $this->patchJson("/api/v1/inventory/{$item}", ['neighborhood' => 'حي لا يوجد'])->assertStatus(422);           // checked against its city
        $this->apiContract('inventory_updated', $this->patchJson("/api/v1/inventory/{$item}", ['price' => 720000, 'neighborhood' => 'النرجس'])->assertOk()
            ->assertJsonPath('data.price', 720000)->assertJsonPath('data.neighborhood', 'النرجس')->assertJsonPath('data.title', 'شقة من المخزون'));
        $this->getJson('/api/v1/listings')->assertJsonCount(0, 'data');                                             // stock is never public

        $this->signIn(Customer::factory()->broker()->create());
        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/inventory/{$item}")->assertNotFound();
        $this->patchJson("/api/v1/inventory/{$item}", ['price' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/inventory/{$item}")->assertNotFound();

        $this->signIn($broker);
        $this->apiContract('inventory_removed', $this->deleteJson("/api/v1/inventory/{$item}")->assertOk());
        $this->assertSame(0, InventoryItem::query()->count());
    }

    public function test_a_task_edited_done_once_however_often_sent_undone_removed(): void
    {
        $me = $this->signIn(Customer::factory()->broker()->create());
        $task = $this->postJson('/api/v1/tasks', ['title' => 'زيارة العقار', 'priority' => 'low', 'remind_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('data.id');
        Task::query()->whereKey($task)->update(['reminded_at' => now()]);

        $this->apiContract('task_updated', $this->patchJson("/api/v1/tasks/{$task}", ['title' => 'زيارة العقار مع العميل', 'priority' => 'high', 'remind_at' => now()->addDay()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.priority', 'high')->assertJsonPath('data.title', 'زيارة العقار مع العميل'));
        $this->assertNull(Task::query()->findOrFail($task)->reminded_at, 'a new time reminds again');
        $this->patchJson("/api/v1/tasks/{$task}", ['remind_at' => now()->subHour()->toIso8601String()])->assertStatus(422);

        $this->apiContract('task_done', $this->postJson("/api/v1/tasks/{$task}/done", ['done' => true])->assertOk()->assertJsonPath('data.done', true));
        $this->postJson("/api/v1/tasks/{$task}/done", ['done' => true])->assertOk()->assertJsonPath('data.done', true);   // its answer lost, sent again
        $this->postJson("/api/v1/tasks/{$task}/done", ['done' => false])->assertOk()->assertJsonPath('data.done', false);
        $this->postJson("/api/v1/tasks/{$task}/done")->assertOk()->assertJsonPath('data.done', true);                      // without it, it toggles

        $this->signIn(Customer::factory()->broker()->create());
        $this->patchJson("/api/v1/tasks/{$task}", ['title' => 'غيري'])->assertNotFound();
        $this->postJson("/api/v1/tasks/{$task}/done", ['done' => false])->assertNotFound();
        $this->deleteJson("/api/v1/tasks/{$task}")->assertNotFound();

        $this->signIn($me);
        $this->apiContract('task_removed', $this->deleteJson("/api/v1/tasks/{$task}")->assertOk());
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
    }
}

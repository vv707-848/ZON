<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Support\Api;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** «مهامي» (tasks_view.dart): add · edit · done · delete, with an optional reminder (SendTaskReminders). */
class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tasks = $request->user('api')->tasks()->orderByRaw('done_at IS NOT NULL')->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")->latest()->limit(500)->get();

        return Api::ok($tasks->map(fn (Task $t) => $this->present($t)));
    }

    public function store(Request $request): JsonResponse
    {
        return Api::created($this->present($request->user('api')->tasks()->create($this->validated($request, true))));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        Ownership::assert($task, $request->user('api'));
        $data = $this->validated($request, false);
        if (array_key_exists('remind_at', $data)) {
            $data['reminded_at'] = null;                    // a new time reminds again
        }
        $task->fill($data)->save();

        return Api::ok($this->present($task));
    }

    public function done(Request $request, Task $task): JsonResponse
    {
        Ownership::assert($task, $request->user('api'));
        $task->forceFill(['done_at' => $task->done_at === null ? now() : null])->save();

        return Api::ok($this->present($task));
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        Ownership::assert($task, $request->user('api'));
        $task->delete();

        return Api::ok();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'remind_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Task $task): array
    {
        return ['id' => $task->id, 'title' => $task->title, 'body' => $task->body, 'priority' => ($task->priority ?? TaskPriority::Medium)->value,
            'remind_at' => $task->remind_at?->toIso8601String(), 'done' => $task->done_at !== null, 'done_at' => $task->done_at?->toIso8601String()];
    }
}

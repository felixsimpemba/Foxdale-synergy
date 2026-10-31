<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreTaskCommentRequest;
use App\Http\Requests\Projects\UpdateTaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Flat comments on a task. Posting follows the task update permission (viewers cannot
 * comment); editing and deleting stay with the author.
 */
class TaskCommentController extends Controller
{
    public function store(StoreTaskCommentRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('update', $task);

        $comment = $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        return (new TaskCommentResource($comment->load('user')))->response()->setStatusCode(201);
    }

    public function update(UpdateTaskCommentRequest $request, TaskComment $taskComment): TaskCommentResource
    {
        Gate::authorize('view', $taskComment->task()->firstOrFail());
        abort_unless($taskComment->user_id === $request->user()->id, 403);

        $taskComment->forceFill([
            'body' => $request->validated('body'),
            'edited_at' => now(),
        ])->save();

        return new TaskCommentResource($taskComment->load('user'));
    }

    public function destroy(Request $request, TaskComment $taskComment): Response
    {
        Gate::authorize('view', $taskComment->task()->firstOrFail());
        abort_unless($taskComment->user_id === $request->user()->id, 403);

        $taskComment->delete();

        return response()->noContent();
    }
}

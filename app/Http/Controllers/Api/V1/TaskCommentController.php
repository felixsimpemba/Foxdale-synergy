<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TaskCommentPosted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreTaskCommentRequest;
use App\Http\Requests\Projects\UpdateTaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\Mentions\Mentioner;
use App\Services\Mentions\TaskMentionNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Comments on a task, one level of replies deep. Posting follows the task update permission
 * (viewers cannot comment); editing and deleting stay with the author.
 */
class TaskCommentController extends Controller
{
    public function store(StoreTaskCommentRequest $request, Task $task, Mentioner $mentioner): JsonResponse
    {
        Gate::authorize('update', $task);

        $author = $request->user();
        $body = (string) $request->validated('body');
        $mentioned = $mentioner->mentionedIn($body, $mentioner->mentionableOnTask($task));

        $comment = $task->comments()->create([
            'parent_id' => $request->validated('parent_id'),
            'user_id' => $author->id,
            'body' => $body,
        ]);

        if ($mentioned->isNotEmpty()) {
            $comment->mentions()->sync($mentioned->modelKeys());
        }

        $comment->load('user', 'mentions');
        TaskCommentPosted::dispatch($comment, $author);

        return (new TaskCommentResource($comment))->response()->setStatusCode(201);
    }

    /**
     * Edit the text of your own comment. Only people mentioned for the first time are told.
     */
    public function update(
        UpdateTaskCommentRequest $request,
        TaskComment $taskComment,
        Mentioner $mentioner,
        TaskMentionNotifier $mentionNotifier,
    ): TaskCommentResource {
        $task = $taskComment->task()->firstOrFail();
        Gate::authorize('view', $task);
        abort_unless($taskComment->user_id === $request->user()->id, 403);

        $body = (string) $request->validated('body');
        $previous = $taskComment->mentions()->pluck('users.id');
        $taskComment->forceFill([
            'body' => $body,
            'edited_at' => now(),
        ])->save();

        $mentioned = $mentioner->mentionedIn($body, $mentioner->mentionableOnTask($task));
        $taskComment->mentions()->sync($mentioned->modelKeys());
        $added = $mentioned->reject(fn (User $person): bool => $previous->contains($person->id));

        if ($added->isNotEmpty()) {
            $mentionNotifier->notify($task, $taskComment, $request->user(), $added, $body);
        }

        return new TaskCommentResource($taskComment->load('user', 'mentions'));
    }

    /**
     * People who can be @mentioned on this task: everyone who can open its project.
     */
    public function mentionable(Task $task, Mentioner $mentioner): JsonResponse
    {
        Gate::authorize('view', $task);

        return response()->json(['data' => $mentioner->mentionableOnTask($task)
            ->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])
            ->values()
            ->all()]);
    }

    public function destroy(Request $request, TaskComment $taskComment): Response
    {
        Gate::authorize('view', $taskComment->task()->firstOrFail());
        abort_unless($taskComment->user_id === $request->user()->id, 403);

        $taskComment->delete();

        return response()->noContent();
    }
}

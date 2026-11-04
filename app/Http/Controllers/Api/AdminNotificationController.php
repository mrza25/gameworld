<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AdminNotificationController extends Controller
{
    /**
     * Announcements sent by admins, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::with('sender')
            ->latest()
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100))
            ->withQueryString();

        return $this->paginatedResponse(
            $announcements,
            $announcements->getCollection()->map(fn (Announcement $announcement) => $announcement->toApiArray())
        );
    }

    /**
     * Send an announcement to one user (`user_id`), several (`user_ids`) or
     * everyone (`audience: "all"`).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'link' => ['nullable', 'string', 'max:500', 'regex:#^(/(?!/)|https?://)#'],
            'audience' => ['nullable', 'in:all,users'],
            'user_id' => ['nullable', 'integer'],
            'user_ids' => ['nullable', 'array', 'max:1000'],
            'user_ids.*' => ['integer'],
        ], [
            'link.regex' => 'لینک باید با / یا http شروع شود.',
        ]);

        $userIds = collect($validated['user_ids'] ?? [])
            ->push($validated['user_id'] ?? null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $audience = $validated['audience'] ?? ($userIds->isEmpty() ? null : Announcement::AUDIENCE_USERS);

        if ($audience === null || ($audience === Announcement::AUDIENCE_USERS && $userIds->isEmpty())) {
            throw ValidationException::withMessages([
                'audience' => 'مخاطب اعلان را مشخص کنید: یک یا چند کاربر، یا همه‌ی کاربران.',
            ]);
        }

        if ($audience === Announcement::AUDIENCE_USERS && User::whereIn('id', $userIds)->count() !== $userIds->count()) {
            throw ValidationException::withMessages([
                'user_ids' => 'برخی از کاربران انتخاب‌شده وجود ندارند.',
            ]);
        }

        $announcement = Announcement::create([
            'sender_id' => $request->user()->id,
            'title' => $validated['title'],
            'message' => $validated['message'],
            'link' => $validated['link'] ?? null,
            'audience' => $audience,
            'recipients_count' => $audience === Announcement::AUDIENCE_ALL ? User::count() : $userIds->count(),
        ]);

        $recipients = User::query()->select('id');

        if ($audience === Announcement::AUDIENCE_USERS) {
            $recipients->whereIn('id', $userIds);
        }

        $recipients->chunkById(500, function (Collection $users) use ($announcement) {
            Notification::send($users, new AdminAnnouncement($announcement));
        });

        return response()->json([
            'ok' => true,
            'message' => "اعلان برای {$announcement->recipients_count} کاربر ارسال شد.",
            'data' => $announcement->load('sender')->toApiArray(),
        ], 201);
    }
}

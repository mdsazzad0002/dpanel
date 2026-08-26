<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;
use App\Models\FacebookPagePost;
use App\Models\FacebookPageActivity;
use App\Services\ChatEngine\Providers\FacebookAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChatEngineFacebookPostController extends Controller
{
    public function __construct(private readonly FacebookAdapter $facebook)
    {
    }

    public function index(Request $request): Response
    {
        $pages = ChatChannel::query()
            ->visibleTo($request->user())
            ->where('type', 'facebook')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'external_account_id']);

        $selectedPage = $pages->firstWhere('id', (string) $request->query('page')) ?? $pages->first();
        $publishedPosts = $selectedPage
            ? FacebookPagePost::query()
                ->where('chat_channel_id', $selectedPage->id)
                ->latest('published_at')
                ->limit(100)
                ->get()
            : collect();
        $activities = $selectedPage
            ? FacebookPageActivity::query()
                ->where('chat_channel_id', $selectedPage->id)
                ->latest('occurred_at')
                ->limit(300)
                ->get()
            : collect();

        $comments = $activities->where('activity_type', 'comment')->groupBy('parent_post_id');
        $threads = collect();

        foreach ($publishedPosts as $post) {
            $threadComments = $comments->get($post->external_post_id, collect())->values();

            if ($post->first_comment && ! $threadComments->contains('external_id', $post->external_comment_id)) {
                $threadComments->prepend([
                    'id' => 'initial-'.$post->id,
                    'external_id' => $post->external_comment_id,
                    'message' => $post->first_comment,
                    'actor_name' => $selectedPage?->name,
                    'verb' => $post->comment_status,
                    'occurred_at' => $post->published_at,
                ]);
            }

            $threads->put($post->external_post_id, [
                'id' => $post->id,
                'external_post_id' => $post->external_post_id,
                'message' => $post->message,
                'permalink_url' => $post->permalink_url,
                'occurred_at' => $post->published_at,
                'source' => 'panel',
                'comments' => $threadComments->values(),
            ]);
        }

        foreach ($activities->where('activity_type', 'post') as $post) {
            if ($threads->has($post->external_id)) {
                continue;
            }

            $threads->put($post->external_id, [
                'id' => $post->id,
                'external_post_id' => $post->external_id,
                'message' => $post->message,
                'permalink_url' => null,
                'occurred_at' => $post->occurred_at,
                'source' => 'webhook',
                'comments' => $comments->get($post->external_id, collect())->values(),
            ]);
        }

        // Meta may deliver a comment event without replaying the older parent
        // post. Keep it connected under a lightweight parent placeholder.
        foreach ($comments as $postId => $threadComments) {
            if (! $postId || $threads->has($postId)) {
                continue;
            }

            $threads->put($postId, [
                'id' => 'parent-'.$postId,
                'external_post_id' => $postId,
                'message' => null,
                'permalink_url' => null,
                'occurred_at' => $threadComments->max('occurred_at'),
                'source' => 'webhook',
                'comments' => $threadComments->values(),
            ]);
        }

        return Inertia::render('ChatEngine/FacebookPosts/Index', [
            'pages' => $pages,
            'selectedPageId' => $selectedPage?->id,
            'threads' => $threads->sortByDesc('occurred_at')->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chat_channel_id' => [
                'required',
                'uuid',
                Rule::exists('chat_channels', 'id')->where(fn ($query) => $query
                    ->where('type', 'facebook')
                    ->where('is_active', true)),
            ],
            'message' => ['required', 'string', 'max:63206'],
            'link' => ['nullable', 'url:http,https', 'max:2048'],
            'first_comment' => ['nullable', 'string', 'max:8000'],
        ]);

        $channel = ChatChannel::query()->visibleTo($request->user())->findOrFail($validated['chat_channel_id']);

        try {
            $result = $this->facebook->publishPagePost(
                $channel,
                $validated['message'],
                $validated['link'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Facebook post failed: '.$e->getMessage());
        }

        $redirect = back()
            ->with('success', 'Post published to '.$channel->name.'.')
            ->with('facebook_post_url', $result['permalink_url']);

        $storedPost = FacebookPagePost::create([
            'chat_channel_id' => $channel->id,
            'external_post_id' => $result['id'],
            'message' => $validated['message'],
            'link' => $validated['link'] ?? null,
            'first_comment' => $validated['first_comment'] ?? null,
            'comment_status' => empty($validated['first_comment']) ? 'not_requested' : 'pending',
            'permalink_url' => $result['permalink_url'],
            'published_at' => now(),
            'created_by' => $request->user()?->id,
        ]);

        if (! empty($validated['first_comment'])) {
            try {
                $commentId = $this->facebook->commentOnPagePost($channel, $result['id'], $validated['first_comment']);
                $storedPost->update([
                    'external_comment_id' => $commentId,
                    'comment_status' => 'published',
                ]);
                $redirect->with('success', 'Post and first comment published to '.$channel->name.'.');
            } catch (\Throwable $e) {
                $storedPost->update(['comment_status' => 'failed']);
                // The post already exists at this point. Report partial
                // success instead of retrying and creating a duplicate post.
                $redirect->with('error', 'The post was published, but its first comment failed: '.$e->getMessage());
            }
        }

        return $redirect;
    }

    public function comment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chat_channel_id' => ['required', 'uuid', Rule::exists('chat_channels', 'id')->where(fn ($query) => $query->where('type', 'facebook')->where('is_active', true))],
            'post_id' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:8000'],
        ]);

        $channel = ChatChannel::query()->visibleTo($request->user())->findOrFail($validated['chat_channel_id']);
        $knownPost = FacebookPagePost::query()->where('chat_channel_id', $channel->id)->where('external_post_id', $validated['post_id'])->exists()
            || FacebookPageActivity::query()->where('chat_channel_id', $channel->id)->where('activity_type', 'post')->where('external_id', $validated['post_id'])->exists()
            || FacebookPageActivity::query()->where('chat_channel_id', $channel->id)->where('parent_post_id', $validated['post_id'])->exists();

        if (! $knownPost) {
            return back()->with('error', 'This Facebook post is not available in the selected Page history.');
        }

        try {
            $commentId = $this->facebook->commentOnPagePost($channel, $validated['post_id'], $validated['message']);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Facebook comment failed: '.$e->getMessage());
        }

        FacebookPageActivity::updateOrCreate(
            ['chat_channel_id' => $channel->id, 'activity_type' => 'comment', 'external_id' => $commentId],
            ['parent_post_id' => $validated['post_id'], 'message' => $validated['message'], 'actor_id' => $channel->external_account_id, 'actor_name' => $channel->name, 'verb' => 'add', 'occurred_at' => now()],
        );

        return back()->with('success', 'Comment published as '.$channel->name.'.');
    }
}

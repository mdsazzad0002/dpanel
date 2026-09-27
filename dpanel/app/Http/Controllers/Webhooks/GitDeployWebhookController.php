<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RunWebsiteGitActionJob;
use App\Models\WebsiteGitDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GitHub push webhook → pull the website's branch. Each deployment has its
 * own URL and HMAC secret, so one leaked secret never reaches another site.
 */
class GitDeployWebhookController extends Controller
{
    public function __invoke(Request $request, string $deployment): JsonResponse
    {
        $model = WebsiteGitDeployment::query()->find($deployment);
        $secret = (string) ($model?->webhook_secret ?? '');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if (! $model || $secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }
        if ($event !== 'push') {
            return response()->json(['message' => "Ignored {$event} event."]);
        }
        if (! $model->enabled || ! $model->deploy_on_push) {
            return response()->json(['message' => 'Push-to-deploy is disabled for this website.']);
        }
        if ($request->json('ref') !== 'refs/heads/'.$model->branch) {
            return response()->json(['message' => 'Push is not for the deployed branch; ignored.']);
        }
        if ($request->json('deleted') === true) {
            return response()->json(['message' => 'Branch deletion ignored.']);
        }

        // The first deploy moves existing files to trash, so it must stay a
        // deliberate click in the panel — webhooks only ever pull.
        if (! $model->logs()->where('action', 'clone')->where('status', 'success')->exists()) {
            return response()->json(['message' => 'Website has not been deployed from the panel yet; ignored.']);
        }
        $commit = substr((string) $request->json('after'), 0, 12);
        RunWebsiteGitActionJob::dispatch($model->id, 'pull', trim("Push-to-deploy {$commit}"));

        return response()->json(['message' => 'Deployment queued.'], 202);
    }
}

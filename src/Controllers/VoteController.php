<?php

namespace Azuriom\Plugin\Vote\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\Server;
use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Reward;
use Azuriom\Plugin\Vote\Models\ServerWrapper;
use Azuriom\Plugin\Vote\Models\Site;
use Azuriom\Plugin\Vote\Models\Vote;
use Azuriom\Plugin\Vote\Verification\VoteChecker;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class VoteController extends Controller
{
    /**
     * Display the vote home page.
     */
    public function index(Request $request)
    {
        $servers = [];

        /** @var Reward $reward */
        foreach (Reward::orderByDesc('chances')->get() as $reward) {
            foreach ($reward->servers as $server) {
                $servers[(new ServerWrapper($server))->getPublicId()] = $server->name;
            }
        }

        $queryName = ($gameId = $request->input('uid')) !== null
            ? User::where('game_id', $gameId)->value('name')
            : $request->input('user', '');

        $user = $request->user();

        return view('vote::index', array_merge([
            'serversChoice' => $servers,
            'user' => $user,
            'request' => $request,
            'sites' => Site::enabled()->with('rewards')->get(),
            'rewards' => Reward::orderByDesc('chances')->get(),
            'votes' => Vote::getTopVoters(now()->startOfMonth()),
            'ipv6compatibility' => setting('vote.ipv4-v6-compatibility', true),
            'displayRewards' => (bool) setting('vote.display-rewards', true),
        ], $this->userVoteInfo($user)));
    }

    private function userVoteInfo(?User $user) {
        if ($user === null) {
            return [
                'totalVotes' => 0,
                'monthPosition' => 0,
                'monthVotes' => 0,
                'avatar' => '',
                'userName' => ''
            ];
        }

        $top = Vote::getTopPosition($user->id, now()->startOfMonth(), null);
        if ($top['position'] == 0) {
            $pos = '-';
        } elseif ($top['position'] == 1) {
            $pos = '1er/ère';
        } elseif ($top['position'] == 2) {
            $pos = '2nd';
        } else {
            $pos = $top['position'].'ème';
        }

        return [
            'totalVotes' => Vote::countAllVotes($user->id),
            'monthPosition' => $pos,
            'monthVotes' => $top['votes'],
            'avatar' => $user->getAvatar(),
            'userName' => $user->name
        ];
    }

    public function verifyUser(Request $request, string $name)
    {
        $user = User::firstWhere('name', $name);

        if ($user === null) {
            return response()->json([
                'message' => trans('vote::messages.errors.user'),
            ], 422);
        }

        $sites = Site::enabled()
            ->with('rewards')
            ->get()
            ->mapWithKeys(function (Site $site) use ($user, $request) {
                return [
                    $site->id => $site->getNextVoteTime($user, $request->ip())?->valueOf(),
                ];
            });

        return response()->json(array_merge([ 'sites' => $sites ], $this->userVoteInfo($user)));
    }

    public function vote()
    {
        return response()->noContent(404);
    }

    public function done(Request $request, Site $site)
    {
        $user = $request->user() ?? User::firstWhere('name', $request->input('user'));

        abort_if($user === null, 401);

        $nextVoteTime = $site->getNextVoteTime($user, $request->ip());

        if ($nextVoteTime !== null) {
            return response()->json([
                'message' => $this->formatTimeMessage($nextVoteTime),
            ], 422);
        }

        $voteChecker = app(VoteChecker::class);

        if ($site->has_verification && ! $voteChecker->verifyVote($site, $user, $request->ip())) {
            return response()->json([
                'status' => 'pending',
            ]);
        }

        // Check again because sometimes API can be really slow...
        $nextVoteTime = $site->getNextVoteTime($user, $request->ip());

        if ($nextVoteTime !== null) {
            return response()->json([
                'message' => $this->formatTimeMessage($nextVoteTime),
            ], 422);
        }

        $next = now()->addMinutes($site->vote_delay);
        Cache::put('votes.site.'.$site->id.'.'.$request->ip(), $next, $next);

        $serverId = (int) Crypt::decryptString($request->input('server_id'));
        $reward = $site->getRandomReward($serverId);

        if ($reward !== null) {
            $vote = $site->votes()->create([
                'user_id' => $user->id,
                'reward_id' => $reward->id,
            ]);

            $reward->dispatch($vote, $serverId);
        }

        return response()->json([
            'message' => trans('vote::messages.success', [
                'reward' => $reward?->name ?? trans('messages.unknown'),
            ]),
        ]);
    }

    private function formatTimeMessage(Carbon $nextVoteTime)
    {
        $time = $nextVoteTime->diffForHumans([
            'parts' => 2,
            'join' => true,
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
        ]);

        return trans('vote::messages.errors.delay', ['time' => $time]);
    }
}

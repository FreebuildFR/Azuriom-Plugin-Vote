<?php

namespace Azuriom\Plugin\Vote\Models;

use Azuriom\Models\Server;
use Azuriom\Models\Traits\HasTablePrefix;
use Azuriom\Models\Traits\Loggable;
use Azuriom\Models\Traits\Searchable;
use Azuriom\Models\User;
use Barryvdh\Debugbar\Facades\Debugbar;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property int $vote_delay
 * @property string|null $verification_key
 * @property bool $has_verification
 * @property bool $is_enabled
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Illuminate\Support\Collection|\Azuriom\Plugin\Vote\Models\Reward[] $rewards
 * @property \Illuminate\Support\Collection|\Azuriom\Plugin\Vote\Models\Vote[] $votes
 *
 * @method static \Illuminate\Database\Eloquent\Builder enabled()
 */
class Site extends Model
{
    use HasTablePrefix;
    use Loggable;
    use Searchable;

    /**
     * The table prefix associated with the model.
     */
    protected string $prefix = 'vote_';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name', 'url', 'vote_delay', 'verification_key', 'has_verification', 'is_enabled',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'need_online' => 'boolean',
        'has_verification' => 'boolean',
        'is_enabled' => 'boolean',
    ];

    /**
     * The attributes that can be used for search.
     *
     * @var array<int, string>
     */
    protected array $searchable = [
        'name',
    ];

    public function rewards()
    {
        return $this->belongsToMany(Reward::class, 'vote_reward_site');
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function getPublicServerIds(): string
    {
        $serversIds = [];

        /** @var Reward $reward */
        foreach ($this->rewards as $reward) {
            /** @var Server $server */
            foreach ($reward->servers as $server) {
                $serversIds[] = (new ServerWrapper($server))->getPublicId();
            }
        }

        return json_encode($serversIds);
    }

    public function getRandomReward(int $server_id = null): ?Reward
    {
        $rewards = clone $this->rewards;
        if ($server_id !== null) {
            $rewards = $rewards->filter(function (Reward $reward) use ($server_id) {
                $servers = $reward->servers->filter(function (Server $server) use ($server_id) {
                    return $server->id === $server_id;
                });

                if ($servers->isEmpty()) {
                    return false;
                }

                return true;
            });
        }

        $total = $rewards->sum('chances');

        if ($total === 0) {
            return null;
        }

        // Multiply to support decimal chances
        $random = random_int(1, $total * 1000);
        $sum = 0;

        foreach ($rewards as $reward) {
            $sum += $reward->chances * 1000;

            if ($sum >= $random) {
                return $reward;
            }
        }

        return $rewards->first();
    }

    public function getNextVoteTime(User $user, Request|string $ip): ?Carbon
    {
        if ($ip instanceof Request) {
            $ip = $ip->ip();
        }

        // GTop100 votes resets at midnight GMT+1
        $voteResetAtFixedTime = Str::contains($this->url, 'gtop100.com');
        $voteTime = $voteResetAtFixedTime
            ? now()->timezone('Europe/London')->startOfDay()
            : now()->subMinutes($this->vote_delay);

        $lastVoteTime = $this->votes()
            ->where('user_id', $user->id)
            ->where('created_at', '>', $voteTime)
            ->latest()
            ->value('created_at');

        if ($lastVoteTime !== null) {
            return $voteResetAtFixedTime
                ? now()->timezone('Europe/London')->endOfDay()
                : $lastVoteTime->addMinutes($this->vote_delay);
        }

        $nextVoteTimeForIp = Cache::get('votes.site.'.$this->id.'.'.$ip);

        if ($nextVoteTimeForIp === null || $nextVoteTimeForIp->isPast()) {
            return null;
        }

        return $nextVoteTimeForIp;
    }

    /**
     * Scope a query to only include enabled vote sites.
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }
}

<?php

namespace Azuriom\Plugin\Vote\Commands;

use Azuriom\Models\User;
use Azuriom\Plugin\Vote\Models\Reward;
use Azuriom\Plugin\Vote\Models\Vote;
use Illuminate\Console\Command;

class MonthlyRewardsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vote:rewards';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Give rewards to the users with most votes.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $rewards = Reward::all();
        $now = now()->subHour();
        $votes = Vote::getRawTopVoters($now->clone()->startOfMonth(), $now->endOfMonth(), 100);
        $users = User::findMany($votes->pluck('user_id'))->keyBy('id');

        foreach ($votes as $index => $vote) {
            $positions = $reward->monthly_rewards ?? [];
            $user = $users->get($vote->user_id);
            $targetRewards = $rewards->where(
                fn (Reward $reward) => in_array($index + 1, $positions, true)
            );

            if ($user === null) {
                continue;
            }

            foreach ($targetRewards as $reward) {
                $reward->dispatch($user);

                $this->info('Dispatching reward '.$reward->name.' to user #'.$user->id);
            }
        }
    }
}

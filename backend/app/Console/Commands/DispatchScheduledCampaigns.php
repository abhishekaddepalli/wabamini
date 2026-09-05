<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Campaign;
use App\Jobs\ProcessCampaignBroadcastJob;

class DispatchScheduledCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaigns:dispatch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Polls database for scheduled campaign broadcasts that are due and triggers them.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $campaigns = Campaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($campaigns->isEmpty()) {
            return Command::SUCCESS;
        }

        foreach ($campaigns as $campaign) {
            $campaign->update(['status' => 'sending']);
            ProcessCampaignBroadcastJob::dispatch($campaign->id);
            $this->info("Dispatched broadcast campaign ID {$campaign->id} ({$campaign->name}).");
        }

        return Command::SUCCESS;
    }
}

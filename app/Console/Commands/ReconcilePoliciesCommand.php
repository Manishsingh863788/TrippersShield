<?php

namespace App\Console\Commands;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\PolicyIssuanceService;
use Illuminate\Console\Command;

class ReconcilePoliciesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'policyflow:reconcile {--id= : Specific policy request ID to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile pending or uncertain policy requests against the insurer gateway';

    public function handle(PolicyIssuanceService $issuanceService): int
    {
        $id = $this->option('id');

        $query = PolicyRequest::query();

        if ($id) {
            $query->where('id', $id);
        } else {
            $query->whereIn('status', [
                PolicyStatus::PENDING_CONFIRMATION->value,
                PolicyStatus::NEEDS_RECONCILIATION->value,
            ]);
        }

        $pending = $query->get();

        if ($pending->isEmpty()) {
            $this->info('No pending or uncertain policy requests found for reconciliation.');

            return self::SUCCESS;
        }

        $this->info("Found {$pending->count()} requests requiring reconciliation. Starting recovery...");

        $recovered = 0;
        $unresolved = 0;

        foreach ($pending as $request) {
            $this->line("Reconciling PolicyRequest #{$request->id} (Status: {$request->status->value})...");
            $result = $issuanceService->reconcile($request);

            if ($result['policy_request']->status === PolicyStatus::ISSUED) {
                $this->info(" -> SUCCESS: Policy #{$request->id} reconciled and confirmed ISSUED (Policy Number: {$result['policy_request']->policy_number})");
                $recovered++;
            } else {
                $this->warn(" -> UNRESOLVED: Policy #{$request->id} remains in status {$result['policy_request']->status->value}");
                $unresolved++;
            }
        }

        $this->info("Reconciliation complete. Recovered: {$recovered}, Unresolved: {$unresolved}.");

        return self::SUCCESS;
    }
}

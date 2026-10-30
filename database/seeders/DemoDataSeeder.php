<?php

namespace Database\Seeders;

use App\Enums\FixType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentUpdateType;
use App\Enums\RoleName;
use App\Enums\Severity;
use App\Enums\SlaEventType;
use App\Enums\SlaMetric;
use App\Enums\TimeEntrySource;
use App\Enums\UpdateVisibility;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentResolution;
use App\Models\Runbook;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboard\DashboardCache;
use App\Services\SlaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Realistic sample data for trying the app locally: one user per role (plus responders on
 * every team), about 220 incidents over the last 120 days with timings, SLA breaches, fixes
 * and logged time, and a few runbooks. Run it explicitly:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Demo users sign in with the password "demo-password". Output is the same on every run
 * (fixed random seed), and running it twice does nothing the second time.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'demo-password';

    private const DAYS = 120;

    private const INCIDENTS = 220;

    /** @var array<string, list<User>> */
    private array $teamMembers = [];

    private User $manager;

    /** @var list<User> */
    private array $reporters = [];

    public function __construct(private SlaService $sla) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('DemoDataSeeder only runs in the local or testing environment.');
        }

        if (User::where('email', 'grace@foxdale.local')->exists()) {
            $this->command?->warn('Demo data is already present; nothing to do.');

            return;
        }

        $this->call([RolesAndPermissionsSeeder::class, CatalogSeeder::class, SlaPolicySeeder::class, ExampleServicesSeeder::class]);

        mt_srand(2026);

        // Demo rows are sample data, not user actions, so they are not written to the audit log.
        Model::withoutEvents(fn () => DB::transaction(function (): void {
            $this->createPeople();
            $this->createRunbooks();
            $this->createIncidents();
        }));

        $this->call(DemoRcaSeeder::class);

        app(DashboardCache::class)->bust();
        $this->command?->info('Demo data created. Sign in as grace@foxdale.local (incident manager) or any demo user with "'.self::PASSWORD.'".');
    }

    private function createPeople(): void
    {
        $this->manager = $this->user('Grace Mwansa', 'grace', RoleName::IncidentManager);

        $people = [
            'NOC' => [['Peter Banda', 'peter', RoleName::Noc], ['Ruth Phiri', 'ruth', RoleName::Noc]],
            'Payments' => [['Chanda Mulenga', 'chanda', RoleName::Responder], ['Mutale Zulu', 'mutale', RoleName::Responder]],
            'Infrastructure' => [['Joseph Tembo', 'joseph', RoleName::Responder], ['Natasha Lungu', 'natasha', RoleName::Responder]],
            'DBA' => [['Brian Musonda', 'brian', RoleName::Responder], ['Esther Kabwe', 'esther', RoleName::Responder]],
            'Security' => [['Daniel Sakala', 'daniel', RoleName::Responder], ['Linda Chileshe', 'linda', RoleName::Responder]],
        ];

        foreach ($people as $teamName => $members) {
            $team = Team::where('name', $teamName)->firstOrFail();

            foreach ($members as $index => [$name, $login, $role]) {
                $user = $this->user($name, $login, $role);
                $team->members()->attach($user->id, ['is_lead' => $index === 0]);
                $this->teamMembers[$teamName][] = $user;
            }

            $team->forceFill(['lead_user_id' => $this->teamMembers[$teamName][0]->id])->save();
        }

        $this->reporters = [
            $this->user('Mary Ngoma', 'mary', RoleName::Reporter),
            $this->user('John Mwale', 'john', RoleName::Reporter),
        ];
    }

    private function user(string $name, string $login, RoleName $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$login}@foxdale.local",
            'password' => self::PASSWORD,
            'timezone' => 'Africa/Lusaka',
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    private function createIncidents(): void
    {
        $services = Service::with('ownerTeam')->get()->keyBy('name');
        $categories = Category::pluck('id', 'name');
        $scenarios = $this->scenarios();
        $now = Carbon::now();

        for ($i = 0; $i < self::INCIDENTS; $i++) {
            // Spread reports over the period, a little busier in recent weeks.
            $daysAgo = (int) floor(self::DAYS * (mt_rand(0, 1000) / 1000) ** 1.3);
            $reportedAt = $now->copy()->subDays($daysAgo)->setTime(mt_rand(6, 21), mt_rand(0, 59));

            if ($reportedAt->greaterThan($now)) {
                $reportedAt = $now->copy()->subMinutes(mt_rand(5, 600));
            }

            $this->createIncident($scenarios[mt_rand(0, count($scenarios) - 1)], $reportedAt, $services, $categories, $now);
        }
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  Collection<string, Service>  $services
     * @param  Collection<string, int>  $categories
     */
    private function createIncident(array $scenario, Carbon $reportedAt, $services, $categories, Carbon $now): void
    {
        $service = $services[$scenario['service']];
        $teamName = $service->ownerTeam->name ?? 'NOC';
        $members = $this->teamMembers[$teamName] ?? $this->teamMembers['NOC'];
        $assignee = $members[mt_rand(0, count($members) - 1)];
        $severity = $this->pickSeverity($scenario['severity']);
        $ageHours = $reportedAt->diffInHours($now);

        $incident = Incident::factory()->make([
            'title' => $scenario['title'],
            'description' => $scenario['description'],
            'impact' => $scenario['impact'],
            'severity' => $severity,
            'service_id' => $service->id,
            'category_id' => $categories[$scenario['category']] ?? null,
            'team_id' => $service->owner_team_id,
            'reporter_id' => mt_rand(0, 2) === 0 ? $this->reporters[mt_rand(0, 1)]->id : $this->teamMembers['NOC'][mt_rand(0, 1)]->id,
            'assignee_id' => $assignee->id,
            'source' => mt_rand(1, 10) === 1 ? IncidentSource::Monitoring : IncidentSource::Manual,
            'reported_at' => $reportedAt,
            'started_at' => $reportedAt->copy()->subMinutes(mt_rand(2, 40)),
        ]);
        $this->sla->applyTargets($incident);

        $targets = $this->sla->targetsFor($severity);
        $ackMinutes = $this->spread($targets['ack'], 0.15, 1.2);
        $mitigateMinutes = max($ackMinutes + 10, $this->spread($targets['mitigate'], 0.3, 1.15));
        $resolveMinutes = max($mitigateMinutes + 15, $this->spread($targets['resolve'], 0.1, 1.2));

        // How far it got: anything older than its resolve time is done; recent ones are in flight.
        $minutesOpen = $reportedAt->diffInMinutes($now);
        $cancelled = mt_rand(1, 40) === 1;
        $stages = match (true) {
            $cancelled => ['cancelled'],
            // Quick fixes skip the separate mitigation step.
            $minutesOpen >= $resolveMinutes && $ageHours > 6 => ['acknowledged', 'investigating', ...($resolveMinutes > $targets['mitigate'] ? ['mitigated'] : []), 'resolved'],
            $minutesOpen >= $mitigateMinutes => ['acknowledged', 'investigating', 'mitigated'],
            $minutesOpen >= $ackMinutes + 10 => ['acknowledged', 'investigating'],
            $minutesOpen >= $ackMinutes => ['acknowledged'],
            default => [],
        };

        $times = [
            'acknowledged' => $reportedAt->copy()->addMinutes($ackMinutes),
            'investigating' => $reportedAt->copy()->addMinutes($ackMinutes + mt_rand(1, 8)),
            'mitigated' => $reportedAt->copy()->addMinutes($mitigateMinutes),
            'resolved' => $reportedAt->copy()->addMinutes($resolveMinutes),
            'cancelled' => $reportedAt->copy()->addMinutes(mt_rand(5, 40)),
        ];

        $closes = in_array('resolved', $stages, true) && ! $severity->isHigh() && $ageHours > 72 && mt_rand(1, 10) <= 7;

        if ($closes) {
            $stages[] = 'closed';
            $times['closed'] = $times['resolved']->copy()->addHours(mt_rand(8, 48));
        }

        $status = IncidentStatus::from($stages === [] ? 'reported' : end($stages));
        $reopened = $status === IncidentStatus::Resolved || $status === IncidentStatus::Closed ? mt_rand(1, 25) === 1 : false;

        $incident->forceFill([
            'status' => $status,
            'acknowledged_at' => in_array('acknowledged', $stages, true) ? $times['acknowledged'] : null,
            'mitigated_at' => in_array('mitigated', $stages, true) ? $times['mitigated'] : null,
            'resolved_at' => in_array('resolved', $stages, true) ? $times['resolved'] : null,
            'closed_at' => $closes ? $times['closed'] : null,
            'reopened_count' => $reopened ? 1 : 0,
        ]);

        if ($status === IncidentStatus::Cancelled) {
            $incident->assignee_id = null;
        }

        $incident->save();
        $incident->forceFill(['ref' => Incident::makeRef($incident->id, $incident->reported_at)])->save();

        $this->writeHistory($incident, $stages, $times, $reopened, $assignee);
        $this->writeSlaEvents($incident, $now);

        if ($incident->resolved_at !== null) {
            $this->writeResolution($incident, $scenario, $assignee);
        }

        if ($incident->acknowledged_at !== null && $status !== IncidentStatus::Cancelled) {
            $this->writeWork($incident, $assignee, $scenario, $now);
        }
    }

    /**
     * @param  list<string>  $stages
     * @param  array<string, Carbon>  $times
     */
    private function writeHistory(Incident $incident, array $stages, array $times, bool $reopened, User $assignee): void
    {
        $previous = ['status' => null, 'at' => $incident->reported_at];
        $rows = [['to_status' => 'reported', 'changed_at' => $incident->reported_at, 'user_id' => $incident->reporter_id]];

        foreach ($stages as $stage) {
            $rows[] = ['to_status' => $stage, 'changed_at' => $times[$stage], 'user_id' => $assignee->id];

            if ($stage === 'resolved' && $reopened) {
                $reopenAt = $times['resolved']->copy()->addMinutes(mt_rand(30, 240));
                $rows[] = ['to_status' => 'investigating', 'changed_at' => $reopenAt, 'user_id' => $assignee->id, 'note' => 'Problem came back after the fix'];
                $rows[] = ['to_status' => 'resolved', 'changed_at' => $reopenAt->copy()->addMinutes(mt_rand(30, 180)), 'user_id' => $assignee->id];
            }
        }

        foreach ($rows as $row) {
            $incident->statusHistory()->create([
                'from_status' => $previous['status'],
                'to_status' => $row['to_status'],
                'user_id' => $row['user_id'],
                'note' => $row['note'] ?? null,
                'changed_at' => $row['changed_at'],
                'seconds_in_previous' => $previous['status'] === null ? null : (int) $previous['at']->diffInSeconds($row['changed_at']),
            ]);
            $previous = ['status' => $row['to_status'], 'at' => $row['changed_at']];
        }

        if ($reopened) {
            $incident->forceFill(['resolved_at' => $previous['at']])->save();
        }
    }

    private function writeSlaEvents(Incident $incident, Carbon $now): void
    {
        if ($incident->status === IncidentStatus::Cancelled) {
            return;
        }

        $checks = [
            [SlaMetric::Acknowledge, $incident->ack_due_at, $incident->acknowledged_at],
            [SlaMetric::Mitigate, $incident->mitigate_due_at, $incident->mitigated_at ?? $incident->resolved_at],
            [SlaMetric::Resolve, $incident->resolve_due_at, $incident->resolved_at],
        ];

        $breached = false;

        foreach ($checks as [$metric, $due, $metAt]) {
            if ($due === null || ($metAt !== null && $metAt->lessThanOrEqualTo($due)) || ($metAt === null && $due->isFuture())) {
                continue;
            }

            $incident->slaEvents()->create(['metric' => $metric, 'type' => SlaEventType::Breached, 'due_at' => $due, 'occurred_at' => $due]);
            $breached = true;
        }

        if ($breached) {
            $incident->forceFill(['sla_breached' => true])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function writeResolution(Incident $incident, array $scenario, User $assignee): void
    {
        $published = mt_rand(1, 100) <= 35;

        $resolution = new IncidentResolution([
            'summary' => $scenario['summary'],
            'workaround' => $scenario['workaround'],
            'permanent_fix' => $scenario['permanent_fix'],
            'fix_type' => $scenario['fix_type'],
            'steps' => $scenario['steps'],
            'linked_prs' => $scenario['fix_type'] === FixType::CodeChange
                ? [['url' => 'https://git.foxdale.local/platform/'.str($scenario['service'])->slug().'/pull/'.mt_rand(100, 999), 'title' => $scenario['pr_title'] ?? 'Fix']]
                : [],
            'deployed_at' => $incident->resolved_at?->copy()->subMinutes(mt_rand(5, 30)),
            'verification' => $scenario['verification'],
            'follow_up_needed' => mt_rand(1, 4) === 1,
            'updated_by' => $assignee->id,
        ]);
        $resolution->incident_id = $incident->id;
        $resolution->forceFill([
            'is_published' => $published,
            'published_at' => $published ? $incident->resolved_at?->copy()->addDays(mt_rand(1, 3)) : null,
            'published_by' => $published ? $this->manager->id : null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function writeWork(Incident $incident, User $assignee, array $scenario, Carbon $now): void
    {
        $end = $incident->resolved_at ?? $now;

        $incident->updates()->create([
            'user_id' => $assignee->id,
            'type' => IncidentUpdateType::StatusUpdate,
            'body' => $scenario['update'],
            'visibility' => UpdateVisibility::Stakeholder,
        ]);

        for ($entry = 0, $entries = mt_rand(1, 3); $entry < $entries; $entry++) {
            $incident->timeEntries()->create([
                'user_id' => $entry === 2 ? $this->manager->id : $assignee->id,
                'minutes' => mt_rand(3, 24) * 5,
                'note' => ['Investigation', 'Applied fix and monitored', 'Coordination and stakeholder updates'][$entry],
                'worked_on' => $end->copy()->setTimezone('Africa/Lusaka')->toDateString(),
                'source' => TimeEntrySource::Manual,
            ]);
        }
    }

    /**
     * A random duration around a target: mostly between $low and $high times the target,
     * occasionally well over it.
     */
    private function spread(int $target, float $low, float $high): int
    {
        $share = $low + ($high - $low) * (mt_rand(0, 1000) / 1000) ** 1.8;

        if (mt_rand(1, 12) === 1) {
            $share *= 1.8;
        }

        return max(1, (int) round($target * $share));
    }

    /**
     * Mostly the scenario's usual severity, sometimes one step either way.
     */
    private function pickSeverity(int $usual): Severity
    {
        $roll = mt_rand(1, 10);
        $value = $roll === 1 ? $usual - 1 : ($roll >= 9 ? $usual + 1 : $usual);

        return Severity::from(max(1, min(4, $value)));
    }

    private function createRunbooks(): void
    {
        $services = Service::pluck('id', 'name');
        $categories = Category::pluck('id', 'name');
        $author = $this->teamMembers['NOC'][0];

        $runbooks = [
            [
                'title' => 'Drain a stuck payment callback queue',
                'service' => 'Payment gateway',
                'category' => 'Application',
                'body' => "Use this when deposits stay *pending* and the `payment-callbacks` queue depth keeps growing.\n\n## Check\n\n1. Open RabbitMQ management and look at `payment-callbacks`. Above 500 ready messages for 5 minutes is not normal.\n2. Check that the consumer pods are connected: `kubectl -n payments get pods -l app=callback-consumer`.\n\n## Fix\n\n1. Restart the consumer: `kubectl -n payments rollout restart deploy/callback-consumer`.\n2. Watch the queue drain. It should fall by at least 200 messages a minute.\n3. Replay failed callbacks from the dead-letter queue with `php artisan payments:replay-dlq --since=2h`.\n\n## Escalate\n\nIf the queue does not drain within 15 minutes, page the Payments team lead.",
            ],
            [
                'title' => 'Mobile money provider timeouts',
                'service' => 'Mobile money integration',
                'category' => 'Third-party',
                'body' => "## Symptoms\n\n- Collections fail with `504` from the provider\n- The provider status page may still show green\n\n## Steps\n\n1. Confirm on the provider dashboard which operator is affected (Airtel or MTN).\n2. Switch that operator to **queued mode** in the admin panel so requests are retried instead of failing.\n3. Call the provider NOC (numbers in the vendor contacts sheet) and open a ticket.\n4. Post a stakeholder update every 30 minutes.\n\n## Recovery\n\nWhen the provider confirms recovery, switch back to live mode and watch the success rate for 15 minutes.",
            ],
            [
                'title' => 'Replica lag on the core database cluster',
                'service' => 'Core database cluster',
                'category' => 'Database',
                'body' => "## Check\n\n```sql\nSHOW REPLICA STATUS\\G\n```\n\n`Seconds_Behind_Source` above 300 needs action.\n\n## Fix\n\n1. Find long-running queries on the replica: `SELECT * FROM information_schema.processlist WHERE time > 60;`\n2. Kill reporting queries that block the SQL thread.\n3. If lag keeps growing, move read traffic to the other replica in ProxySQL.\n\n## Prevention\n\nReporting jobs must use the reporting replica, never the primary.",
            ],
            [
                'title' => 'Site-to-site VPN tunnel down',
                'service' => 'Network and VPN',
                'category' => 'Network',
                'body' => "1. Check the tunnel status on the firewall: **VPN > IPsec tunnels**.\n2. If phase 1 is down, check the peer's public IP has not changed.\n3. Restart the tunnel. If it does not come up, check the pre-shared key and proposal settings match the peer.\n4. Fail traffic over to the backup tunnel while you work.",
            ],
            [
                'title' => 'Users cannot sign in with SSO',
                'service' => 'Identity and SSO',
                'category' => 'Access',
                'body' => "## Quick checks\n\n- Is the IdP signing certificate expired? Check **Certificates** in the IdP admin.\n- Is the clock on the app servers in sync (`timedatectl`)? SAML fails with more than 2 minutes of skew.\n\n## Fix\n\n1. Rotate the certificate and upload the new metadata to each service provider.\n2. Ask an affected user to sign in again in a private window.\n\nLog every change in the incident timeline.",
            ],
            [
                'title' => 'Outbound email stuck in the queue',
                'service' => 'Email and notifications',
                'category' => 'Infrastructure',
                'body' => "1. Check the mail queue size: `postqueue -p | tail -1`.\n2. Look for `connection refused` or `rate limited` in `/var/log/mail.log`.\n3. If the relay rate-limits us, lower the send rate in the notifications service and flush the queue: `postqueue -f`.\n4. Confirm that a test email arrives.",
            ],
        ];

        foreach ($runbooks as $runbook) {
            Runbook::create([
                'title' => $runbook['title'],
                'body' => $runbook['body'],
                'service_id' => $services[$runbook['service']] ?? null,
                'category_id' => $categories[$runbook['category']] ?? null,
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);
        }
    }

    /**
     * Incident templates: what was reported and how it was fixed.
     *
     * @return list<array<string, mixed>>
     */
    public static function scenarios(): array
    {
        return [
            [
                'service' => 'Payment gateway', 'category' => 'Application', 'severity' => 2,
                'title' => 'Deposits stuck in pending on the payment gateway',
                'description' => 'Customers report deposits stay pending. Bank callbacks are not being processed.',
                'impact' => 'Customers cannot complete card deposits',
                'update' => 'Callback consumer restarted; the queue is draining.',
                'summary' => 'Callback queue was stuck after a RabbitMQ node restart',
                'workaround' => 'Restarted the consumer service to drain the queue',
                'permanent_fix' => 'Added consumer reconnect with backoff and a queue-depth alert',
                'fix_type' => FixType::CodeChange, 'pr_title' => 'Reconnect callback consumer with backoff',
                'steps' => ['Checked queue depth in RabbitMQ management', 'Restarted the consumer', 'Replayed the failed callbacks'],
                'verification' => 'Deposits succeeded for 30 minutes after the fix',
            ],
            [
                'service' => 'Payment gateway', 'category' => 'Third-party', 'severity' => 1,
                'title' => 'Card payments declined for all Visa cards',
                'description' => 'Every Visa transaction is declined with code 91 (issuer unavailable).',
                'impact' => 'All Visa card payments fail',
                'update' => 'Acquirer confirmed an outage on their side and is failing over.',
                'summary' => 'Acquiring bank switch outage',
                'workaround' => 'Routed Visa traffic to the secondary acquirer',
                'permanent_fix' => 'Automatic failover to the secondary acquirer when the decline rate passes 20%',
                'fix_type' => FixType::Vendor,
                'steps' => ['Confirmed decline code 91 across issuers', 'Called the acquirer NOC', 'Switched routing to the secondary acquirer'],
                'verification' => 'Visa approval rate back to 94%',
            ],
            [
                'service' => 'Mobile money integration', 'category' => 'Third-party', 'severity' => 2,
                'title' => 'MTN MoMo collections timing out',
                'description' => 'Collection requests to MTN time out after 30 seconds.',
                'impact' => 'MTN customers cannot pay',
                'update' => 'MTN switched to queued mode; provider ticket opened.',
                'summary' => 'Provider API degraded during their maintenance window',
                'workaround' => 'Queued collections and retried them every 2 minutes',
                'permanent_fix' => 'Subscribed to provider maintenance notices and added a circuit breaker',
                'fix_type' => FixType::Vendor,
                'steps' => ['Confirmed timeouts on the provider dashboard', 'Switched MTN to queued mode', 'Opened a ticket with MTN'],
                'verification' => 'Queued collections all completed after recovery',
            ],
            [
                'service' => 'Mobile money integration', 'category' => 'Application', 'severity' => 3,
                'title' => 'Airtel Money disbursements failing with signature error',
                'description' => 'Disbursements return "invalid signature" since this morning.',
                'impact' => 'Payouts to Airtel wallets delayed',
                'update' => 'Found an expired signing key; rotating.',
                'summary' => 'Signing key for the Airtel API expired',
                'workaround' => 'Paused disbursements',
                'permanent_fix' => 'Rotated the key and added an expiry reminder 30 days ahead',
                'fix_type' => FixType::ConfigChange,
                'steps' => ['Reproduced the signature error', 'Generated and uploaded a new key pair', 'Resumed disbursements'],
                'verification' => 'Test and live disbursements succeeded',
            ],
            [
                'service' => 'Customer portal', 'category' => 'Application', 'severity' => 3,
                'title' => 'Customer portal slow to load statements',
                'description' => 'Statement pages take more than 20 seconds to load.',
                'impact' => 'Customers cannot download statements quickly',
                'update' => 'Missing index identified on the transactions table.',
                'summary' => 'Statement query did a full table scan after a schema change',
                'workaround' => 'Increased the page timeout',
                'permanent_fix' => 'Added a composite index on (account_id, created_at)',
                'fix_type' => FixType::CodeChange, 'pr_title' => 'Index transactions by account and date',
                'steps' => ['Profiled the slow query', 'Added the index in a migration', 'Deployed and checked response times'],
                'verification' => 'Statements load in under 2 seconds',
            ],
            [
                'service' => 'Customer portal', 'category' => 'Application', 'severity' => 2,
                'title' => 'Portal returns 500 errors after deploy',
                'description' => 'Login page and dashboard return HTTP 500 since the 14:00 release.',
                'impact' => 'Customers cannot use the portal',
                'update' => 'Rolling back to the previous release.',
                'summary' => 'Release shipped with a missing environment variable',
                'workaround' => null,
                'permanent_fix' => 'Rolled back, then added a config check to the deploy pipeline',
                'fix_type' => FixType::Rollback,
                'steps' => ['Checked error logs', 'Rolled back the release', 'Added the missing variable and redeployed'],
                'verification' => 'Error rate back to 0.1%',
            ],
            [
                'service' => 'Core database cluster', 'category' => 'Database', 'severity' => 2,
                'title' => 'Replica lag above 10 minutes on db-replica-2',
                'description' => 'Reads from the replica return stale data; lag keeps growing.',
                'impact' => 'Balances shown to customers are out of date',
                'update' => 'Long-running reporting query killed; lag falling.',
                'summary' => 'Reporting query on the replica blocked replication',
                'workaround' => 'Killed the query and moved reads to db-replica-1',
                'permanent_fix' => 'Moved reporting jobs to the dedicated reporting replica',
                'fix_type' => FixType::ConfigChange,
                'steps' => ['Checked SHOW REPLICA STATUS', 'Killed the blocking query', 'Updated the reporting connection string'],
                'verification' => 'Lag under 2 seconds for 24 hours',
            ],
            [
                'service' => 'Core database cluster', 'category' => 'Database', 'severity' => 1,
                'title' => 'Primary database disk almost full',
                'description' => 'Disk usage on db-primary at 97% and climbing.',
                'impact' => 'Writes will fail when the disk fills',
                'update' => 'Purged old binary logs; extending the volume.',
                'summary' => 'Binary logs were not being purged after a config change',
                'workaround' => 'Purged binary logs older than 3 days',
                'permanent_fix' => 'Set binlog_expire_logs_seconds and added a disk alert at 80%',
                'fix_type' => FixType::Infrastructure,
                'steps' => ['Found binary logs using 400 GB', 'Purged old logs', 'Extended the volume by 200 GB'],
                'verification' => 'Disk usage at 61% and stable',
            ],
            [
                'service' => 'Reporting and BI', 'category' => 'Database', 'severity' => 4,
                'title' => 'Nightly finance report missing yesterday\'s data',
                'description' => 'The 06:00 finance report has no rows for yesterday.',
                'impact' => 'Finance cannot reconcile on time',
                'update' => 'ETL job failed on a schema change; rerunning.',
                'summary' => 'ETL job failed after a column was renamed upstream',
                'workaround' => 'Reran the job manually',
                'permanent_fix' => 'Updated the ETL mapping and added a row-count check',
                'fix_type' => FixType::DataFix,
                'steps' => ['Checked the ETL logs', 'Fixed the column mapping', 'Reran the job and resent the report'],
                'verification' => 'Report row counts match the source',
            ],
            [
                'service' => 'Network and VPN', 'category' => 'Network', 'severity' => 2,
                'title' => 'Site-to-site VPN to the Ndola office down',
                'description' => 'Ndola staff cannot reach internal systems.',
                'impact' => 'Ndola branch offline for internal tools',
                'update' => 'Tunnel restarted on the backup link.',
                'summary' => 'ISP changed the branch public IP',
                'workaround' => 'Brought the tunnel up on the backup link',
                'permanent_fix' => 'Requested a static IP from the ISP',
                'fix_type' => FixType::Infrastructure,
                'steps' => ['Checked phase 1 status on the firewall', 'Updated the peer address', 'Restarted the tunnel'],
                'verification' => 'Branch staff confirmed access',
            ],
            [
                'service' => 'Network and VPN', 'category' => 'Network', 'severity' => 3,
                'title' => 'Intermittent packet loss in the Lusaka office',
                'description' => 'Calls drop and pages time out a few times an hour.',
                'impact' => 'Staff in Lusaka have unreliable connectivity',
                'update' => 'Faulty switch port identified.',
                'summary' => 'Failing uplink port on the core switch',
                'workaround' => 'Moved the uplink to a spare port',
                'permanent_fix' => 'Replaced the switch module',
                'fix_type' => FixType::Infrastructure,
                'steps' => ['Ran continuous pings to find the loss', 'Checked interface error counters', 'Moved the uplink'],
                'verification' => 'No packet loss over 24 hours',
            ],
            [
                'service' => 'Email and notifications', 'category' => 'Infrastructure', 'severity' => 3,
                'title' => 'OTP SMS messages delayed by 10 minutes',
                'description' => 'Customers receive one-time passwords long after they expire.',
                'impact' => 'Customers cannot complete sign-in or payments that need an OTP',
                'update' => 'Switched OTP traffic to the backup SMS provider.',
                'summary' => 'Primary SMS aggregator throttled our sender ID',
                'workaround' => 'Routed OTP messages through the backup provider',
                'permanent_fix' => 'Raised our throughput limit with the aggregator',
                'fix_type' => FixType::Vendor,
                'steps' => ['Measured delivery delay', 'Switched to the backup provider', 'Agreed a higher limit with the aggregator'],
                'verification' => 'OTP delivery under 10 seconds',
            ],
            [
                'service' => 'Email and notifications', 'category' => 'Infrastructure', 'severity' => 4,
                'title' => 'Statement emails going to spam',
                'description' => 'Customers report monthly statements land in spam folders.',
                'impact' => 'Customers miss their statements',
                'update' => 'DKIM record missing for the new sending domain.',
                'summary' => 'DKIM was not set up for the new sending domain',
                'workaround' => null,
                'permanent_fix' => 'Published DKIM and DMARC records',
                'fix_type' => FixType::ConfigChange,
                'steps' => ['Checked mail headers', 'Published the DNS records', 'Sent test emails to major providers'],
                'verification' => 'Test emails land in the inbox',
            ],
            [
                'service' => 'Identity and SSO', 'category' => 'Access', 'severity' => 2,
                'title' => 'Staff cannot sign in with SSO',
                'description' => 'SSO login fails with "invalid signature" for all internal apps.',
                'impact' => 'Staff locked out of internal tools',
                'update' => 'IdP signing certificate expired; rotating.',
                'summary' => 'IdP signing certificate expired',
                'workaround' => 'Enabled local admin login for critical tools',
                'permanent_fix' => 'Rotated the certificate and added an expiry alert 30 days ahead',
                'fix_type' => FixType::ConfigChange,
                'steps' => ['Confirmed the certificate expiry', 'Generated a new certificate', 'Uploaded new metadata to each app'],
                'verification' => 'All apps accept SSO logins',
            ],
            [
                'service' => 'Identity and SSO', 'category' => 'Security', 'severity' => 2,
                'title' => 'Suspicious login attempts on admin accounts',
                'description' => 'Hundreds of failed logins on admin accounts from foreign IPs.',
                'impact' => 'Risk of account takeover',
                'update' => 'Source IPs blocked; forcing password resets.',
                'summary' => 'Credential stuffing attack against admin accounts',
                'workaround' => 'Blocked the source IP ranges at the firewall',
                'permanent_fix' => 'Enforced MFA for all admin accounts and added login rate limits',
                'fix_type' => FixType::ConfigChange,
                'steps' => ['Reviewed the auth logs', 'Blocked the IP ranges', 'Forced password resets and enabled MFA'],
                'verification' => 'No further failed logins from those ranges',
            ],
            [
                'service' => 'Monitoring and logging', 'category' => 'Infrastructure', 'severity' => 4,
                'title' => 'Log search returning no results for the last hour',
                'description' => 'Kibana shows no logs since 10:00.',
                'impact' => 'Responders cannot search recent logs',
                'update' => 'Log shipper restarted; backlog catching up.',
                'summary' => 'Log shipper crashed after running out of memory',
                'workaround' => 'Restarted the shipper',
                'permanent_fix' => 'Raised the memory limit and added a heartbeat alert',
                'fix_type' => FixType::Infrastructure,
                'steps' => ['Checked the shipper status', 'Restarted it with more memory', 'Confirmed the backlog drained'],
                'verification' => 'Logs arriving within 30 seconds',
            ],
            [
                'service' => 'Customer portal', 'category' => 'Access', 'severity' => 4,
                'title' => 'Password reset emails not arriving',
                'description' => 'A few customers say password reset emails never arrive.',
                'impact' => 'Some customers cannot reset their password',
                'update' => 'Addresses were on the bounce suppression list.',
                'summary' => 'Addresses on the suppression list after an old bounce',
                'workaround' => 'Removed the addresses from the suppression list',
                'permanent_fix' => 'No change needed; support was given steps to check the list',
                'fix_type' => FixType::NoAction,
                'steps' => ['Checked the email provider logs', 'Removed the suppressed addresses'],
                'verification' => 'Customers received the reset emails',
            ],
        ];
    }
}

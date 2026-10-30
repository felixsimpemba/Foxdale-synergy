<?php

use App\Enums\ServiceCriticality;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

describe('incidents:create-admin', function () {
    it('creates an admin with the given password', function () {
        $exit = Artisan::call('incidents:create-admin', [
            'email' => 'Grace.Mwansa@Example.com',
            '--name' => 'Grace Mwansa',
            '--password' => 'a-long-and-strong-password',
        ]);

        $user = User::sole();

        expect($exit)->toBe(0)
            ->and($user->email)->toBe('grace.mwansa@example.com')
            ->and($user->name)->toBe('Grace Mwansa')
            ->and($user->hasRole('admin'))->toBeTrue()
            ->and($user->is_active)->toBeTrue()
            ->and(Hash::check('a-long-and-strong-password', $user->password))->toBeTrue();
    });

    it('generates and prints a password once', function () {
        Artisan::call('incidents:create-admin', ['email' => 'admin@example.com']);
        $output = Artisan::output();

        expect($output)->toMatch('/Password: (\S+)/');

        preg_match('/Password: (\S+)/', $output, $matches);
        expect(Hash::check($matches[1], User::sole()->password))->toBeTrue();
    });

    it('promotes an existing account without touching its password', function () {
        $user = User::factory()->create(['password' => Hash::make('existing-password'), 'is_active' => false]);
        $before = $user->password;

        Artisan::call('incidents:create-admin', ['email' => $user->email]);

        expect($user->fresh()->hasRole('admin'))->toBeTrue()
            ->and($user->fresh()->password)->toBe($before)
            ->and($user->fresh()->is_active)->toBeTrue();
    });

    it('rejects an invalid email', function () {
        expect(Artisan::call('incidents:create-admin', ['email' => 'not-an-email']))->not->toBe(0)
            ->and(User::count())->toBe(0);
    });
});

/**
 * Writes a CSV file for the import command and returns its path.
 */
function serviceCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'services').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

describe('incidents:import-services', function () {
    it('creates services with their team and criticality, and updates them on a second run', function () {
        $team = Team::factory()->create(['name' => 'Payments']);
        $path = serviceCsv(Str::of("name,description,owner_team,criticality,runbook_url\n")
            ."Payment gateway,Card payments,Payments,critical,https://wiki/payments\n"
            ."Customer portal,Self-service,,high,\n");

        expect(Artisan::call('incidents:import-services', ['file' => $path]))->toBe(0);

        $gateway = Service::where('name', 'Payment gateway')->sole();
        expect(Service::count())->toBe(2)
            ->and($gateway->owner_team_id)->toBe($team->id)
            ->and($gateway->criticality)->toBe(ServiceCriticality::Critical)
            ->and($gateway->runbook_url)->toBe('https://wiki/payments')
            ->and(Service::where('name', 'Customer portal')->sole()->owner_team_id)->toBeNull();

        // Second run with a changed description updates instead of duplicating.
        file_put_contents($path, "name,description,owner_team,criticality\nPayment gateway,Card and bank payments,Payments,high\n");
        expect(Artisan::call('incidents:import-services', ['file' => $path]))->toBe(0);

        expect(Service::count())->toBe(2)
            ->and($gateway->fresh()->description)->toBe('Card and bank payments')
            ->and($gateway->fresh()->criticality)->toBe(ServiceCriticality::High);
    });

    it('creates missing teams only when asked', function () {
        $path = serviceCsv("name,owner_team\nDatabase,DB Team\n");

        expect(Artisan::call('incidents:import-services', ['file' => $path]))->toBe(1);
        expect(Team::where('name', 'DB Team')->exists())->toBeFalse()
            ->and(Service::count())->toBe(0);

        expect(Artisan::call('incidents:import-services', ['file' => $path, '--create-teams' => true]))->toBe(0);
        expect(Team::where('name', 'DB Team')->exists())->toBeTrue()
            ->and(Service::sole()->ownerTeam?->name)->toBe('DB Team');
    });

    it('reports bad rows with line numbers and still imports the good ones', function () {
        $path = serviceCsv(Str::of("Name,Description,Criticality\n")
            ."Good service,Fine,medium\n"
            .",No name,medium\n"
            ."Odd service,Weird,urgent\n");

        expect(Artisan::call('incidents:import-services', ['file' => $path]))->toBe(1);
        $output = Artisan::output();

        expect($output)->toContain('Line 3: the name is empty.')
            ->and($output)->toContain('Line 4: criticality "urgent" must be one of')
            ->and(Service::where('name', 'Good service')->exists())->toBeTrue()
            ->and(Service::where('name', 'Odd service')->exists())->toBeFalse();
    });

    it('writes nothing on a dry run', function () {
        $path = serviceCsv("name\nEmail service\n");

        expect(Artisan::call('incidents:import-services', ['file' => $path, '--dry-run' => true]))->toBe(0)
            ->and(Service::count())->toBe(0)
            ->and(Artisan::output())->toContain('Would create Email service');
    });
});

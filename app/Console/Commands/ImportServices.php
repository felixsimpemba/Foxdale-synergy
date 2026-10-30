<?php

namespace App\Console\Commands;

use App\Enums\ServiceCriticality;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Imports the real service catalog from a CSV file, so a deployment does not start with
 * hand-typed services.
 *
 * Columns: name (required), description, owner_team, criticality (critical|high|medium|low),
 * runbook_url. The header row names the columns; order does not matter. Existing services are
 * matched by name and updated.
 * testing
 */
class ImportServices extends Command
{
    protected $signature = 'incidents:import-services
        {file : Path to the CSV file}
        {--create-teams : Create teams that the CSV mentions but the database does not have}
        {--dry-run : Check the file and report what would change without writing}';

    protected $description = 'Import or update services from a CSV file';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error("Cannot open {$path}.");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            $this->error('The file is empty.');

            return self::FAILURE;
        }

        $header = array_map(fn ($column): string => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $column))), $header);
        $index = array_flip($header);

        if (! isset($index['name'])) {
            fclose($handle);
            $this->error('The header row needs a "name" column. Optional: description, owner_team, criticality, runbook_url.');

            return self::FAILURE;
        }

        $criticalities = array_map(fn (ServiceCriticality $case): string => $case->value, ServiceCriticality::cases());
        $teams = Team::query()->get()->keyBy(fn (Team $team): string => mb_strtolower($team->name));
        $dryRun = (bool) $this->option('dry-run');
        $createTeams = (bool) $this->option('create-teams');

        $created = 0;
        $updated = 0;
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }

            $value = fn (string $column): string => isset($index[$column]) ? trim((string) ($row[$index[$column]] ?? '')) : '';

            $name = $value('name');

            if ($name === '') {
                $errors[] = "Line {$line}: the name is empty.";

                continue;
            }

            if (mb_strlen($name) > 255) {
                $errors[] = "Line {$line}: the name is longer than 255 characters.";

                continue;
            }

            $criticalityValue = $value('criticality') !== '' ? mb_strtolower($value('criticality')) : 'medium';

            if (! in_array($criticalityValue, $criticalities, true)) {
                $errors[] = "Line {$line}: criticality \"{$criticalityValue}\" must be one of ".implode(', ', $criticalities).'.';

                continue;
            }

            $teamName = $value('owner_team');
            $team = $teamName === '' ? null : $teams->get(mb_strtolower($teamName));

            if ($teamName !== '' && $team === null) {
                if (! $createTeams) {
                    $errors[] = "Line {$line}: team \"{$teamName}\" does not exist. Create it under Administration > Teams, or use --create-teams.";

                    continue;
                }

                if (! $dryRun) {
                    $team = Team::create(['name' => $teamName]);
                }

                $this->line("Line {$line}: team \"{$teamName}\" would be created.");
            }

            $attributes = [
                'description' => $value('description') ?: null,
                'owner_team_id' => $team?->id,
                'criticality' => $criticalityValue,
                'runbook_url' => $value('runbook_url') ?: null,
            ];

            if ($dryRun) {
                $exists = Service::query()->where('name', $name)->exists();
                $this->line(($exists ? 'Would update ' : 'Would create ').$name);
                $exists ? $updated++ : $created++;

                continue;
            }

            $service = Service::query()->firstOrNew(['name' => $name]);
            $isNew = ! $service->exists;
            $service->fill($attributes)->save();

            $isNew ? $created++ : $updated++;
        }

        fclose($handle);

        $this->newLine();
        $this->info(($dryRun ? 'Dry run: ' : '')."{$created} created, {$updated} updated, ".count($errors).' problem(s).');

        foreach ($errors as $error) {
            $this->error($error);
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}

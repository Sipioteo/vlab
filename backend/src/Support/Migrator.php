<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Hand-rolled migration runner (SPEC §1.4). Reads ordered files from
 * database/migrations/ and records applied names in the `migrations` table.
 */
final class Migrator
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 2) . '/database/migrations';
    }

    private function ensureTable(): void
    {
        if (!Capsule::schema()->hasTable('migrations')) {
            Capsule::schema()->create('migrations', function ($t) {
                $t->id();
                $t->string('migration', 191)->unique();
                $t->integer('batch');
                $t->dateTime('ran_at');
            });
        }
    }

    /** @return string[] applied migration names, in order */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = Capsule::table('migrations')->pluck('migration')->all();
        $batch = ((int) Capsule::table('migrations')->max('batch')) + 1;
        $ran = [];
        foreach ($this->files() as $name => $file) {
            if (in_array($name, $applied, true)) {
                continue;
            }
            $migration = require $file;
            $migration->up();
            Capsule::table('migrations')->insert([
                'migration' => $name,
                'batch' => $batch,
                'ran_at' => Dates::nowDb(),
            ]);
            $ran[] = $name;
        }
        return $ran;
    }

    public function fresh(): array
    {
        $connection = Capsule::connection();
        $driver = $connection->getDriverName();
        $schema = Capsule::schema();
        if ($driver === 'sqlite') {
            $connection->statement('PRAGMA foreign_keys=OFF');
        }
        // Drop in reverse creation order to satisfy FKs on non-sqlite drivers.
        $files = array_reverse($this->files(), true);
        foreach ($files as $name => $file) {
            $migration = require $file;
            if (method_exists($migration, 'down')) {
                $migration->down();
            }
        }
        if ($schema->hasTable('migrations')) {
            $schema->drop('migrations');
        }
        if ($driver === 'sqlite') {
            $connection->statement('PRAGMA foreign_keys=ON');
        }
        return $this->migrate();
    }

    public function appliedCount(): int
    {
        if (!Capsule::schema()->hasTable('migrations')) {
            return 0;
        }
        return (int) Capsule::table('migrations')->count();
    }

    /** @return array<string,string> name => full path, sorted */
    private function files(): array
    {
        $out = [];
        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            $out[basename($file, '.php')] = $file;
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}

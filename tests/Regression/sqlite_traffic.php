<?php

// No production configuration, Redis or database is used.
// Run: php tests/Regression/sqlite_traffic.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;

$checks = 0;
function verify($expected, $actual, string $message): void {
    global $checks;
    if ($expected !== $actual) throw new RuntimeException($message);
    $checks++;
}

function isolatedDatabase(string $path): array {
    $container = new Container();
    Container::setInstance($container);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $container->instance('app', $container);
    $container->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
    $cache = new class {
        public array $forgotten = [];
        public function forget($key): void { $this->forgotten[] = $key; }
    };
    $redis = new class {
        public array $pending = [];
        public function sadd($key, ...$users): void { $this->pending = array_merge($this->pending, $users); }
    };
    $log = new class {
        public array $errors = [];
        public function error($message, $context = []): void { $this->errors[] = $message; }
    };
    foreach (['cache' => $cache, 'redis' => $redis, 'log' => $log] as $key => $instance) $container->instance($key, $instance);
    $manager = new Manager($container);
    $manager->addConnection([
        'driver' => 'sqlite', 'database' => $path, 'prefix' => '',
        'journal_mode' => 'wal', 'busy_timeout' => 10000, 'synchronous' => 'normal',
        'foreign_key_constraints' => true,
    ]);
    $manager->setAsGlobal();
    $manager->bootEloquent();
    $container->instance('db', $manager->getDatabaseManager());
    $manager->getConnection()->setTransactionManager(new DatabaseTransactionsManager());
    return [$manager, $container, $cache, $redis, $log];
}

$directory = sys_get_temp_dir() . '/xboard-sqlite-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/fixture.sqlite';
touch($path);
try {
    [$db, $app, $cache, $redis, $log] = isolatedDatabase($path);
    $db->schema()->create('v2_plan', function ($table) {
        $table->increments('id'); $table->integer('reset_traffic_method');
    });
    $db->schema()->create('v2_user', function ($table) {
        $table->increments('id'); $table->integer('plan_id'); $table->integer('u'); $table->integer('d');
        $table->string('token'); $table->integer('banned')->default(0);
        $table->integer('expired_at')->nullable(); $table->integer('transfer_enable');
        $table->integer('next_reset_at')->nullable(); $table->integer('last_reset_at')->nullable();
        $table->integer('reset_count')->default(0); $table->integer('t')->nullable();
        $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
    });
    $db->schema()->create('v2_traffic_reset_logs', function ($table) {
        $table->increments('id'); $table->integer('user_id'); $table->string('reset_type');
        $table->dateTime('reset_time'); $table->string('trigger_source');
        foreach (['old_upload','old_download','old_total','new_upload','new_download','new_total'] as $column) $table->integer($column);
        $table->text('metadata')->nullable(); $table->timestamps();
    });
    $db->table('v2_plan')->insert([['id' => 1, 'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH], ['id' => 2, 'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_YEAR]]);
    $row = ['plan_id' => 1, 'u' => 100, 'd' => 200, 'token' => str_repeat('a', 32), 'banned' => 0,
        'expired_at' => time() + 86400 * 365, 'transfer_enable' => 1000000, 'next_reset_at' => time() - 60, 'reset_count' => 0];
    foreach (range(1, 6) as $id) $db->table('v2_user')->insert(['id' => $id] + $row);
    $events = 0;
    HookManager::register('traffic.reset.after', function () use (&$events) { $events++; });
    $service = new TrafficResetService();

    // Stale callbacks cannot erase traffic recorded after a completed reset.
    $first = User::findOrFail(1); $stale = User::findOrFail(1);
    verify(true, $service->checkAndReset($first), 'First due reset must succeed');
    $db->table('v2_user')->where('id', 1)->update(['u' => 57]);
    verify(false, $service->checkAndReset($stale), 'Stale scheduled reset must not repeat');
    verify(57, User::findOrFail(1)->u, 'New traffic must survive a stale reset callback');
    verify(1, User::findOrFail(1)->reset_count, 'Reset counter must increment once');
    verify(1, TrafficResetLog::where('user_id', 1)->count(), 'Reset log must be recorded once');
    verify(1, $events, 'Hook must run once after commit');

    // A purchase can modify plan/expiry/quota before it calls performReset.
    $purchase = User::findOrFail(2);
    $purchase->plan_id = 2; $purchase->transfer_enable = 5000000; $purchase->expired_at += 86400;
    $db->table('v2_user')->where('id', 2)->update(['u' => 400, 'd' => 600]);
    verify(true, $service->performReset($purchase, TrafficResetLog::SOURCE_ORDER), 'Purchase reset must succeed');
    $fresh = User::findOrFail(2);
    verify(2, $fresh->plan_id, 'Dirty purchased plan must survive reloading');
    verify(5000000, $fresh->transfer_enable, 'Dirty quota must survive reloading');
    verify($row['expired_at'] + 86400, $fresh->expired_at, 'Dirty expiry must survive reloading');
    verify(1000, TrafficResetLog::where('user_id', 2)->firstOrFail()->old_total, 'Reset log must use current database traffic');

    // Actual outer commit/rollback; no test-framework wrapping transaction.
    $beforeEvents = $events; $beforeCache = count($cache->forgotten);
    $db->getConnection()->beginTransaction();
    verify(true, $service->checkAndReset(User::findOrFail(3)), 'Nested reset must succeed before rollback');
    verify($beforeEvents, $events, 'Hook must wait for outer commit');
    verify($beforeCache, count($cache->forgotten), 'Cache must wait for outer commit');
    $db->getConnection()->rollBack();
    verify(100, User::findOrFail(3)->u, 'Outer rollback must restore traffic');
    verify(0, User::findOrFail(3)->reset_count, 'Outer rollback must restore reset counter');
    verify(0, TrafficResetLog::where('user_id', 3)->count(), 'Outer rollback must remove reset log');
    verify($beforeEvents, $events, 'Rolled-back reset must not invoke hooks');
    $db->getConnection()->beginTransaction();
    verify(true, $service->checkAndReset(User::findOrFail(3)), 'Nested reset must succeed before commit');
    verify($beforeEvents, $events, 'Nested callback must remain deferred');
    $db->getConnection()->commit();
    verify($beforeEvents + 1, $events, 'Outer commit must invoke hook once');
    verify($beforeCache + 3, count($cache->forgotten), 'Outer commit must invalidate three cache keys');

    HookManager::register('traffic.reset.after', function () { throw new RuntimeException('synthetic callback failure'); }, 30);
    verify(true, $service->checkAndReset(User::findOrFail(4)), 'Post-commit callback failure must not turn reset into a failure');
    verify(1, User::findOrFail(4)->reset_count, 'Callback failure must leave committed reset intact');
    verify(1, count($log->errors), 'Callback failure must be logged once');
    $app->instance('hook.actions', []);

    // Exercise actual SQLite storage types at fractional rates.
    $db->table('v2_user')->where('id', 5)->update(['u' => 0, 'd' => 0]);
    (new TrafficFetchJob(['rate' => 0.3], [5 => [1234, 2345]], 'vless', time()))->handle();
    $usage = $db->table('v2_user')->where('id', 5)->selectRaw('u,d,typeof(u) as ut,typeof(d) as dt')->first();
    verify(370, $usage->u, 'Fractional-rate upload must be integer bytes');
    verify(703, $usage->d, 'Fractional-rate download must be integer bytes');
    verify('integer', $usage->ut, 'SQLite upload storage type must be integer');
    verify('integer', $usage->dt, 'SQLite download storage type must be integer');
    verify([5], $redis->pending, 'Quota-check queue must receive the user');

    // Two independent writer processes load stale models before contending.
    if (!function_exists('pcntl_fork')) throw new RuntimeException('pcntl is required for the parallel SQLite regression');
    $db->getConnection()->disconnect();
    $children = [];
    for ($worker = 0; $worker < 2; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Unable to fork SQLite worker');
        if ($pid === 0) {
            try {
                [$childDb] = isolatedDatabase($path);
                $user = User::findOrFail(6);
                touch($directory . '/ready-' . $worker);
                $deadline = microtime(true) + 10;
                while (!is_file($directory . '/go')) {
                    if (microtime(true) > $deadline) throw new RuntimeException('Writer barrier timeout');
                    usleep(1000);
                }
                $success = (new TrafficResetService())->checkAndReset($user);
                file_put_contents($directory . '/result-' . $worker, json_encode(['success' => $success]));
                $childDb->getConnection()->disconnect();
                exit(0);
            } catch (Throwable $error) {
                file_put_contents($directory . '/result-' . $worker, json_encode(['error' => $error::class]));
                exit(1);
            }
        }
        $children[] = $pid;
    }
    $deadline = microtime(true) + 10;
    while (!is_file($directory . '/ready-0') || !is_file($directory . '/ready-1')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Writer startup timeout');
        usleep(1000);
    }
    touch($directory . '/go');
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        verify(0, pcntl_wexitstatus($status), 'Parallel writer must complete without a lock error');
    }
    $results = [];
    foreach ([0, 1] as $worker) $results[] = json_decode(file_get_contents($directory . '/result-' . $worker), true)['success'];
    sort($results);
    verify([false, true], $results, 'Exactly one parallel scheduled reset may succeed');
    [$db] = isolatedDatabase($path);
    verify(1, User::findOrFail(6)->reset_count, 'Parallel reset counter must increment once');
    verify(1, TrafficResetLog::where('user_id', 6)->count(), 'Parallel reset log must be unique');
    verify(0, User::findOrFail(6)->u, 'Parallel reset must clear old traffic');
    verify('wal', $db->getConnection()->selectOne('PRAGMA journal_mode')->journal_mode, 'Test must use WAL');
    $db->getConnection()->disconnect();
    echo json_encode(['checks' => $checks, 'passed' => true, 'database' => 'temporary SQLite WAL', 'parallel_writers' => 2]) . PHP_EOL;
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}

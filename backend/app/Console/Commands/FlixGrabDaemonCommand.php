<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class FlixGrabDaemonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flixgrab:daemon {action=status : Acción (status, start, stop, restart, run-once)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Controlador del servicio en segundo plano FlixGrab OTT Daemon';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = strtolower($this->argument('action'));
        $daemonScript = base_path('../daemon/flixgrab_daemon.py');

        if (!file_exists($daemonScript)) {
            $daemonScript = base_path('daemon/flixgrab_daemon.py');
        }

        $this->info("Controlando FlixGrab OTT Daemon -> Acción: [{$action}]");

        $pythonBin = 'python';
        if (PHP_OS_FAMILY === 'Windows') {
            $pythonBin = 'python';
        } else {
            $pythonBin = file_exists('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
        }

        $command = "{$pythonBin} \"{$daemonScript}\" {$action}";
        $process = Process::fromShellCommandLine($command);
        $process->setTimeout(60);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}

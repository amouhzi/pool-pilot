<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:create',
    description: 'Creates a new system user, directory, and PHP-FPM pool with auto-detected PHP version.',
)]
final class CreateUserCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The name of the application');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appName = $input->getArgument('name');
        $filesystem = new Filesystem();

        // Detect current PHP version (e.g., "8.2")
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $output->writeln("<info>Detected PHP version: $phpVersion</info>");

        // 1. Create System User if it doesn't exist
        if ($this->userExists($appName)) {
            $output->writeln("<comment>User '$appName' already exists. Skipping creation.</comment>");
        } else {
            $output->writeln("<info>Creating system user '$appName'...</info>");
            $this->runProcess(['useradd', '-m', '-s', '/bin/false', $appName], $output);
        }

        // 2. Setup Directories
        $output->writeln("<info>Setting up directories...</info>");
        $webDir = "/var/www/$appName/public";
        $filesystem->mkdir($webDir);
        $this->runProcess(['chown', '-R', "$appName:$appName", "/var/www/$appName"], $output);
        $this->runProcess(['chmod', '-R', '755', "/var/www/$appName"], $output);

        // 3. Generate PHP-FPM Pool Configuration from www.conf template
        $output->writeln("<info>Generating PHP-FPM pool configuration...</info>");
        $templatePath = "/etc/php/$phpVersion/fpm/pool.d/www.conf";
        if (!$filesystem->exists($templatePath)) {
            throw new \RuntimeException("Default pool template '$templatePath' not found.");
        }

        $poolConfig = file_get_contents($templatePath);
        if ($poolConfig === false) {
            throw new \RuntimeException("Could not read template file '$templatePath'.");
        }

        // Use regex to replace key directives, assuming www-data is the user/group in www.conf
        $replacements = [
            '/^\[www\]/m' => "[$appName]",
            '/^user\s*=\s*www-data/m' => "user = $appName",
            '/^group\s*=\s*www-data/m' => "group = $appName",
            '/^listen\s*=.+$/m' => "listen = /run/php/php$phpVersion-fpm-$appName.sock",
        ];

        $poolConfig = preg_replace(array_keys($replacements), array_values($replacements), $poolConfig, 1);

        // Ensure listen ownership is set for socket access by the web server
        if (!str_contains($poolConfig, 'listen.owner')) {
            $poolConfig .= "\nlisten.owner = www-data";
        }
        if (!str_contains($poolConfig, 'listen.group')) {
            $poolConfig .= "\nlisten.group = www-data";
        }

        $poolPath = "/etc/php/$phpVersion/fpm/pool.d/$appName.conf";
        $filesystem->dumpFile($poolPath, $poolConfig);
        $output->writeln("<info>Pool configuration created at $poolPath using www.conf as a template.</info>");

        // 4. Restart PHP-FPM Service
        $output->writeln("<info>Restarting PHP-FPM service...</info>");
        $serviceName = "php$phpVersion-fpm";
        $this->runProcess(['systemctl', 'restart', $serviceName], $output);

        $output->writeln("<success>Application setup complete. Service $serviceName restarted.</success>");

        return Command::SUCCESS;
    }

    private function runProcess(array $command, OutputInterface $output): void
    {
        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            $output->writeln("<error>Error executing: " . $process->getCommandLine() . "</error>");
            throw new \RuntimeException($process->getErrorOutput());
        }
    }

    private function userExists(string $username): bool
    {
        $process = new Process(['id', '-u', $username]);
        $process->run();

        return $process->isSuccessful();
    }
}

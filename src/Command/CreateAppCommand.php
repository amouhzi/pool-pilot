<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:create',
    description: 'Creates a new system user, directory, PHP-FPM pool, and Nginx site with auto-detected PHP version.',
)]
final class CreateAppCommand extends Command
{
    private readonly bool $isRoot;

    public function __construct()
    {
        parent::__construct();
        $this->isRoot = (function_exists('posix_getuid') && posix_getuid() === 0);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the application')
            ->addArgument('domain', InputArgument::REQUIRED, 'The domain name for the application');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $appName = $input->getArgument('name');
        $domain = $input->getArgument('domain');
        $filesystem = new Filesystem();

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $io->writeln("<info>Detected PHP version: $phpVersion</info>");

        if ($this->userExists($appName)) {
            $io->writeln("<comment>User '$appName' already exists. Skipping.</comment>");
        } else {
            $io->write("<info>Creating system user '$appName'...</info>");
            $this->runProcess(['useradd', '-m', '-s', '/bin/false', $appName]);
            $io->writeln(" <info>[OK]</info>");
        }

        $this->setupSshKeys($appName, $io);

        $baseDir = "/var/www/$appName";
        $io->write("<info>Creating base directory $baseDir...</info>");
        $this->runProcess(['mkdir', '-p', $baseDir]);
        $io->writeln(" <info>[OK]</info>");

        $io->write("<info>Setting directory ownership...</info>");
        $this->runProcess(['chown', '-R', "$appName:$appName", $baseDir]);
        $io->writeln(" <info>[OK]</info>");

        $io->write("<info>Setting directory permissions...</info>");
        $this->runProcess(['chmod', '-R', '755', $baseDir]);
        $io->writeln(" <info>[OK]</info>");

        $templatePath = "/etc/php/$phpVersion/fpm/pool.d/www.conf";
        if (!$filesystem->exists($templatePath)) {
            throw new \RuntimeException("Default pool template '$templatePath' not found.");
        }
        $poolConfig = file_get_contents($templatePath);
        if ($poolConfig === false) {
            throw new \RuntimeException("Could not read template file '$templatePath'.");
        }

        $replacements = [
            '/^\[www\]/m' => "[$appName]",
            '/^user\s*=\s*www-data/m' => "user = $appName",
            '/^group\s*=\s*www-data/m' => "group = $appName",
            '/^listen\s*=.+$/m' => "listen = /run/php/php$phpVersion-fpm-$appName.sock",
        ];
        $poolConfig = preg_replace(array_keys($replacements), array_values($replacements), $poolConfig, 1);

        if (!str_contains($poolConfig, 'listen.owner')) { $poolConfig .= "\nlisten.owner = www-data"; }
        if (!str_contains($poolConfig, 'listen.group')) { $poolConfig .= "\nlisten.group = www-data"; }

        $poolPath = "/etc/php/$phpVersion/fpm/pool.d/$appName.conf";
        $io->write("<info>Creating PHP-FPM pool $poolPath...</info>");
        $this->runProcess(['tee', $poolPath], $poolConfig);
        $io->writeln(" <info>[OK]</info>");

        $nginxConfig = $this->generateNginxConfig($appName, $phpVersion, $domain);
        $nginxPath = "/etc/nginx/sites-available/$appName";
        $io->write("<info>Creating Nginx site configuration $nginxPath...</info>");
        $this->runProcess(['tee', $nginxPath], $nginxConfig);
        $io->writeln(" <info>[OK]</info>");

        $this->enableNginxSite($appName, $filesystem, $io);

        $io->write("<info>Reloading Nginx...</info>");
        $this->runProcess(['systemctl', 'reload', 'nginx']);
        $io->writeln(" <info>[OK]</info>");

        $io->write("<info>Restarting PHP-FPM service...</info>");
        $this->runProcess(['systemctl', 'restart', "php$phpVersion-fpm"]);
        $io->writeln(" <info>[OK]</info>");

        $io->success("Application setup complete for $domain.");
        $io->note("Nginx is configured to serve from '$baseDir/current/public'. Your deployment tool is responsible for managing the 'current' symlink.");

        return Command::SUCCESS;
    }

    private function runProcess(array $command, ?string $input = null): void
    {
        if (!$this->isRoot) {
            array_unshift($command, 'sudo');
        }

        $process = new Process($command);
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Error executing: '" . $process->getCommandLine() . "'\n" . $process->getErrorOutput()
            );
        }
    }

    private function userExists(string $username): bool
    {
        $process = new Process(['id', '-u', $username]);
        $process->run();
        return $process->isSuccessful();
    }

    private function generateNginxConfig(string $appName, string $phpVersion, string $domain): string
    {
        $webDir = "/var/www/$appName/current/public";
        $socketPath = "/run/php/php$phpVersion-fpm-$appName.sock";

        return <<<EOT
            server {
                server_name $domain;
                root $webDir;

                location / {
                    try_files \$uri /index.php\$is_args\$args;
                }

                location ~ ^/index\.php(/|$) {
                    fastcgi_pass unix:$socketPath;
                    fastcgi_split_path_info ^(.+\.php)(/.*)$;
                    include fastcgi_params;
                    fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
                    fastcgi_param DOCUMENT_ROOT \$realpath_root;
                    internal;
                }

                location ~ \.php$ {
                    return 404;
                }

                error_log /var/log/nginx/{$appName}_error.log;
                access_log /var/log/nginx/{$appName}_access.log;
            }
            EOT;
    }

    private function enableNginxSite(string $appName, Filesystem $filesystem, SymfonyStyle $io): void
    {
        $availableSite = "/etc/nginx/sites-available/$appName";
        $enabledSite = "/etc/nginx/sites-enabled/$appName";

        if ($filesystem->exists($enabledSite)) {
            $io->writeln("<comment>Nginx site '$appName' is already enabled. Skipping.</comment>");
            return;
        }

        $io->write("<info>Enabling Nginx site (creating symlink)...</info>");
        $this->runProcess(['ln', '-s', $availableSite, $enabledSite]);
        $io->writeln(" <info>[OK]</info>");
    }

    private function setupSshKeys(string $appName, SymfonyStyle $io): void
    {
        $homeDir = $this->getInvokingUserHomeDir();
        if ($homeDir === null) {
            $io->warning("Could not determine home directory of invoking user. Skipping SSH key setup.");
            return;
        }

        $authorizedKeysFile = "$homeDir/.ssh/authorized_keys";
        if (!file_exists($authorizedKeysFile)) {
            $io->note("No SSH authorized keys found at '$authorizedKeysFile'. Skipping.");
            return;
        }

        $keys = file_get_contents($authorizedKeysFile);
        if ($keys === false) {
            $io->warning("Could not read authorized keys from '$authorizedKeysFile'. Skipping.");
            return;
        }

        $io->write("<info>Copying SSH authorized keys...</info>");

        $newUserHomeDir = "/home/$appName";
        $newUserSshDir = "$newUserHomeDir/.ssh";
        $newUserAuthorizedKeys = "$newUserSshDir/authorized_keys";

        $this->runProcess(['mkdir', '-p', $newUserSshDir]);
        $this->runProcess(['tee', $newUserAuthorizedKeys], $keys);
        $this->runProcess(['chmod', '700', $newUserSshDir]);
        $this->runProcess(['chmod', '600', $newUserAuthorizedKeys]);
        $this->runProcess(['chown', '-R', "$appName:$appName", $newUserSshDir]);

        $io->writeln(" <info>[OK]</info>");
    }

    private function getInvokingUserHomeDir(): ?string
    {
        $user = $_SERVER['SUDO_USER'] ?? null;
        if ($user !== null) {
            $userInfo = posix_getpwnam($user);
            return $userInfo['dir'] ?? null;
        }

        $userInfo = posix_getpwuid(posix_getuid());
        return $userInfo['dir'] ?? null;
    }
}

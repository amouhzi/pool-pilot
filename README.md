# PoolPilot

PoolPilot is a command-line tool that simplifies infrastructure management for developers running multiple PHP applications on a single Debian/Ubuntu instance. Instead of maintaining a separate LXC container for every project, PoolPilot orchestrates a secure, shared environment using PHP-FPM pools.

This tool automates the manual labor of system administration by handling:

*   **User Isolation**: Creates dedicated system users and groups for each application to enforce strict filesystem permissions.
*   **FPM Configuration**: Generates unique PHP-FPM pool configurations with dynamic socket allocation.
*   **Version Awareness**: Automatically detects the running PHP version (e.g., 8.2, 8.3) to apply the correct paths and service restarts.
*   **Service Management**: Seamless interaction with systemd to reload configurations instantly.

## Installation

### 1. Install PoolPilot

Install PoolPilot globally using Composer:

```bash
composer global require amouhzi/pool-pilot
```

### 2. Update Your System PATH

To run the `pool-pilot` command from anywhere, you must add Composer's global `bin` directory to your system's `PATH`.

First, find the correct directory by running:
```bash
composer global config bin-dir --absolute
```

Now, add the following line to your shell configuration file (e.g., `~/.bashrc`, `~/.zshrc`). Replace `~/.composer/vendor/bin` with the path you found above if it's different.

```bash
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

Finally, reload your shell configuration to apply the changes:

```bash
source ~/.bashrc
# Or for Zsh:
# source ~/.zshrc
```

## Usage

PoolPilot can be run in two modes: as the `root` user for simplicity, or as a non-root user for enhanced security.

### Simple Mode (As Root)

If you are logged in as `root`, you can run the command directly. The tool will handle all system operations without needing `sudo`.

```bash
pool-pilot app:create my-app my-app.com
```

### Secure Mode (As a Non-Root User)

This is the recommended approach for production servers and automated deployments (e.g., with `php-deployer`).

1.  **Create a Deployer User**: If you don't have one, create a dedicated user for deployments.
2.  **Configure Sudo**: The tool will automatically use `sudo` for privileged operations. To allow this without a password, create a sudoers file for your deployer user:

    ```bash
    sudo visudo -f /etc/sudoers.d/deployer
    ```

    Add the following lines, which grant password-less access only to the commands PoolPilot needs:

    ```
    # Allow the deployer user to manage users, services, and files for PoolPilot
    deployer ALL=(ALL) NOPASSWD: /usr/sbin/useradd, /usr/sbin/groupadd, /bin/systemctl, /bin/chown, /bin/chmod, /usr/bin/ln, /usr/bin/mkdir, /usr/bin/tee
    ```
3.  **Run the Command**: As the deployer user, run the command. `sudo` will be invoked automatically where needed.

    ```bash
    pool-pilot app:create my-app my-app.com
    ```

## Contributing

Contributions are welcome! Please feel free to submit a pull request.

## License

This project is licensed under the MIT License.

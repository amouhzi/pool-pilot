# PoolPilot

PoolPilot simplifies infrastructure management for developers running multiple PHP applications on a single Debian/Ubuntu instance. Instead of maintaining a separate LXC container for every project, PoolPilot orchestrates a secure, shared environment using PHP-FPM pools.

This tool automates the manual labor of system administration by handling:

*   **User Isolation**: Creates dedicated system users and groups for each application to enforce strict filesystem permissions.
*   **FPM Configuration**: Generates unique PHP-FPM pool configurations with dynamic socket allocation.
*   **Version Awareness**: Automatically detects the running PHP version (e.g., 8.2, 8.3) to apply the correct paths and service restarts.
*   **Service Management**: Seamless interaction with systemd to reload configurations instantly.

Designed for solo administrators who need the security of isolation with the simplicity of a monolithic server.

## Installation

```bash
composer install
```

## Usage

To run the application, use the `bin/console` script. The following commands are available:

*   `app:create`: Creates a new system user, directory, and PHP-FPM pool with auto-detected PHP version.

```bash
php bin/console app:create my-app
```

## Contributing

Contributions are welcome! Please feel free to submit a pull request.

## License

This project is licensed under the MIT License.

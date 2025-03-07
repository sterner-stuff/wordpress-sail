<?php

namespace SternerStuff\WordPressSail\Console;

use Illuminate\Support\Collection;
use WP_CLI\Utils;

class InstallCommand
{

    /**
     * The available services that may be installed.
     *
     * @var array<string>
     */
    protected $services = [
        'mysql',
        'pgsql',
        'mariadb',
        'redis',
        'memcached',
        'meilisearch',
        'minio',
        'mailhog',
        'selenium',
    ];

    /**
     * Install WordPress Sail's default Docker Compose file
     *
     * ## OPTIONS
     *
     * [--with=<with>]
     * : The services that should be included in the installation.
     *
     * [--devcontainer]
     * : Create a .devcontainer configuration directory. Currently unsupported.
     *
     * ## EXAMPLES
     *
     *     wp sail:install --with=mysql,redis
     *
     * @when before_wp_load
     */
    public function __invoke( $args, $assoc_args )
    {

        if (Utils\get_flag_value($assoc_args, 'with', false)) {
            $services = $assoc_args['with'] == 'none' ? [] : explode(',', $assoc_args['with']);
        // } elseif ($this->option('no-interaction')) {
        //     $services = ['mysql', 'redis', 'selenium', 'mailhog'];
        } else {
            $services = ['mysql', 'mailhog'];
        }

        if ($invalidServices = array_diff($services, $this->services)) {
            \WP_CLI::error('Invalid services ['.implode(',', $invalidServices).'].');

            return 1;
        }

        $this->buildDockerCompose($services);
        $this->replaceEnvVariables($services);
        // $this->configurePhpUnit();

        // if ($this->option('devcontainer')) {
        //     $this->installDevContainer();
        // }

        \WP_CLI::success('Sail scaffolding installed successfully.');

        $this->prepareInstallation($services);
    }

    /**
     * Gather the desired Sail services using a Symfony menu.
     *
     * @return array
     */
    /* protected function gatherServicesWithSymfonyMenu()
    {
        return $this->choice('Which services would you like to install?', $this->services, 0, null, true);
    }
    */

    /**
     * Build the Docker Compose file.
     *
     * @param  array  $services
     * @return void
     */
    protected function buildDockerCompose(array $services)
    {
        $depends = new Collection($services);

        $depends = $depends->filter(function ($service) {
                return in_array($service, ['mysql', 'mariadb', 'redis', 'meilisearch', 'minio', 'selenium']);
            })->map(function ($service) {
                return "            - {$service}";
            })->whenNotEmpty(function ($collection) {
                return $collection->prepend('depends_on:');
            })->implode("\n");
        
        
        $stubs = new Collection($services);

        $stubs = rtrim($stubs->map(function ($service) {
            return file_get_contents(__DIR__ . "/../../stubs/{$service}.stub");
        })->implode(''));

        $volumes = new Collection($services);

        $volumes = $volumes->filter(function ($service) {
                return in_array($service, ['mysql', 'mariadb', 'redis', 'meilisearch', 'minio']);
            })->map(function ($service) {
                return "    sail-{$service}:\n        driver: local";
            })->whenNotEmpty(function ($collection) {
                return $collection->prepend('volumes:');
            })->implode("\n");

        $dockerCompose = file_get_contents(__DIR__ . '/../../stubs/docker-compose.stub');

        $dockerCompose = str_replace('{{depends}}', empty($depends) ? '' : '        '.$depends, $dockerCompose);
        $dockerCompose = str_replace('{{services}}', $stubs, $dockerCompose);
        $dockerCompose = str_replace('{{volumes}}', $volumes, $dockerCompose);

        // Replace Selenium with ARM base container on Apple Silicon...
        if (in_array('selenium', $services) && php_uname('m') === 'arm64') {
            $dockerCompose = str_replace('selenium/standalone-chrome', 'seleniarm/standalone-chromium', $dockerCompose);
        }

        // Remove empty lines...
        $dockerCompose = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $dockerCompose);

        file_put_contents(getcwd() . '/docker-compose.yml', $dockerCompose);
    }

    /**
     * Replace the Host environment variables in the app's .env file.
     *
     * @param  array  $services
     * @return void
     */
    protected function replaceEnvVariables(array $services)
    {
        $environment = file_get_contents(getcwd() . '/.env');
        
        if (in_array('mariadb', $services)) {
            $environment = str_replace(['DB_HOST=127.0.0.1', "# DB_HOST='localhost'", "DB_HOST='localhost'"], "DB_HOST=mariadb", $environment);
        } else {
            $environment = str_replace(['DB_HOST=127.0.0.1', "# DB_HOST='localhost'", "DB_HOST='localhost'"], "DB_HOST=mysql", $environment);
        }

        $environment = str_replace("DB_USER='database_user'", "DB_USER=sail", $environment);
        $environment = preg_replace("/DB_PASSWORD=(.*)/", "DB_PASSWORD=password", $environment);

        $environment = str_replace('MEMCACHED_HOST=127.0.0.1', 'MEMCACHED_HOST=memcached', $environment);
        $environment = str_replace('REDIS_HOST=127.0.0.1', 'REDIS_HOST=redis', $environment);

        if (in_array('meilisearch', $services)) {
            $environment .= "\nSCOUT_DRIVER=meilisearch";
            $environment .= "\nMEILISEARCH_HOST=http://meilisearch:7700\n";
        }

        file_put_contents(getcwd() . '/.env', $environment);
    }

    /**
     * Configure PHPUnit to use the dedicated testing database.
     *
     * @return void
     */
    protected function configurePhpUnit()
    {
        if (! file_exists($path = $this->laravel->basePath('phpunit.xml'))) {
            $path = $this->laravel->basePath('phpunit.xml.dist');
        }

        $phpunit = file_get_contents($path);

        $phpunit = preg_replace('/^.*DB_CONNECTION.*\n/m', '', $phpunit);
        $phpunit = str_replace('<!-- <env name="DB_DATABASE" value=":memory:"/> -->', '<env name="DB_DATABASE" value="testing"/>', $phpunit);

        file_put_contents($this->laravel->basePath('phpunit.xml'), $phpunit);
    }

    /**
     * Install the devcontainer.json configuration file.
     *
     * @return void
     */
    /*
    protected function installDevContainer()
    {
        if (! is_dir($this->laravel->basePath('.devcontainer'))) {
            mkdir($this->laravel->basePath('.devcontainer'), 0755, true);
        }

        file_put_contents(
            $this->laravel->basePath('.devcontainer/devcontainer.json'),
            file_get_contents(__DIR__.'/../../stubs/devcontainer.stub')
        );

        $environment = file_get_contents($this->laravel->basePath('.env'));

        $environment .= "\nWWWGROUP=1000";
        $environment .= "\nWWWUSER=1000\n";

        file_put_contents($this->laravel->basePath('.env'), $environment);
    }
    */

    /**
     * Prepare the installation by pulling and building any necessary images.
     *
     * @param  array  $services
     * @return void
     */
    protected function prepareInstallation($services)
    {
        // Ensure docker is installed...
        if ($this->runCommands(['docker info > /dev/null 2>&1']) !== 0) {
            return;
        }

        $status = $this->runCommands([
            './vendor/bin/sail pull '.implode(' ', $services),
            './vendor/bin/sail build',
        ]);

        if ($status === 0) {
            \WP_CLI::log('Sail images installed successfully.');
        }
    }

    /**
     * Run the given commands.
     *
     * @param  array  $commands
     * @return int
     */
    protected function runCommands($commands)
    {
        return \WP_CLI::launch(implode(' && ', $commands));
    }
}

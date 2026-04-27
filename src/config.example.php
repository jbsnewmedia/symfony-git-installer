<?php

declare(strict_types=1);

return [
    'updater_source_path' => 'public/update',

    'show_versions_before_login' => false,

    'installer_version' => '',

    'project_version' => '',

    'repository' => 'jbsnewmedia/symfony-git-installer',

    'github_token' => $_ENV['GITHUB_TOKEN'] ?? '',

    'password' => '',

    'api_base_url' => 'https://api.github.com',

    'target_directory' => '../../',

    'temp_directory' => __DIR__.'/temp',

    'exclude_folders' => [
        '.git',
        '.github',
        '.ai',
        '.developer',
        '.idea',
        '.junie',
        'node_modules',
        'tests',
        'docs',
        'doc',
    ],

    'exclude_files' => [
        '.gitignore',
        '.gitattributes',
        'README.md',
        'LICENSE',
        '.php-cs-fixer.dist.php',
        'phpstan-global.neon',
        'rector.php',
        'phpunit.dist.xml',
        '.env.dev',
        '.env.test',
        'docker-compose.yml',
    ],

    'whitelist_folders' => [
        'public/update',
    ],

    'whitelist_files' => [
        '.env.local',
    ],

    'default_language' => 'en',
];

<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\Quality\PackageVerifier;

require dirname(__DIR__) . '/tools/quality/PackageVerifier.php';

$root = dirname(__DIR__);
$work = $root . '/build/package-check/' . bin2hex(random_bytes(6));
mkdir($work, 0777, true);
$run = (new PackageVerifier())->runCommand(...);
$source = trim($run(['git', 'rev-parse', 'HEAD'], $root));
$dirty = trim($run(['git', 'status', '--porcelain'], $root)) !== '';
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$archiveArgument = $arguments[1] ?? null;
$archives = [];
if (is_string($archiveArgument)) {
    $resolved = realpath($archiveArgument);
    if ($resolved === false) {
        throw new RuntimeException('Archive argument does not exist.');
    }
    $archives['supplied'] = $resolved;
} else {
    $run(['composer', 'archive', '--format=zip', '--dir=' . $work, '--file=composer'], $root);
    $archives['composer'] = $work . '/composer.zip';
    $run([
        'git', 'archive', '--worktree-attributes', '--format=zip', '--output=' . $work . '/git.zip', 'HEAD',
    ], $root);
    $archives['git'] = $work . '/git.zip';
    (new PackageVerifier())->inspect($archives['composer']);
    $stage = $work . '/contaminated';
    mkdir($stage);
    $zip = new ZipArchive();
    $zip->open($archives['composer']);
    $zip->extractTo($stage);
    $zip->close();
    foreach (['.env', '.phpstan.cache/fixture', 'vendor/fixture', 'coverage/fixture', 'local-settings.ini'] as $file) {
        if (!is_dir(dirname($stage . '/' . $file))) {
            mkdir(dirname($stage . '/' . $file), 0777, true);
        }
        file_put_contents($stage . '/' . $file, 'harmless synthetic package contamination');
    }
    $run(['composer', 'archive', '--format=zip', '--dir=' . $work, '--file=contaminated'], $stage);
    $archives['contaminated'] = $work . '/contaminated.zip';
}
$reports = [];
foreach ($archives as $kind => $archive) {
    $reports[$kind] = (new PackageVerifier())->inspect($archive);
    $consumer = $work . '/consumer-' . $kind;
    mkdir($consumer);
    $package = json_decode((new PackageVerifier())->composerJson($archive), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($package) || !is_array($package['autoload'] ?? null) || !is_array($package['require'] ?? null)) {
        throw new RuntimeException('Invalid package metadata.');
    }
    $definition = [
        'name' => 'simplequery/artifact-consumer',
        'require' => ['oeltimacreation/php-simplequery' => 'dev-artifact'],
        'repositories' => [
            'artifact' => ['type' => 'package', 'package' => [
                'name' => 'oeltimacreation/php-simplequery', 'version' => 'dev-artifact',
                'dist' => ['type' => 'zip', 'url' => 'file://' . $archive, 'reference' => $reports[$kind]['sha256']],
                'autoload' => $package['autoload'], 'require' => $package['require'],
            ]],
            'packagist.org' => false,
        ],
        'config' => ['allow-plugins' => false],
    ];
    file_put_contents($consumer . '/composer.json', json_encode($definition, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    copy($root . '/tests/Fixtures/Consumer/artifact-lifecycle.php', $consumer . '/run.php');
    $run(['composer', 'install', '--no-dev', '--no-interaction', '--no-plugins', '--no-scripts',
        '--classmap-authoritative', '--prefer-dist', '--no-progress'], $consumer);
    $run(['composer', 'check-platform-reqs', '--no-dev'], $consumer);
    $run([PHP_BINARY, 'run.php'], $consumer);
    $reports[$kind]['consumer'] = 'passed';
}
echo json_encode(
    ['checkout_commit' => $source, 'checkout_dirty' => $dirty, 'archives' => $reports],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
), PHP_EOL;

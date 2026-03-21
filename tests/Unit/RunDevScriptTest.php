<?php

use Symfony\Component\Process\Process;

it('run-dev.sh has valid bash syntax', function () {
    $root = dirname(__DIR__, 2);
    $process = new Process(['bash', '-n', 'run-dev.sh'], $root);
    $process->run();

    expect($process->isSuccessful())->toBeTrue();
});

it('run-dev.sh prints help', function () {
    $root = dirname(__DIR__, 2);
    $process = new Process(['bash', 'run-dev.sh', '--help'], $root);
    $process->run();

    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('run-dev.sh');
    expect($process->getOutput())->toContain('--reset');
});

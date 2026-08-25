<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The boundary this package exists to keep.
 *
 * `padosoft/eval-harness` scores a plain `Trajectory` DTO and knows nothing
 * about any agent SDK. That is what lets an eval written today survive a move
 * from `laravel/ai` to a custom orchestrator, to MCP, or to `laravel-flow` saga
 * steps without rewriting the dataset — and it is the difference from tools
 * whose agent assertions are welded to one SDK's response object.
 *
 * A boundary that is only described in a README erodes. These tests fail when
 * it does.
 */
final class BoundaryTest extends TestCase
{
    /** Files allowed to know the SDK exists. */
    /**
     * The files allowed to name the SDK. Every one of them is an adapter by
     * definition — a mapper for that SDK's response, a runner that calls it, a
     * listener for its events, and the provider that registers that listener.
     * Anything else naming `Laravel\Ai\` has leaked the SDK into logic that
     * should have outlived it.
     */
    private const SDK_AWARE = [
        'src/Trajectories/AgentResponseTrajectory.php',
        'src/Trajectories/RunTrajectoryRecorder.php',
        'src/Runners/AgentSampleRunner.php',
        'src/EvalHarnessAiBridgeServiceProvider.php',
    ];

    /**
     * If this ever fails, the harness has grown a dependency on an agent SDK
     * and this package has lost its reason to exist.
     */
    public function test_the_harness_itself_never_references_the_ai_sdk(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root().'/vendor/padosoft/eval-harness/src') as $file) {
            if (str_contains((string) file_get_contents($file), 'Laravel\\Ai\\')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'eval-harness must stay SDK-agnostic; the bridge is where laravel/ai belongs.');
    }

    /**
     * A future SDK swap should have a known blast radius: the four files listed
     * in SDK_AWARE, and nothing else.
     */
    public function test_only_the_adapter_and_the_runner_know_about_laravel_ai(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root().'/src') as $file) {
            $relative = str_replace($this->root().'/', '', $file);

            if (in_array($relative, self::SDK_AWARE, true)) {
                continue;
            }

            if (str_contains((string) file_get_contents($file), 'Laravel\\Ai\\')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * The PHPUnit trait is the surface that works everywhere; Pest must stay
     * confined to the one file that is a no-op without it.
     */
    public function test_pest_appears_only_in_the_pest_file(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root().'/src') as $file) {
            $relative = str_replace($this->root().'/', '', $file);

            if ($relative === 'src/Testing/pest.php') {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'Pest\\') || str_contains($contents, 'expect(')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_every_source_file_declares_strict_types(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root().'/src') as $file) {
            if (! str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
                $offenders[] = str_replace($this->root().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            $this->markTestSkipped(sprintf('Directory %s is not present.', $directory));
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Pdf;

use Spatie\Browsershot\Browsershot;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Browsershot for untrusted mail: it renders whatever text a mail contains, and
 * its timeout really stops the render.
 *
 * Browsershot's own timeout kills the shell it started and nothing else, so
 * node and Chrome carry on. Each render here runs in a session of its own, with
 * a marker in its environment, and nothing it started outlives callBrowser().
 * The marker is what finds them: puppeteer detaches Chrome into another session
 * and Chrome's crashpad daemons into more, so no one process group holds the
 * tree, but all of it inherits the environment.
 */
final class MailBrowsershot extends Browsershot
{
    private const RUN_VARIABLE = 'MAILROOM_PDF_RUN';

    /** SIGKILL cannot be refused, only delayed: a process stuck on disk dies when the disk answers. */
    private const KILL_WAIT_SECONDS = 10;

    private string $run = '';

    /**
     * The parent refuses any document containing `file:/`, `view-source` or
     * `//localhost`, anywhere, including in a mail's plain text, so a message
     * mentioning one could never be rendered. What keeps a mail off the disk and
     * the network is Chrome, refusing them to a page on an http origin with
     * JavaScript off (BrowsershotRenderer), not this substring test.
     */
    public function setHtml(string $html): static
    {
        $this->html = $html;
        $this->url = '';

        return $this->hideBrowserHeaderAndFooter();
    }

    /**
     * A profile of our own, because puppeteer only removes the one it makes
     * when node lives to do so. Chrome's temporary files go in it too: a killed
     * Chrome leaves its singleton socket's directory behind.
     *
     * @param  array<string, mixed>  $command
     */
    protected function callBrowser(array $command): string
    {
        $this->run = bin2hex(random_bytes(16));
        $profile = (new TemporaryDirectory($this->tempPath))->create();
        $command['options']['args'][] = '--user-data-dir='.$profile->path();
        $this->setNodeEnv(['TMPDIR' => $profile->path()]);

        try {
            return parent::callBrowser($command);
        } finally {
            $this->killTheRun();
            $profile->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<int, string>|string
     */
    protected function getFullCommand(array $command): array|string
    {
        $full = parent::getFullCommand($command);

        return is_string($full)
            ? sprintf('exec env %s=%s setsid sh -c %s', self::RUN_VARIABLE, $this->run, escapeshellarg($full))
            : $full;
    }

    /**
     * Looks again after every kill, for what was being spawned as the last one
     * landed. Returns once nothing of the run is left, so a retry cannot overlap.
     */
    private function killTheRun(): void
    {
        $giveUp = microtime(true) + self::KILL_WAIT_SECONDS;

        while (($groups = $this->groupsOfTheRun()) !== [] && microtime(true) < $giveUp) {
            foreach ($groups as $group) {
                posix_kill(-$group, SIGKILL);
            }

            usleep(20_000);
        }
    }

    /**
     * @return list<int>
     */
    private function groupsOfTheRun(): array
    {
        $marker = self::RUN_VARIABLE.'='.$this->run;
        $groups = [];

        foreach (glob('/proc/[0-9]*/environ') ?: [] as $environ) {
            if (str_contains((string) @file_get_contents($environ), $marker)) {
                $groups[] = (int) posix_getpgid((int) basename(dirname($environ)));
            }
        }

        return array_values(array_unique(array_filter($groups)));
    }
}

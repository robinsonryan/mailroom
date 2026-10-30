<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Console;

use Illuminate\Console\Command;
use RobinsonRyan\Mailroom\Contracts\DecisionGateway;
use RobinsonRyan\Mailroom\Exceptions\GatewayUnavailable;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Exceptions\MessageUnreadable;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\ProviderRegistry;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;
use RobinsonRyan\Mailroom\Typing\Typist;

/**
 * Acceptance check before going live: asks a type set about messages whose
 * answer is already known and reports agreement. It reads and asks only: no
 * typing is saved, no label written, no event fired.
 *
 * The file is JSON lines, `{"id": "<provider message id>", "expected": "<type>"}`.
 * Corpora hold real mail ids: keep them out of git.
 */
final class ReplayCommand extends Command
{
    protected $signature = 'mailroom:replay
        {type_set : The registered type set to ask}
        {mailbox : The mailbox address holding the messages}
        {file : JSON lines of id and expected}
        {--positive=invoice : Agreement is on this type or not}
        {--minimum=95 : Fail below this percentage}';

    protected $description = 'Replay known messages through a type set and report agreement; changes nothing';

    public function handle(Mailroom $mailroom, ProviderRegistry $providers, TypeSetRegistry $typeSets, DecisionGateway $gateway, Typist $typist): int
    {
        /** @var array{type_set: string, mailbox: string, file: string} $arguments */
        $arguments = $this->arguments();
        /** @var string $positive */
        $positive = $this->option('positive');
        $set = $typeSets->get($arguments['type_set']);
        $mailbox = $mailroom->mailbox($arguments['mailbox']);
        $provider = $providers->for($mailbox);
        $lines = file($arguments['file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            $this->error('Cannot read the file.');

            return self::FAILURE;
        }

        $agreed = 0;
        $compared = 0;
        $missing = 0;
        $unsure = 0;

        foreach ($lines as $line) {
            /** @var array{id: string, expected: string} $row */
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            try {
                $summary = $provider->summary($mailbox, $row['id'], (int) config('mailroom.typing.text_limit'));
                $answer = $gateway->ask($set, $typist->state($summary), "{$mailbox->address}:{$row['id']}");
            } catch (MessageNotFound|MessageUnreadable) {
                $missing++;

                continue;
            } catch (GatewayUnavailable $e) {
                $this->error("The gateway failed: {$e->getMessage()}");

                return self::FAILURE;
            }

            $compared++;
            $unsure += ($answer->confidence ?? 0.0) < $set->threshold ? 1 : 0;

            if (($answer->type === $positive) === ($row['expected'] === $positive)) {
                $agreed++;

                continue;
            }

            $this->line(sprintf('DISAGREE %s expected=%s got=%s (%s) %s — %s', $row['id'], $row['expected'], $answer->type, $answer->confidence ?? 'n/a', $summary->from, $summary->subject));
        }

        $percent = $compared === 0 ? 0.0 : round($agreed / $compared * 100, 1);
        $this->info("{$agreed} of {$compared} agree ({$percent}%); {$unsure} below the threshold would go to a person; {$missing} not in this mailbox.");

        return $percent >= (float) (is_string($this->option('minimum')) ? $this->option('minimum') : 95) ? self::SUCCESS : self::FAILURE;
    }
}

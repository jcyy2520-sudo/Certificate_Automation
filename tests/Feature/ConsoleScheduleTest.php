<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ConsoleScheduleTest extends TestCase
{
    public function test_all_operational_and_privacy_commands_are_scheduled(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $commands = $events->map(function (Event $event): string {
            return (string) $event->command;
        });

        $expected = [
            'operations:heartbeat',
            'security:relay-email-outbox',
            'security:prune-participant-access',
            'privacy:erase-expired-participants',
            'security:prune-email-deliveries',
            'security:prune-audit-logs',
        ];

        foreach ($expected as $commandName) {
            $matched = $commands->contains(function (string $cmd) use ($commandName): bool {
                return str_contains($cmd, $commandName);
            });

            $this->assertTrue($matched, "Expected command [{$commandName}] was not found in the console schedule.");
        }
    }
}

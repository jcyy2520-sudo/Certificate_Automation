<?php

namespace Tests\Feature;

use App\Models\User;

/** Exercise the established workflow suite with regular-owner authorization. */
class OwnerFormResponsesWorkflowTest extends FormResponsesWorkflowTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::query()->firstOrFail();
        $owner->forceFill(['role' => User::USER_ROLE])->save();
        $this->actingAs($owner);
    }
}

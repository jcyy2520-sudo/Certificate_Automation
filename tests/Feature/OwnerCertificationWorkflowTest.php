<?php

namespace Tests\Feature;

use App\Models\User;

/** Run certificate preparation, issuance and delivery regressions as an owner. */
class OwnerCertificationWorkflowTest extends CertificationManagementTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::query()->firstOrFail();
        $owner->forceFill(['role' => User::USER_ROLE])->save();
        $this->actingAs($owner);
    }
}

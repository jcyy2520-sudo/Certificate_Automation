    <p>Dear {{ $participant->full_name }},</p>
    <p>Thank you for participating in <strong>{{ $webinar->title }}</strong>.</p>
    @if($summary['status'] === 'not_eligible')
        <p>You are <strong>Not Eligible</strong> for a certificate because the current records do not satisfy all of the webinar requirements. The organizer has finalized the review of these records.</p>
    @else
        <p>Your certificate eligibility is currently <strong>Incomplete / Under Review</strong>. We cannot issue a certificate yet because the current records do not verify all of the webinar requirements. This is not a final ineligibility decision.</p>
    @endif
    <p>The following requirements need attention in your record:</p>
    <ul>
        @foreach($summary['activities'] as $activity)
            @if($activity['required'] && $activity['met'] !== true)
                <li><strong>{{ $activity['label'] }}:</strong> {{ $activity['detail'] }}
                    @if($activity['minimum_score'] !== null)
                        Required minimum score: {{ $activity['minimum_score'] }}.
                        @php($scores = collect($activity['records'])->pluck('score')->filter(fn ($score) => $score !== null))
                        @if($scores->isNotEmpty()) Recorded score(s): {{ $scores->implode(', ') }}. @endif
                    @endif
                </li>
            @endif
        @endforeach
    </ul>
    @if($summary['final_reason'])<p>Organizer's explanation: {{ $summary['final_reason'] }}</p>@endif
    <p>If you believe a record is missing or incorrect, please reply to the organizing team with the relevant details so we can check it.</p>
    <p>No certificate is attached to this message.</p>
    <p>Best regards,<br>Webinar Organizing Team</p>

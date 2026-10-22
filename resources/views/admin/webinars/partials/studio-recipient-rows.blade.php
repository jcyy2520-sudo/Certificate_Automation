<div class="min-h-0 flex-1 overflow-y-auto p-2" data-certificate-list>
                    @forelse($participants as $participant)
                        @php
                            $certificate = $participant->certificate_record;
                            $state = $participant->certificate_delivery_state;
                            $status = $state->slug();
                            $statusShort = match ($status) {
                                'ready' => 'Ready',
                                'queued' => 'Queued',
                                'sending' => 'Sending',
                                'sent' => 'Sent',
                                'failed' => 'Failed',
                                'missing_email' => 'No email',
                                default => '—',
                            };
                        @endphp
                        {{-- One row is both the certificate and the person: selecting, previewing,
                             filtering and live status updates all address the same element. --}}
                        <article class="studio-person-row" data-certificate-row="{{ $participant->public_id }}" data-person-row="{{ $participant->public_id }}" data-search="{{ Str::lower($participant->full_name.' '.$participant->email) }}" data-source="{{ $participant->recipient_source }}" data-manual-editable="{{ $participant->manual_editable ? 1 : 0 }}" data-manual-active="{{ $participant->certificateApproval?->source === 'manual' && ! $participant->certificateApproval->removed_at ? 1 : 0 }}" data-manual-update-url="{{ route('admin.certificates.manual.update', [$webinar, $participant]) }}" data-status="{{ $status }}" title="{{ $state->label() }}" data-name-update-url="{{ route('admin.participants.name', [$webinar, $participant]) }}">
                            <div class="flex items-center gap-2.5">
                                <label class="shrink-0" title="{{ $status === 'ready' ? 'Include this certificate when sending' : $state->label().' — this recipient cannot be selected again' }}"><input type="checkbox" class="size-4 rounded border-slate-300 text-accent-700" data-queue-check="{{ $participant->public_id }}" @checked($selectionScoped && $status === 'ready') @disabled($status !== 'ready')></label>
                                <button type="button" class="flex min-w-0 flex-1 items-center gap-2.5 text-left" data-preview-select="{{ $participant->public_id }}">
                                    <span class="studio-avatar">{{ Str::upper(Str::substr($participant->full_name ?: 'P', 0, 1)) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[12px] font-semibold text-slate-900" data-participant-name>{{ $participant->full_name ?: 'Unnamed participant' }}</span>
                                        <span class="mt-0.5 block truncate text-[11px] text-slate-500">{{ $participant->email ?: 'No email address' }}</span>
                                    </span>
                                </button>
                                {{-- A compact, fixed-width status chip: sighted admins see why a
                                     box will not tick ("Sent" vs "Failed"), while the full label
                                     stays on hover and the name/email keep their room. --}}
                                <span class="text-[10px] text-slate-500" data-recipient-source-label>{{ ucfirst($participant->recipient_source) }}</span>
                                <span class="studio-status-chip" data-status-badge title="{{ $state->label() }}">{{ $statusShort }}</span>
                            </div>
                            @if($certificate?->status === 'failed')
                                <form method="POST" action="{{ route('admin.certificates.issue-selected', $webinar) }}" class="mt-2 pl-8" data-confirm="Queue certificate generation again for this recipient using the saved name placement? Check Preview PDF before continuing." data-confirm-title="Retry certificate generation" data-confirm-action="Retry generation">
                                    @csrf
                                    <input type="hidden" name="participants[]" value="{{ $participant->public_id }}">
                                    <input type="hidden" name="preview_confirmed" value="1">
                                    <input type="hidden" name="designs_json" value="{{ json_encode([$participant->public_id => $certificate->layout]) }}">
                                    <button class="text-[10px] font-bold text-accent-700 underline">Retry generation</button>
                                </form>
                            @elseif($certificate?->status === 'issued')
                                <div class="{{ $status === 'failed' ? '' : 'hidden' }} mt-2 pl-8" data-failure-detail>
                                    @if($participant->delivery_meta['requires_reconciliation'] ?? false)
                                        <form method="POST" action="{{ route('admin.certificates.resend', $certificate) }}" class="space-y-2 rounded border border-amber-200 bg-amber-50 p-2 text-[11px] text-amber-950" data-confirm="You confirmed in Brevo that this uncertain delivery was not accepted. Queue a new certificate email?" data-confirm-tone="danger" data-confirm-action="Queue confirmed retry" data-confirm-title="Reconcile before retry">
                                            @csrf
                                            <input type="hidden" name="confirm_unknown" value="1">
                                            <p class="font-semibold">Provider outcome unknown — do not retry blindly.</p>
                                            <p>Check Brevo's message log first, then record the search or case reference.</p>
                                            <label class="block">Provider-log reference
                                                <input name="provider_reconciliation_reference" required minlength="5" maxlength="200" class="field mt-1 w-full" placeholder="e.g. Brevo log search 2026-09-25 10:15 UTC">
                                            </label>
                                            <button class="text-[10px] font-bold text-amber-900 underline">Queue confirmed retry</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.certificates.resend', $certificate) }}" data-confirm="Retry this failed certificate delivery?" data-confirm-tone="neutral" data-confirm-action="Retry send" data-confirm-title="Retry certificate">@csrf<button class="text-[10px] font-bold text-red-700 underline">Retry email</button></form>
                                    @endif
                                </div>
                            @endif
                            @if($participant->manual_editable)
                                <div class="mt-2 flex gap-3 pl-8"><button type="button" class="text-xs text-accent-700 underline" data-edit-manual="{{ $participant->public_id }}">Edit recipient</button><button type="button" class="text-xs text-red-700 underline" data-remove-manual="{{ $participant->public_id }}">Remove</button></div>
                            @elseif($participant->certificateApproval?->source === 'manual' && ! $participant->certificateApproval->removed_at && ! $participant->certificates->contains(fn ($c) => ! $c->revoked_at && in_array($c->status, ['processing', 'issued'], true)))
                                <div class="mt-2 pl-8"><button type="button" class="text-xs text-red-700 underline" data-remove-manual="{{ $participant->public_id }}">Remove manual exception</button></div>
                            @endif
                        </article>
                    @empty
                        <p class="px-3 py-8 text-center text-xs text-slate-500">No recipients match this list. Eligible participants appear automatically; use Add Recipient Manually for an exception.</p>
                    @endforelse
                </div>

<div data-tab-panel="sent" class="hidden min-h-0 flex-1 flex-col overflow-y-auto">
                    @if($sentRecent->isEmpty())
                        <p class="px-4 py-10 text-center text-[12px] text-slate-400" data-sent-empty>Nothing sent yet for this webinar.</p>
                    @else
                        @if(($canResendCertificates ?? false) && app(\App\Services\LocalDelivery::class)->enabled())
                            <div class="border-b border-slate-200 p-3">
                                <label class="flex items-center gap-2 text-xs font-medium"><input type="checkbox" data-resend-all> Select all corrected eligible recipients</label>
                                <form id="resend-selected-form" method="POST" action="{{ route('admin.certificates.send-plan', $webinar) }}" class="mt-2"
                                      data-resend-selected-form data-certificate-progress="resend" data-inline-submit
                                      data-confirm="Resend corrected certificates to the selected eligible recipients?" data-confirm-title="Resend corrected certificates?" data-confirm-action="Start corrected resend" data-confirm-tone="neutral">
                                    @csrf
                                    <button type="submit" class="button-secondary w-full text-xs" disabled>Resend selected (<span data-resend-count>0</span>)</button>
                                </form>
                                <p class="mt-2 text-[11px] text-slate-500">Only recipients with a corrected PDF who are currently eligible can be selected. This does not send non-eligible notices.</p>
                            </div>
                        @endif
                        <ul class="divide-y divide-slate-100">
                            @foreach($sentRecent as $certificate)
                                @php
                                    $sentName = $certificate->recipient_name ?: ($certificate->participant?->full_name ?: 'Unnamed participant');
                                    $sentEmail = $certificate->participant?->email;
                                @endphp
                                <li class="flex flex-wrap items-center gap-2.5 px-3 py-2" data-sent-row data-search="{{ Str::lower($sentName.' '.$sentEmail) }}">
                                    @if(($canResendCertificates ?? false) && $certificate->eligible_for_resend && $certificate->correction_pending && app(\App\Services\LocalDelivery::class)->enabled())
                                        <input type="checkbox" name="certificates[]" value="{{ $certificate->id }}" form="resend-selected-form" data-resend-check aria-label="Select {{ $sentName }} for corrected resend">
                                    @endif
                                    <span class="grid size-7 shrink-0 place-items-center rounded-md bg-emerald-50 text-[10px] font-bold text-emerald-700">{{ Str::upper(Str::substr($sentName, 0, 1)) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[12px] font-medium text-slate-700">{{ $sentName }}</span><span class="text-[10px] text-slate-500">{{ ($certificate->eligibility_snapshot['source'] ?? (($certificate->eligibility_snapshot['overridden'] ?? false) ? 'manual' : 'eligible')) === 'manual' ? 'Manual' : 'Eligible' }}</span>
                                        <span class="block truncate font-mono text-[11px] text-slate-400">{{ $sentEmail }}</span>
                                    </span>
                                    <span class="shrink-0 whitespace-nowrap text-[10px] tabular-nums text-slate-400" title="{{ $certificate->sent_at?->format('M j, Y g:i A') }}">{{ $certificate->sent_at?->diffForHumans(short: true) }}</span>
                                    @if(($canResendCertificates ?? false) && $certificate->eligible_for_resend)
                                        <div class="flex w-full flex-wrap items-center gap-3 pl-9">
                                            <a class="text-[11px] font-medium text-accent-700 underline" href="{{ route('admin.certificates.download', $certificate) }}">Download PDF</a>
                                            <form method="POST" action="{{ route('admin.certificates.resend', $certificate) }}"
                                                  @if(app(\App\Services\LocalDelivery::class)->enabled() && $certificate->correction_pending) data-certificate-progress="resend" data-inline-submit @endif
                                                  @if(app(\App\Services\LocalDelivery::class)->enabled()) data-immediate-send @endif
                                                  data-confirm="Send another certificate email to {{ $sentName }} ({{ $sentEmail }})? It attaches the current stored PDF. Check Email logs before repeating this action."
                                                  data-confirm-title="Resend this certificate?" data-confirm-action="Send again" data-confirm-tone="neutral">
                                                @csrf
                                                <input type="hidden" name="certificates[]" value="{{ $certificate->id }}">
                                                <button type="submit" class="text-[11px] font-semibold text-accent-700 underline" data-sent-resend>{{ $certificate->signature_corrected ? 'Resend corrected certificate' : 'Resend certificate' }}</button>
                                            </form>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if($sentCount > $sentRecent->count())
                            <p class="px-4 py-2 text-[11px] text-slate-400">{{ \Illuminate\Support\Number::format($sentCount - $sentRecent->count()) }} earlier deliveries not listed here — use the email delivery log for older records.</p>
                        @endif
                    @endif
                </div>

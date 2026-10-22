<div data-tab-panel="sent" class="hidden min-h-0 flex-1 flex-col overflow-y-auto">
                    @if($sentRecent->isEmpty())
                        <p class="px-4 py-10 text-center text-[12px] text-slate-400" data-sent-empty>Nothing sent yet for this webinar.</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach($sentRecent as $certificate)
                                @php
                                    $sentName = $certificate->recipient_name ?: ($certificate->participant?->full_name ?: 'Unnamed participant');
                                    $sentEmail = $certificate->participant?->email;
                                @endphp
                                <li class="flex items-center gap-2.5 px-3 py-2" data-sent-row data-search="{{ Str::lower($sentName.' '.$sentEmail) }}">
                                    <span class="grid size-7 shrink-0 place-items-center rounded-md bg-emerald-50 text-[10px] font-bold text-emerald-700">{{ Str::upper(Str::substr($sentName, 0, 1)) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[12px] font-medium text-slate-700">{{ $sentName }}</span><span class="text-[10px] text-slate-500">{{ ($certificate->eligibility_snapshot['source'] ?? (($certificate->eligibility_snapshot['overridden'] ?? false) ? 'manual' : 'eligible')) === 'manual' ? 'Manual' : 'Eligible' }}</span>
                                        <span class="block truncate font-mono text-[11px] text-slate-400">{{ $sentEmail }}</span>
                                    </span>
                                    <span class="shrink-0 whitespace-nowrap text-[10px] tabular-nums text-slate-400" title="{{ $certificate->sent_at?->format('M j, Y g:i A') }}">{{ $certificate->sent_at?->diffForHumans(short: true) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if($sentCount > $sentRecent->count())
                            <p class="px-4 py-2 text-[11px] text-slate-400">{{ \Illuminate\Support\Number::format($sentCount - $sentRecent->count()) }} earlier deliveries not listed here — use the email delivery log for older records.</p>
                        @endif
                    @endif
                </div>

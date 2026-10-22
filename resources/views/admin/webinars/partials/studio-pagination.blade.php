<div data-recipient-pagination>
                    @if($participants->hasPages())
                        <div class="shrink-0 border-t border-slate-100 px-4 py-2">
                            <div class="flex items-center justify-between text-[12px]">
                                @if($participants->onFirstPage())
                                    <span class="text-slate-300">Previous</span>
                                @else
                                    <a class="font-medium text-accent-700 hover:underline" href="{{ $participants->previousPageUrl() }}" rel="prev">Previous</a>
                                @endif
                                <span class="tabular-nums text-slate-500">Page {{ $participants->currentPage() }} of {{ $participants->lastPage() }}</span>
                                @if($participants->hasMorePages())
                                    <a class="font-medium text-accent-700 hover:underline" href="{{ $participants->nextPageUrl() }}" rel="next">Next</a>
                                @else
                                    <span class="text-slate-300">Next</span>
                                @endif
                            </div>
                            <p class="mt-1 text-[11px] leading-4 text-slate-400">Showing a page of recipients. Use "Send to all ready" above to reach everyone at once.</p>
                        </div>
                    @endif
                    </div>

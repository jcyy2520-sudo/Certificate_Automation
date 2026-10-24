<section class="panel mb-5 overflow-hidden" id="source-preview">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
        <div><h2 class="section-title">Original source preview</h2><p class="mt-1 text-sm text-slate-500">Full original headings and cells. Scroll sideways to review every column.</p></div>
        <span class="text-sm text-slate-500">Rows {{ $previewPagination->firstItem() ?? 0 }}–{{ $previewPagination->lastItem() ?? 0 }} of {{ $previewPagination->total() }}</span>
    </div>
    @if($previewRows === [])
        <p class="p-5 text-sm text-amber-800">The file has headers but no response rows. Nothing will be imported.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm" data-source-preview>
                <thead class="border-b border-slate-200 bg-slate-50 text-sm text-slate-500"><tr><th scope="col" class="px-3 py-2.5 font-semibold">Row</th>
                    @foreach($headers as $header)<th scope="col" class="min-w-48 max-w-96 whitespace-pre-wrap break-words px-3 py-2.5 font-semibold">{{ $header }}</th>@endforeach
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($previewRows as $preview)
                        <tr @class(['bg-red-50/60' => $preview['column_count'] !== count($headers)])>
                            <td class="whitespace-nowrap px-3 py-2.5 font-medium tabular-nums text-slate-700">{{ $preview['row_number'] }}</td>
                            @foreach($headers as $index => $header)<td class="min-w-48 max-w-96 whitespace-pre-wrap break-words px-3 py-2.5 text-slate-600">{{ $preview['cells'][$index] ?? '—' }}</td>@endforeach
                        </tr>
                        @if($preview['column_count'] !== count($headers))<tr class="bg-red-50/60"><td colspan="{{ count($headers) + 1 }}" class="px-3 pb-2.5 text-red-800">This row has {{ $preview['column_count'] }} columns; the header has {{ count($headers) }}. It will be held for review, not interpreted.</td></tr>@endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($previewPagination->hasPages())<div class="border-t border-slate-200 p-4">{{ $previewPagination->links() }}</div>@endif
</section>

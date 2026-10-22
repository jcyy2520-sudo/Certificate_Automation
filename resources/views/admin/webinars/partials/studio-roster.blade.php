<!DOCTYPE html>
<html><head><title>Certificate recipients</title></head><body data-app-layout="studio-roster">
@include('admin.webinars.partials.studio-send-inputs')
@include('admin.webinars.partials.studio-recipient-rows')
@include('admin.webinars.partials.studio-pagination')
<span data-recipient-working-total>{{ number_format($participants->total()) }}</span>
@include('admin.webinars.partials.studio-sent')
<span data-recipient-sent-total>{{ number_format($sentCount) }}</span>
<span data-bulk-ready-count>{{ $bulkReadyCount }}</span>
</body></html>

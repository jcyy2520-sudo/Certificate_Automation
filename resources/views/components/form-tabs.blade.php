@props(['webinar', 'active', 'context' => 'forms'])
<nav class="form-tabs mb-6" aria-label="{{ $context === 'forms' ? 'Forms' : 'Response forms' }}">
    @foreach(\App\Services\FormResponseService::TYPES as $type => $label)
        @if($tabForm = $webinar->forms->firstWhere('type', $type))
            <a href="{{ $context === 'forms' ? route('admin.forms.edit', [$webinar, $tabForm]) : route('admin.responses.index', [$webinar, 'type' => $type]) }}"
               class="form-tab {{ $active === $type ? 'form-tab-active' : '' }}" @if($active === $type) aria-current="page" @endif>{{ $label }}</a>
        @endif
    @endforeach
</nav>

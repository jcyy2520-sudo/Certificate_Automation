@props(['webinar'])
@if(auth()->user()->isAdministrator())
    @php $locked = $webinar->isAdministratorLocked(); @endphp
    <form method="POST" action="{{ route('admin.webinars.lock', $webinar) }}"
        data-confirm="{{ $locked ? 'Restore this webinar’s existing management permissions? Saved records and participant access will stay as they are.' : 'Restrict this webinar to administrators? All other managers, including its owner, will lose management access. Saved records and participant access will stay as they are.' }}"
        data-confirm-title="{{ $locked ? 'Unlock webinar' : 'Lock webinar' }}"
        data-confirm-action="{{ $locked ? 'Unlock webinar' : 'Lock webinar' }}" data-confirm-tone="{{ $locked ? 'info' : 'warning' }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="locked" value="{{ $locked ? '0' : '1' }}">
        <button class="button-secondary whitespace-nowrap" aria-label="{{ $locked ? 'Unlock webinar' : 'Lock webinar' }}: {{ $webinar->title }}">
            <x-icon name="{{ $locked ? 'unlock' : 'lock' }}" class="size-4" />{{ $locked ? 'Unlock webinar' : 'Lock webinar' }}
        </button>
    </form>
@endif

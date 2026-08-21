@props(['error'])
@if($error)
    <p {{ $attributes->class(['field-error']) }}><x-icon name="alert" class="size-3.5 shrink-0" />{{ $error }}</p>
@endif

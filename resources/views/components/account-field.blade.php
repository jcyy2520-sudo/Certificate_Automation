@props(['name', 'label', 'type' => 'text', 'autocomplete' => null, 'value' => null, 'required' => true])
<div>
    <label class="field-label" for="{{ $name }}">{{ $label }}</label>
    <div class="flex gap-2">
        <input id="{{ $name }}" class="field min-w-0 flex-1" name="{{ $name }}" type="{{ $type }}"
               @if($type !== 'password') value="{{ old($name, $value) }}" @endif
               @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif @required($required)
               aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" aria-describedby="{{ $name }}-error">
        @if($type === 'password')<button class="button-secondary shrink-0" type="button" data-password-toggle="{{ $name }}" aria-label="Show {{ strtolower($label) }}" aria-pressed="false">Show</button>@endif
    </div>
    <p id="{{ $name }}-error" class="mt-1 text-[13px] text-red-700 dark:text-red-300">{{ $errors->first($name) }}</p>
</div>

<label class="field-label">{{ $label }} @if($field === 'email')<span class="sr-only">(required for individual matching)</span>@endif
    <select name="map[{{ $field }}]" class="field" aria-label="Column carrying {{ $label }}"><option value="">— not present —</option>@foreach($headers as $index => $header)<option value="{{ $index }}" @selected((string) old('map.'.$field, $map[$field] ?? '') === (string) $index)>{{ $header }}</option>@endforeach</select>
</label>

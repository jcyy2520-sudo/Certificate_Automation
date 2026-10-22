<div data-studio-send-inputs>
    @foreach($participants as $participant)
        <input type="checkbox" name="participants[]" value="{{ $participant->public_id }}" data-send-participant="{{ $participant->public_id }}" @checked($selectionScoped && $participant->cert_status === 'ready') @disabled($participant->cert_status !== 'ready')>
        <span data-email-store="{{ $participant->public_id }}" data-value="{{ $participant->email }}"></span>
        <span data-name-store="{{ $participant->public_id }}" data-value="{{ $participant->full_name }}"></span>
    @endforeach
    </div>

@extends('errors.layout')
@section('code', '423')
@section('heading', 'Webinar access restricted')
@section('message', 'Access to this webinar has been restricted by an administrator. Contact your administrator if you need access.')
@section('actions')
    <a href="{{ route('admin.webinars.index') }}" class="button-primary">Back to webinars</a>
@endsection

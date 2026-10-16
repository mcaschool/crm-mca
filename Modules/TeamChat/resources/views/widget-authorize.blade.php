@php($en = ($values['locale'] ?? 'es') === 'en')
<!doctype html><html lang="{{ $en ? 'en' : 'es' }}"><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>MCA Colaboración</title><body><main><h1>MCA Colaboración</h1>
<p>{{ $application->name }} — {{ $values['origin'] }}</p>
<p>{{ $en ? 'Connect this application to your institutional session. Conversations remain inside the MCA widget.' : 'Conectar esta aplicación con tu sesión institucional. Las conversaciones permanecen dentro del widget de MCA.' }}</p>
<form method="post" action="{{ url('/collaboration/widget/authorize') }}">@csrf
@foreach($values as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<button type="submit">{{ $en ? 'Connect' : 'Conectar' }}</button></form></main></body></html>

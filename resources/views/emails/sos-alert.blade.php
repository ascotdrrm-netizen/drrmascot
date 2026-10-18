@php($hasGps = $sos->latitude !== null && $sos->longitude !== null)
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>SOS alert</title></head><body>
<h1>SOS #{{ $sos->sos_id }}{{ $sos->type ? ' ('.$sos->type.')' : '' }}</h1>
<p><strong>{{ $reporter->full_name }}</strong>@if(filled($reporter->contact_number)) · <a href="tel:{{ $reporter->contact_number }}">{{ $reporter->contact_number }}</a>@endif</p>
<p>{{ $reporter->campus?->campus_name ?: 'ASCOT campus' }}</p>
<p>At: {{ $sos->place ?: ($hasGps ? 'GPS location' : 'Location not shared') }}</p>
@if($hasGps)<p><a href="https://maps.google.com/?q={{ round((float) $sos->latitude, 6) }},{{ round((float) $sos->longitude, 6) }}">Open the location on Google Maps</a></p>@endif
<p>Sent {{ $sos->created_at?->format('M j, Y g:i A') }}.</p>
<p><a href="{{ $consoleUrl }}">Open the SOS Console now</a></p>
</body></html>

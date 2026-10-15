<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Campus notice</title></head><body>
<h1>{{ $broadcast->title }}</h1>
<p>{{ $broadcast->campus->campus_name }} · ASCOT DRRM Office</p>
<div style="white-space: pre-wrap">{{ $broadcast->body }}</div>
<p>Published {{ $broadcast->published_at->format('M j, Y g:i A') }}.</p>
</body></html>

{!! $emoji !!} {!! $fmt->bold($appName) !!} · {!! $fmt->code($appEnv) !!} · {!! $fmt->bold($levelName) !!}
{!! $fmt->italic($datetime->format('Y-m-d H:i:s T')) !!}

{!! $fmt->text($message) !!}
@if ($exception)

{!! $fmt->bold($exception['class']) !!}
@if ($exception['message'] !== null)
{!! $fmt->text($exception['message']) !!}
@endif
{!! $fmt->code($exception['location']) !!}
@if ($exception['trace'] !== [])
{!! $fmt->pre(implode("\n", $exception['trace'])) !!}
@endif
@endif
@if ($request)

{!! $fmt->bold('Request') !!}
{!! $fmt->code($request['method'] ?? '') !!} {!! $fmt->text($request['url'] ?? '') !!}
@isset($request['ip'])
IP: {!! $fmt->code($request['ip']) !!}
@endisset
@isset($request['user_id'])
User: {!! $fmt->code($request['user_id']) !!}
@endisset
@endif
@if ($context !== null)

{!! $fmt->bold('Context') !!}
{!! $fmt->pre($context, 'json') !!}
@endif
@if ($extra !== null)

{!! $fmt->bold('Extra') !!}
{!! $fmt->pre($extra, 'json') !!}
@endif

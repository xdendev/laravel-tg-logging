{!! $emoji !!} {!! $fmt->bold($appName) !!} {!! $fmt->code($levelName) !!}
{!! $fmt->text($message) !!}
@if ($exception)
{!! $fmt->code($exception['class'].' — '.$exception['location']) !!}
@endif

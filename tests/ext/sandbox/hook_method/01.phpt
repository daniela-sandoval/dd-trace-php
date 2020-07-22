--TEST--
DDTrace\hook_method supports both hooks simultaneously
--XFAIL--
This is not yet supported
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\hook_method('Greeter', 'greet',
    function () {
        echo "Greeter::greet prehook\n";
    },
    function () {
        echo "Greeter::greet posthook\n";
    }
);

final class Greeter
{
    public static function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

Greeter::greet('Datadog');

?>
--EXPECT--
Greeter::greet prehook
Hello, Datadog.
Greeter::greet posthook

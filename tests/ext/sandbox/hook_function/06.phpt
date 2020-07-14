--TEST--
DDTrace\hook_function prehook is passed the correct args
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php
use DDTrace\SpanData;

var_dump(DDTrace\hook_function('greet',
    function ($args) {
        assert($args == ["Datadog"]);
    }
));

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECT--
bool(true)
Hello, Datadog.


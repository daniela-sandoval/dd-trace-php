--TEST--
DDTrace\hook_function posthook is passed the correct args (variadic)
--FILE--
<?php
use DDTrace\SpanData;

var_dump(DDTrace\hook_function('greet',
    null,
    function (...$args) {
        var_dump($args);
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
array(2) {
  [0]=>
  array(1) {
    [0]=>
    string(7) "Datadog"
  }
  [1]=>
  NULL
}


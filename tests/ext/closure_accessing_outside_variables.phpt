--TEST--
Check if closure can safely use variable names also present in outside scope
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
--FILE--
<?php
// variable present in outside scope
$variable = 1;

class Test {
    public function m(){
        echo "METHOD" . PHP_EOL;
    }
}

function setup($variable){
    dd_trace("Test", "m", function() use ($variable){
        $this->m();
        echo "HOOK " . $variable . PHP_EOL;
    });
}

// Cannot call a function while it is not traced and later expect it to trace
//(new Test())->m();
setup(1);
(new Test())->m();
setup(3);
(new Test())->m();

?>
--EXPECT--
METHOD
HOOK 1
METHOD
HOOK 3

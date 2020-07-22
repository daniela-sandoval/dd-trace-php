<?php

namespace DDTrace\Tests\Integrations\PHPRedis;

use DDTrace\Integrations\PHPRedis\PHPRedisSandboxedIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use Exception;

class PHPRedisSandboxedTest extends IntegrationTestCase
{
    const IS_SANDBOX = true;

    private $host = 'redis_integration';
    private $port = '6379';

    /** Redis */
    private $redis;

    public function setUp()
    {
        parent::setUp();
        $this->redis = new \Redis();
        $this->redis->connect($this->host, $this->port);
    }

    public function tearDown()
    {
        $this->redis->flushAll();
        $this->redis->close();
        parent::tearDown();
    }

    /**
     * @dataProvider dataProviderTestConnectionOk
     */
    public function testConnectionOk($method)
    {
        $redis = new \Redis();
        $traces = $this->isolateTracer(function () use ($redis, $method) {
            $redis->$method($this->host);
        });
        $redis->close();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'redis',
                "Redis.$method"
            )->withExactTags([
                'out.host' => $this->host,
                'out.port' => $this->port,
            ]),
        ]);
    }

    public function dataProviderTestConnectionOk()
    {
        return [
            'connect' => ['connect'],
            'pconnect' => ['pconnect'],
            'open' => ['open'],
            'popen' => ['popen'],
        ];
    }

    /**
     * @dataProvider dataProviderTestConnectionError
     */
    public function testConnectionError($host, $port, $method)
    {
        $redis = new \Redis();
        $traces = $this->isolateTracer(function () use ($redis, $method, $host, $port) {
            try {
                if (null !== $host && null !== $port) {
                    $redis->$method($host, $port);
                } elseif (null !== $host) {
                    $redis->$method($host);
                }
            } catch (\Exception $e) {
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'redis',
                "Redis.$method"
            )
            ->setError()
            ->withExactTags([
                'out.host' => $host,
                'out.port' => $port ?: $this->port,
            ])
            ->withExistingTagsNames(['error.msg', 'error.stack']),
        ]);
    }

    public function dataProviderTestConnectionError()
    {
        return [
            // Unreachable
            'connect host unreachable' => ['not_existing_host', null, 'connect'],
            'open host unreachable' => ['not_existing_host', null, 'open'],
            'pconnect host unreachable' => ['not_existing_host', null, 'pconnect'],
            'popen host unreachable' => ['not_existing_host', null, 'popen'],
            // Not listening
            'connect host not listening' => ['127.0.01', null, 'connect'],
            'open host not listening' => ['127.0.01', null, 'open'],
            'pconnect host not listening' => ['127.0.01', null, 'pconnect'],
            'popen host not listening' => ['127.0.01', null, 'popen'],
            // Wrong port
            'connect wrong port' => [$this->host, 1111, 'connect'],
            'open wrong port' => [$this->host, 1111, 'open'],
            'pconnect wrong port' => [$this->host, 1111, 'pconnect'],
            'popen wrong port' => [$this->host, 1111, 'popen'],
        ];
    }

    public function testClose()
    {
        $redis = new \Redis();
        $redis->connect($this->host);
        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.close",
                'phpredis',
                'redis',
                "Redis.close"
            ),
        ]);
    }

    /**
     * This function tests ALL the methods that are also tested in the phpredis own test suite. For this reason here
     * we are note testing results (i.e. the fact that method still works).
     * We are only testing the fact that the span is generated.
     *
     * @dataProvider dataProviderTestMethodsSpansOnly
     */
    public function testMethodsSpansOnly($method, $args, $rawCommand)
    {
        $this->redis->set('k1', 'v1');
        $traces = $this->isolateTracer(function () use ($method, $args) {
            if (count($args) === 0) {
                $this->redis->$method();
            } elseif (count($args) === 1) {
                $this->redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $this->redis->$method($args[0], $args[1]);
            } else {
                throw new \Exception('number of args not supported');
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'redis',
                "Redis.$method"
            )->withExactTags(['redis.raw_command' => "$method $rawCommand"]),
        ]);
    }

    public function dataProviderTestMethodsSpansOnly()
    {
        return [
            ['del', ['k1'], 'k1'],
            ['del', [['k1', 'k2']], 'k1 k2'],
            ['delete', ['k1'], 'k1'],
            ['delete', [['k1', 'k2']], 'k1 k2'],
            ['exists', ['k1'], 'k1'],
            ['setTimeout', ['k1', 2], 'k1 2'],
            ['expire', ['k1', 2], 'k1 2'],
            ['pexpire', ['k1', 2], 'k1 2'],
            ['expireAt', ['k1', 2], 'k1 2'],
            ['keys', ['*'], '*'],
            ['getKeys', ['*'], '*'],
            ['scan', [null], '0'], // the argument is the LONG (reference), initialized to NULL
            ['object', ['encoding', 'k1'], 'encoding k1'],
            ['persist', ['k1'], 'k1'],
            ['randomKey', [], ''],
            ['rename', ['k1', 'k3'], 'k1 k3'],
            ['renameKey', ['k1', 'k3'], 'k1 k3'],
            ['renameNx', ['k1', 'k3'], 'k1 k3'],
            ['type', ['k1'], 'k1'],
            ['sort', ['k1'], 'k1'],
            ['sort', ['k1', ['sort' => 'desc']], 'k1 sort desc'],
            ['ttl', ['k1'], 'k1'],
            ['pttl', ['k1'], 'k1'],
        ];
    }

    /**
     * @dataProvider dataProviderTestMethodsSimpleSpan
     */
    public function testMethodsOnlySpan($method, $arg)
    {
        $redis = $this->redis;
        $traces = $this->isolateTracer(function () use ($redis, $method, $arg) {
            null === $arg ? $redis->$method() : $redis->$method($arg);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'redis',
                "Redis.$method"
            ),
        ]);
    }

    public function dataProviderTestMethodsSimpleSpan()
    {
        return [
            'auth' => ['auth', 'user'],
            'ping' => ['ping', null],
            'echo' => ['echo', 'hey'],
            'save' => ['save', null],
            'bgRewriteAOF' => ['bgRewriteAOF', null],
            'bgSave' => ['bgSave', null],
            'flushAll' => ['flushAll', null],
            'flushDb' => ['flushDb', null],
        ];
    }

    public function testSelect()
    {
        $redis = $this->redis;
        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->select(1);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.select",
                'phpredis',
                'redis',
                "Redis.select"
            )->withExactTags(['db.index' => '1']),
        ]);
    }

    /**
     * @dataProvider dataProviderTestStringCommandSet
     */
    public function testStringCommandsSet($method, $args, $expected, $rawCommand, $initial = null)
    {
        $redis = $this->redis;

        if (null !== $initial) {
            $redis->set($args[0], $initial);
        }

        $traces = $this->isolateTracer(function () use ($redis, $method, $args) {
            if (count($args) === 1) {
                $redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $redis->$method($args[0], $args[1], $args[2]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'redis',
                "Redis.$method"
            )->withExactTags(['redis.raw_command' => $rawCommand]),
        ]);

        $this->assertSame($expected, $redis->get($args[0]));
    }

    public function dataProviderTestStringCommandSet()
    {
        return [
            [
                'append', // method
                [ 'k1' , 'v1' ], // arguments
                'v1', // expected final value
                'append k1 v1', // raw command
            ],
            [
                'append', // method
                [ 'k1' , 'v1' ], // arguments
                'beforev1', // expected final value
                'append k1 v1', // raw command
                'before', // initial
            ],
            [
                'decr', // method
                [ 'k1' ], // arguments
                '-1', // expected final value
                'decr k1', // raw command
            ],
            [
                'decr', // method
                [ 'k1', '10' ], // arguments
                '-10', // expected final value
                'decr k1 10', // raw command
            ],
            [
                'decrBy', // method
                [ 'k1', '10' ], // arguments
                '-10', // expected final value
                'decrBy k1 10', // raw command
            ],
            [
                'incr', // method
                [ 'k1' ], // arguments
                '1', // expected final value
                'incr k1', // raw command
            ],
            [
                'incr', // method
                [ 'k1', '10' ], // arguments
                '10', // expected final value
                'incr k1 10', // raw command
            ],
            [
                'incrBy', // method
                [ 'k1', '10' ], // arguments
                '10', // expected final value
                'incrBy k1 10', // raw command
            ],
            [
                'incrByFloat', // method
                [ 'k1', '1.3' ], // arguments
                '1.3', // expected final value
                'incrByFloat k1 1.3', // raw command
            ],
            [
                'set', // method
                [ 'k1', 'a' ], // arguments
                'a', // expected final value
                'set k1 a', // raw command
            ],
            [
                'setBit', // method
                [ 'k1', 7, 1 ], // arguments
                '1', // expected final value
                'setBit k1 7 1', // raw command
                '0', // initial "0010 1010"
            ],
            [
                'setEx', // method
                [ 'k1', 1000, 'b' ], // arguments
                'b', // expected final value
                'setEx k1 1000 b', // raw command
                'a', // initial "0010 1010"
            ],
            [
                'pSetEx', // method
                [ 'k1', 1000, 'b' ], // arguments
                'b', // expected final value
                'pSetEx k1 1000 b', // raw command
                'a', // initial "0010 1010"
            ],
            [
                'setNx', // method
                [ 'k1', 'b' ], // arguments
                'a', // expected final value
                'setNx k1 b', // raw command
                'a', // initial "0010 1010"
            ],
            [
                'setRange', // method
                [ 'k1', 6, 'redis' ], // arguments
                'Hello redis', // expected final value
                'setRange k1 6 redis', // raw command
                'Hello world', // initial "0010 1010"
            ],
            [
                'expire', // method
                [ 'k1', 6 ], // arguments
                'value', // expected final value
                'expire k1 6', // raw command
                'value', // initial "0010 1010"
            ],
            [
                'setTimeout', // method
                [ 'k1', 6 ], // arguments
                'value', // expected final value
                'setTimeout k1 6', // raw command
                'value', // initial "0010 1010"
            ],
            [
                'pexpire', // method
                [ 'k1', 6 ], // arguments
                'value', // expected final value
                'pexpire k1 6', // raw command
                'value', // initial "0010 1010"
            ],
        ];
    }

    public function testMSet()
    {
        $redis = $this->redis;

        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->mSet([ 'k1' => 'v1', 'k2' => 'v2' ]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.mSet",
                'phpredis',
                'redis',
                "Redis.mSet"
            )->withExactTags(['redis.raw_command' => 'mSet k1 v1 k2 v2']),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v2', $redis->get('k2'));
    }

    public function testMSetNx()
    {
        $redis = $this->redis;

        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->mSetNx([ 'k1' => 'v1', 'k2' => 'v2' ]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.mSetNx",
                'phpredis',
                'redis',
                "Redis.mSetNx"
            )->withExactTags(['redis.raw_command' => 'mSetNx k1 v1 k2 v2']),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v2', $redis->get('k2'));
    }

    public function testRawCommand()
    {
        $redis = $this->redis;

        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->rawCommand('set', 'k1', 'v1');
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.rawCommand",
                'phpredis',
                'redis',
                "Redis.rawCommand"
            )->withExactTags(['redis.raw_command' => 'rawCommand set k1 v1']),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
    }

    /**
     * @dataProvider dataProviderTestCommandsWithResult
     */
    public function testCommandsWithResult($method, $args, $expected, $rawCommand, $initial = null)
    {
        $redis = $this->redis;

        if (null !== $initial) {
            \is_array($initial) ? $redis->mSet($initial) : $redis->set($args[0], $initial);
        }

        $result = null;

        $traces = $this->isolateTracer(function () use ($redis, $method, $args, &$result) {
            if (count($args) === 1) {
                $result = $redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $result = $redis->$method($args[0], $args[1], $args[2]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'redis',
                "Redis.$method"
            )->withExactTags(['redis.raw_command' => $rawCommand]),
        ]);

        $this->assertSame($expected, $result);
    }

    public function dataProviderTestCommandsWithResult()
    {
        return [
            [
                'get', // method
                [ 'k1' ], // arguments
                false, // expected final value
                'get k1', // raw command
            ],
            [
                'get', // method
                [ 'k1' ], // arguments
                'v1', // expected final value
                'get k1', // raw command
                'v1', // initial
            ],
            [
                'getBit', // method
                [ 'k1', 1 ], // arguments
                1, // expected final value
                'getBit k1 1', // raw command
                '\x7f', // initial
            ],
            [
                'getRange', // method
                [ 'k1', 1, 3], // arguments
                'bcd', // expected final value
                'getRange k1 1 3', // raw command
                'abcdef', // initial
            ],
            [
                'getSet', // method
                [ 'k1', 'v1'], // arguments
                'old', // expected final value
                'getSet k1 v1', // raw command
                'old', // initial
            ],
            [
                'mGet', // method
                [ ['k1', 'k2'] ], // arguments
                [ 'v1', 'v2' ], // expected final value
                'mGet k1 k2', // raw command
                [ 'k1' => 'v1', 'k2' => 'v2'], // initial
            ],
            [
                'getMultiple', // method
                [ ['k1', 'k2'] ], // arguments
                [ 'v1', 'v2' ], // expected final value
                'getMultiple k1 k2', // raw command
                [ 'k1' => 'v1', 'k2' => 'v2'], // initial
            ],
            [
                'strLen', // method
                [ 'k1'], // arguments
                3, // expected final value
                'strLen k1', // raw command
                'old', // initial
            ],
            [
                'del', // method
                [ 'k1'], // arguments
                1, // expected final value
                'del k1', // raw command
                'v1', // initial
            ],
            [
                'delete', // method
                [ 'k1'], // arguments
                1, // expected final value
                'delete k1', // raw command
                'v1', // initial
            ],
            [
                'exists', // method
                [ 'k1'], // arguments
                true, // expected final value
                'exists k1', // raw command
                'v1', // initial
            ],
        ];
    }

    public function testDumpRestore()
    {
        $redis = $this->redis;
        $redis->set('k1', 'v1');

        $traces = $this->isolateTracer(function () use ($redis) {
            $dump = $redis->dump('k1');
            $redis->restore('k2', 0, $dump);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.dump",
                'phpredis',
                'redis',
                "Redis.dump"
            )->withExactTags(['redis.raw_command' => 'dump k1']),
            SpanAssertion::build(
                "Redis.restore",
                'phpredis',
                'redis',
                "Redis.restore"
            ),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v1', $redis->get('k2'));
    }

    public function testSetGetWithBinarySafeStringsAsValue()
    {
        $traces = $this->isolateTracer(function () {
            $this->redis->set('k1', $this->getBinarySafeString());
            $this->assertSame($this->getBinarySafeString(), $this->redis->get('k1'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.set",
                'phpredis',
                'redis',
                "Redis.set"
            )->withExistingTagsNames(['redis.raw_command']),
            SpanAssertion::build(
                "Redis.get",
                'phpredis',
                'redis',
                "Redis.get"
            )->withExistingTagsNames(['redis.raw_command']),
        ]);
    }


    public function testSetGetWithBinarySafeStringsAsKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->redis->set($this->getBinarySafeString(), 'v1');
            $this->assertSame('v1', $this->redis->get($this->getBinarySafeString()));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.set",
                'phpredis',
                'redis',
                "Redis.set"
            )->withExistingTagsNames(['redis.raw_command']),
            SpanAssertion::build(
                "Redis.get",
                'phpredis',
                'redis',
                "Redis.get"
            )->withExistingTagsNames(['redis.raw_command']),
        ]);
    }

    /**
     * @dataProvider dataProviderTestNormalizeArgs
     */
    public function testNormalizeArgs($args, $expected)
    {
        $actual = PHPRedisSandboxedIntegration::normalizeArgs($args);
        $this->assertSame($expected, $actual);
    }

    public function dataProviderTestNormalizeArgs()
    {
        return [
            'no args' => [ [], '' ],
            'one args' => [ ['k1'], 'k1' ],
            'two args' => [ ['k1', 'v1'], 'k1 v1' ],
            'int args' => [ ['k1', 1, 'v1'], 'k1 1 v1' ],
            'indexed array args' => [ [ ['k1', 'k2'] ], 'k1 k2' ],
            'associative array args' => [ [ [ 'k1' => 'v1', 'k2' => 'v2' ] ], 'k1 v1 k2 v2' ],
            'associative numeric array args' => [ [ [ '123' => 'v1', '456' => 'v2' ] ], '123 v1 456 v2' ],
            'mixed array and scalar args' => [ [ ['k1', 'k2'], 1, ['v1', 'v2']], 'k1 k2 1 v1 v2' ],
        ];
    }

    public function testBinarySafeStringsCanBeNormalized()
    {
        // Based on redis docs, key and values can be 'binary-safe' strings, so we need to make sure they are
        // correctly converted to a placeholder.
        $this->assertSame(106, strlen(PHPRedisSandboxedIntegration::normalizeArgs([$this->getBinarySafeString(), 'v1'])));
    }

    public function getBinarySafeString()
    {
        // Binary-safe string made of random bytes.
        $bytes = [
            '01011001', '11010001', '00001111', '11101001', '10010100', '01010110', '10111110', '00111011',
            '10001101', '11100010', '00110110', '10101100', '11000100', '01010100', '10100010', '11110001',
            '01100101', '01000001', '01100010', '01001000', '01000110', '01011001', '11001000', '10111010',
            '01100000', '11010111', '00001111', '00011011', '10011011', '11010011', '01011110', '01110100',
            '10000111', '11001001', '10000111', '11011111', '11010000', '00111000', '10001011', '11110000',
            '01111111', '00110010', '00010010', '11101110', '00011101', '11101001', '11000111', '10111101',
            '11000001', '00110011', '01101001', '11010010', '01011011', '01110011', '11000111', '00101101',
            '01010001', '11010110', '00000001', '11101111', '00100001', '00111110', '11100110', '10111011',
            '11010010', '00100101', '10101001', '01100101', '01101011', '10000101', '11111000', '00000101',
            '01000101', '01101011', '11100101', '01100001', '10010001', '01011011', '01110100', '11101010',
            '01001111', '01011011', '00010001', '00110100', '11001000', '00001101', '01000010', '00101011',
            '11010010', '00001100', '10111001', '10100001', '01011001', '10100100', '10100000', '10001110',
            '11010111', '10011011', '00111001', '01100010', '11001000', '00001101', '01000010', '00101011',
        ];
        $binarySafeString = null;
        foreach ($bytes as $binary) {
            $binarySafeString .= pack('H*', dechex(bindec($binary)));
        }
        return $binarySafeString;
    }
}
